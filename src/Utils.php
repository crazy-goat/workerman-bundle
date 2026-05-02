<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * @internal
 */
final class Utils
{
    private function __construct()
    {
    }

    public static function cpuCount(): int
    {
        // Windows does not support the number of processes setting.
        if (self::isWindows()) {
            return 1;
        }

        if (!function_exists('shell_exec')) {
            return 1;
        }

        $command = \strtolower(\PHP_OS) === 'darwin'
            ? 'sysctl -n machdep.cpu.core_count'
            : 'nproc';

        $result = shell_exec($command);

        // shell_exec returns null (no output) or false (command failed)
        if (!\is_string($result)) {
            return 1;
        }

        $count = (int) \trim($result);

        return $count > 0 ? $count : 1;
    }

    public static function isWindows(): bool
    {
        return \DIRECTORY_SEPARATOR !== '/';
    }

    public static function reboot(bool $rebootAllWorkers = false): void
    {
        posix_kill($rebootAllWorkers ? posix_getppid() : posix_getpid(), SIGUSR1);
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
