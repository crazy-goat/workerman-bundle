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

    /**
     * Gracefully reload worker processes by sending SIGUSR1.
     *
     * Call this method from application code to trigger a hot reload of all
     * worker processes. This is useful after deploying new code or when a
     * runtime condition (e.g., memory pressure, config change) requires a
     * clean worker state.
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
     * @deprecated since 0.17.0, use Utils::reload() instead. Will be removed in the next major release.
     */
    public static function reboot(bool $rebootAllWorkers = false): void
    {
        trigger_deprecation('crazy-goat/workerman-bundle', '0.17.0', 'Utils::reboot() is deprecated since 0.17.0, use Utils::reload() instead. Will be removed in the next major release.');
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
