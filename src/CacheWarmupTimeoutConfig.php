<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * Static configuration holder and validator for the cache warmup timeout.
 *
 * The bundle's extension loader runs during kernel boot and needs to propagate
 * the configured timeout to {@see Runner}, which is constructed later by
 * {@see Runtime::getRunner()} or {@see ServerManager::start()}/`restart()`
 * outside the DI container. This holder bridges the two phases without
 * resorting to superglobal mutation.
 */
final class CacheWarmupTimeoutConfig
{
    public const DEFAULT = 30;
    public const ENV_VAR = 'WORKERMAN_CACHE_WARMUP_TIMEOUT';

    private static ?int $timeout = null;

    public static function set(int $timeout): void
    {
        if ($timeout < 1) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a positive integer, got %d',
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

    public static function resolve(): int
    {
        return self::$timeout ?? self::DEFAULT;
    }

    /**
     * @internal Test affordance only. Production code must not call this.
     */
    public static function reset(): void
    {
        self::$timeout = null;
    }
}
