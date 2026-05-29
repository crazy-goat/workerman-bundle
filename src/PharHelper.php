<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * @internal
 */
final class PharHelper
{
    /**
     * Whether the current process is running from a PHAR archive.
     */
    public static function isPhar(): bool
    {
        $pharPath = \Phar::running(false);

        return $pharPath !== '';
    }

    /**
     * Returns the writable runtime directory.
     *
     * In PHAR mode, this is the directory containing the PHAR file
     * (or WORKERMAN_RUNTIME_DIR env var if set).
     *
     * In non-PHAR mode, this is the project directory.
     *
     * @param string $projectDir The project root directory (kernel.project_dir)
     */
    public static function getRuntimeDir(string $projectDir): string
    {
        if (isset($_SERVER['WORKERMAN_RUNTIME_DIR']) && $_SERVER['WORKERMAN_RUNTIME_DIR'] !== '') {
            return rtrim((string) $_SERVER['WORKERMAN_RUNTIME_DIR'], '/');
        }

        if (!self::isPhar()) {
            return rtrim($projectDir, '/');
        }

        return \dirname(\Phar::running(false));
    }

    /**
     * Resolve a path relative to project_dir, replacing the project_dir
     * prefix with runtime_dir when in PHAR mode.
     *
     * Consolidates logic previously duplicated across Runner, KernelFactory,
     * and ServerManager.
     */
    public static function resolveRuntimePath(string $path, string $projectDir): string
    {
        $projectDir = rtrim($projectDir, '/');
        $runtimeDir = self::getRuntimeDir($projectDir);

        if ($runtimeDir !== $projectDir && str_starts_with($path, $projectDir)) {
            return $runtimeDir . substr($path, strlen($projectDir));
        }

        return $path;
    }
}
