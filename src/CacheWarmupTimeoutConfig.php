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
     * Strictly parse an already-trimmed env value into a positive timeout.
     *
     * `filter_var()` with `min_range=1` accepts only plain decimal integers
     * with an optional sign (`+45` passes as 45) and rejects floats
     * (`45.9`), unit suffixes (`60s`), hex (`0x2D`) and non-numeric input
     * (`abc`) — the same class of values a Symfony `intNode` rejects — so
     * nothing is silently truncated by an `(int)` cast. The raw value (not
     * the cast result) is quoted in the error message. Leading/trailing
     * whitespace is already trimmed by {@see self::readEnvRaw()}.
     *
     * @internal Shared with WorkermanBundle; not part of the public API.
     */
    public static function parseEnvRaw(string $trimmed): int
    {
        $timeout = filter_var($trimmed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($timeout === false) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a positive integer, got "%s"',
                self::ENV_VAR,
                $trimmed,
            ));
        }

        return $timeout;
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

        return self::parseEnvRaw($trimmed);
    }

    /**
     * @internal Test affordance only. Production code must not call this.
     */
    public static function reset(): void
    {
        self::$timeout = null;
    }
}
