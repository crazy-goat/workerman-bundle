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
     * Read the raw env var across the three channels.
     *
     * Shared bridge for {@see self::resolve()} and
     * {@see WorkermanBundle::loadExtension()} so precedence cannot drift
     * again (issue #759 review F-1). Precedence: `$_SERVER`, then `$_ENV`,
     * then `getenv()`. Trims before the emptiness check (whitespace-only is
     * absent) and treats non-scalar superglobal values as absent.
     *
     * @internal Shared with WorkermanBundle; not part of the public API.
     */
    public static function readEnvRaw(): ?string
    {
        $raw = $_SERVER[self::ENV_VAR] ?? null;
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            $raw = $_ENV[self::ENV_VAR] ?? null;
        }
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            // function_exists(): getenv() may be disabled via disable_functions;
            // resolve() runs pre-boot on the Runner path, so it must not fatal
            // even in strict mode when the fallback is unavailable.
            $env = function_exists('getenv') ? getenv(self::ENV_VAR) : false;
            $raw = $env === false ? null : $env;
        }

        // Whitespace-only is treated as absent (matches the
        // ConfigCacheGuardConfig sibling, which trims before its emptiness
        // check); non-scalar superglobal values (pathological — SAPIs deliver
        // strings) are treated as absent rather than cast.
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return null;
        }

        return trim((string) $raw);
    }

    /**
     * Resolve the effective timeout.
     *
     * An explicit {@see self::set()} value (written by
     * {@see WorkermanBundle::loadExtension()} during kernel boot) always wins.
     * Otherwise the environment is read lazily via {@see self::readEnvRaw()}
     * on every call — the same precedence as
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

        $trimmed = self::readEnvRaw();
        if ($trimmed === null) {
            return self::DEFAULT;
        }

        $timeout = (int) $trimmed;
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
