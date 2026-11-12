<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final class Utils
{
    private function __construct()
    {
    }

    public static function cpuCount(string $cgroupRoot = '/sys/fs/cgroup'): int
    {
        if (self::isWindows()) {
            return 1;
        }

        if (!function_exists('shell_exec')) {
            return 1;
        }

        $isDarwin = \strtolower(\PHP_OS) === 'darwin';
        $command = $isDarwin ? 'sysctl -n hw.logicalcpu' : 'nproc';

        $result = shell_exec($command);

        if (!\is_string($result)) {
            return 1;
        }

        $count = (int) \trim($result);
        $count = $count > 0 ? $count : 1;

        if (!$isDarwin) {
            $limit = self::cgroupCpuLimit($cgroupRoot);
            if ($limit !== null) {
                $count = \min($count, $limit);
            }
        }

        return $count;
    }

    /**
     * @internal
     */
    public static function cgroupCpuLimit(string $cgroupRoot = '/sys/fs/cgroup'): ?int
    {
        $v2 = self::readCgroupFile($cgroupRoot . '/cpu.max');
        if ($v2 !== null) {
            $parts = \preg_split('/\s+/', $v2);
            if ($parts === false || !isset($parts[0], $parts[1])) {
                return null;
            }

            return self::quotaToCpus($parts[0], $parts[1]);
        }

        foreach (['cpu', 'cpu,cpuacct'] as $controller) {
            $quota = self::readCgroupFile("{$cgroupRoot}/{$controller}/cpu.cfs_quota_us");
            $period = self::readCgroupFile("{$cgroupRoot}/{$controller}/cpu.cfs_period_us");
            if ($quota !== null && $period !== null) {
                return self::quotaToCpus($quota, $period);
            }
        }

        return null;
    }

    private static function quotaToCpus(string $quota, string $period): ?int
    {
        if (!\ctype_digit($quota) || !\ctype_digit($period)) {
            return null;
        }

        $quotaValue = (int) $quota;
        $periodValue = (int) $period;
        if ($quotaValue <= 0 || $periodValue <= 0) {
            return null;
        }

        return \max(1, (int) \ceil($quotaValue / $periodValue));
    }

    private static function readCgroupFile(string $path): ?string
    {
        if (!\is_readable($path)) {
            return null;
        }

        $content = @\file_get_contents($path);

        return $content === false ? null : \trim($content);
    }

    public static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR !== '/';
    }

    public static function reload(bool $reloadAllWorkers = false): void
    {
        posix_kill($reloadAllWorkers ? posix_getppid() : posix_getpid(), SIGUSR1);
    }

    /**
     * @deprecated
     */
    public static function reboot(bool $rebootAllWorkers = false): void
    {
        trigger_deprecation('crazy-goat/workerman-bundle', '0.17.0', 'Utils::reboot() is deprecated since 0.17.0, use Utils::reload() instead. Will be removed in 1.0.');
        self::reload($rebootAllWorkers);
    }

    public static function clearOpcache(): void
    {
        if (function_exists('opcache_get_status') && $status = opcache_get_status()) {
            foreach (array_keys($status['scripts'] ?? []) as $file) {
                opcache_invalidate(strval($file), true);
            }
        }
    }
}
