<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * Utility methods for Workerman bundle runtime operations.
 *
 * This class is not intended to be instantiated (private constructor, final).
 * All methods are static helpers for process introspection and signalling.
 */
final class Utils
{
    private function __construct()
    {
    }

    /**
     * The number of CPUs this process may use.
     *
     * On Linux the container CPU limit (cgroup v2 or v1) is respected: the
     * result is never more than the limit, rounded up. On macOS the number of
     * logical CPUs is used.
     *
     * @param string $cgroupRoot Root of the cgroup file system. Tests pass a fake directory.
     */
    public static function cpuCount(string $cgroupRoot = '/sys/fs/cgroup'): int
    {
        // Windows does not support the number of processes setting.
        if (self::isWindows()) {
            return 1;
        }

        if (!function_exists('shell_exec')) {
            return 1;
        }

        $isDarwin = \strtolower(\PHP_OS) === 'darwin';
        $command = $isDarwin ? 'sysctl -n hw.logicalcpu' : 'nproc';

        $result = shell_exec($command);

        // shell_exec returns null (no output) or false (command failed)
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
     * The CPU limit of the container, rounded up, or null when there is no limit.
     *
     * Reads cgroup v2 (cpu.max) first, then cgroup v1 (cpu.cfs_quota_us and
     * cpu.cfs_period_us). Missing or broken files mean no limit.
     *
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
        // "max" (v2) and "-1" (v1) mean no limit.
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

    /**
     * Gracefully reload worker processes by sending SIGUSR1.
     *
     * Call this method from application code to trigger a hot reload of all
     * worker processes. This is useful after deploying new code or when a
     * runtime condition (e.g., memory pressure, config change) requires a
     * clean worker state.
     *
     * @api
     *
     * @param bool $reloadAllWorkers When true, SIGUSR1 is sent to the parent
     *                               process, which reloads all workers. When
     *                               false (default), only the current process
     *                               is signalled.
     */
    public static function reload(bool $reloadAllWorkers = false): void
    {
        posix_kill($reloadAllWorkers ? posix_getppid() : posix_getpid(), SIGUSR1);
    }

    /**
     * @deprecated since 0.17.0, use Utils::reload() instead. Will be removed in 1.0.
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
