<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final class ConfigCacheGuardConfig
{
    public const ENV_VAR = 'WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE';

    private static ?bool $trustCacheDir = null;

    public static function set(bool $trustCacheDir): void
    {
        self::$trustCacheDir = $trustCacheDir;
    }

    public static function get(): ?bool
    {
        return self::$trustCacheDir;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$trustCacheDir = null;
    }

    /**
     * @internal
     */
    public static function resolve(): bool
    {
        if (self::$trustCacheDir !== null) {
            return self::$trustCacheDir;
        }

        $raw = $_SERVER[self::ENV_VAR] ?? '';
        if ($raw === '') {
            $raw = $_ENV[self::ENV_VAR] ?? '';
        }
        if ($raw === '') {
            $env = function_exists('getenv') ? getenv(self::ENV_VAR) : false;
            $raw = $env === false ? '' : $env;
        }

        if ($raw === '') {
            return false;
        }

        $normalized = strtolower(trim((string) $raw));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
