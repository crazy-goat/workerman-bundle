<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Exception\InvalidCacheWarmupTimeoutException;

final class CacheWarmupTimeoutConfig
{
    public const DEFAULT = 30;
    public const ENV_VAR = 'WORKERMAN_CACHE_WARMUP_TIMEOUT';

    private static ?int $timeout = null;

    public static function set(int $timeout): void
    {
        if ($timeout < 1) {
            throw new InvalidCacheWarmupTimeoutException(\sprintf(
                '%s must be a positive integer, got "%s"',
                self::ENV_VAR,
                $timeout,
            ));
        }

        self::$timeout = $timeout;
    }

    public static function get(): ?int
    {
        return self::$timeout;
    }

    /**
     * @internal
     */
    public static function readEnvRaw(): ?string
    {
        $raw = $_SERVER[self::ENV_VAR] ?? null;
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            $raw = $_ENV[self::ENV_VAR] ?? null;
        }
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            $env = function_exists('getenv') ? getenv(self::ENV_VAR) : false;
            $raw = $env === false ? null : $env;
        }

        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }

        return trim((string) $raw);
    }

    /**
     * @internal
     */
    public static function parseEnvRaw(string $trimmed): int
    {
        $timeout = filter_var($trimmed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($timeout === false) {
            throw new InvalidCacheWarmupTimeoutException(\sprintf(
                '%s must be a positive integer, got "%s"',
                self::ENV_VAR,
                $trimmed,
            ));
        }

        return $timeout;
    }

    public static function resolve(): int
    {
        if (self::$timeout !== null) {
            return self::$timeout;
        }

        $trimmed = self::readEnvRaw();
        if ($trimmed === null) {
            return self::DEFAULT;
        }

        return self::parseEnvRaw($trimmed);
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$timeout = null;
    }
}
