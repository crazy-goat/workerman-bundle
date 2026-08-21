<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * Static configuration holder for the config-cache permission guard opt-out.
 *
 * The guard behind {@see ConfigLoader::checkCacheFilePermissions()} refuses
 * to {@see require} a cached configuration file whose ownership or whose
 * containing directory's permissions are unsafe (issue #648). Deployments
 * that explicitly trust the cache directory — managed build systems,
 * sudoless image builders, frozen base images — cannot change who warms
 * the cache, so this holder exposes a documented security downgrade: when
 * the opt-out resolves to true, the refusal branches degrade to the
 * advisory warning path and loading proceeds.
 *
 * The value is resolved lazily from the environment because
 * {@see Runner::createConfigLoader()} constructs the ConfigLoader and
 * validates the cache file in the main process *before* the kernel boots —
 * a bridge that only ran in {@see WorkermanBundle::loadExtension()} would
 * never reach that path. The static property is an explicit override and a
 * test affordance; {@see self::reset()} clears it.
 */
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
     * @internal Test affordance only. Production code must not call this.
     */
    public static function reset(): void
    {
        self::$trustCacheDir = null;
    }

    /**
     * Resolve whether the guard downgrade is active.
     *
     * The value is read from the environment (`$_SERVER`, then `$_ENV`, then
     * `getenv()`) on every call, so the holder works on paths that construct
     * the ConfigLoader before the kernel boots. Only an unambiguous truthy
     * value enables the downgrade: `1`, `true`, `on` or `yes` (case- and
     * whitespace-insensitive). Everything else — absent, empty, `0`, `false`,
     * `off`, `no`, or any unrecognised value such as a typo like `ture` —
     * keeps the strict guard (fail-closed: a mistyped opt-out must never
     * silently unlock a security guard).
     *
     * @internal `set()`/`reset()` are test affordances; production code must
     *           not use them.
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
            // function_exists(): getenv() may be disabled via disable_functions;
            // resolve() runs at boot on every path, so it must not fatal even
            // in strict mode when the fallback is unavailable.
            $env = function_exists('getenv') ? getenv(self::ENV_VAR) : false;
            $raw = $env === false ? '' : $env;
        }

        if ($raw === '') {
            return false;
        }

        $normalized = strtolower(trim((string) $raw));

        // Fail-closed allowlist: only the values below enable the downgrade;
        // anything else (typos, "enabled", "1.0", ...) keeps the strict guard.
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
