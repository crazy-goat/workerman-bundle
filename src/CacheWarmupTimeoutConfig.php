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

    /**
     * Resolve the effective timeout.
     *
     * An explicit {@see self::set()} value (written by
     * {@see WorkermanBundle::loadExtension()} during kernel boot) always wins.
     * Otherwise the environment is read lazily (`$_SERVER`, then `$_ENV`, then
     * `getenv()`) on every call — the same precedence as
     * {@see ConfigCacheGuardConfig::resolve()} — because
     * {@see Runtime::getRunner()} constructs the {@see Runner} before any
     * kernel boot on the Runner path, where no `set()` has run yet (issue
     * #759). Absent or empty resolves to {@see self::DEFAULT}; a present but
     * non-positive value throws, consistent with {@see self::set()}.
     */
    public static function resolve(): int
    {
        if (self::$timeout !== null) {
            return self::$timeout;
        }

        $raw = $_SERVER[self::ENV_VAR] ?? null;
        if ($raw === null || $raw === '') {
            $raw = $_ENV[self::ENV_VAR] ?? null;
        }
        if ($raw === null || $raw === '') {
            // function_exists(): getenv() may be disabled via disable_functions;
            // resolve() runs pre-boot on the Runner path, so it must not fatal
            // even in strict mode when the fallback is unavailable.
            $env = function_exists('getenv') ? getenv(self::ENV_VAR) : false;
            $raw = $env === false ? null : $env;
        }

        if ($raw === null || $raw === '') {
            return self::DEFAULT;
        }

        $timeout = (int) $raw;
        if ($timeout < 1) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a positive integer, got %d',
                self::ENV_VAR,
                $timeout,
            ));
        }

        return $timeout;
    }

    /**
     * @internal Test affordance only. Production code must not call this.
     */
    public static function reset(): void
    {
        self::$timeout = null;
    }
}
