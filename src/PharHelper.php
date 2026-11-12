<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * @internal
 */
final class PharHelper
{
    public static function isPhar(): bool
    {
        $pharPath = \Phar::running(false);

        return $pharPath !== '';
    }

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

    public static function resolveRuntimePath(string $path, string $projectDir): string
    {
        $projectDir = rtrim($projectDir, '/');
        $runtimeDir = self::getRuntimeDir($projectDir);

        if ($runtimeDir !== $projectDir && ($path === $projectDir || str_starts_with($path, $projectDir . '/'))) {
            return $runtimeDir . substr($path, strlen($projectDir));
        }

        return $path;
    }
}
