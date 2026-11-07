<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

/**
 * Single source of truth for the ports the test daemon binds and every test
 * client connects to.
 *
 * The defaults keep the historical ports. Setting WMB_PORT_HTTP,
 * WMB_PORT_SECOND and WMB_PORT_THIRD lets parallel host worktrees run the
 * suite side by side; an unset or empty variable means the default.
 *
 * Values are resolved on every call (no static cache), so tests can change
 * the environment with putenv() and see the effect immediately.
 */
final class TestPorts
{
    public const ENV_HTTP = 'WMB_PORT_HTTP';
    public const ENV_SECOND = 'WMB_PORT_SECOND';
    public const ENV_THIRD = 'WMB_PORT_THIRD';

    public const DEFAULT_HTTP = 8888;
    public const DEFAULT_SECOND = 9999;
    public const DEFAULT_THIRD = 9991;

    private function __construct()
    {
    }

    public static function http(): int
    {
        return self::resolve(self::ENV_HTTP, self::DEFAULT_HTTP);
    }

    public static function second(): int
    {
        return self::resolve(self::ENV_SECOND, self::DEFAULT_SECOND);
    }

    public static function third(): int
    {
        return self::resolve(self::ENV_THIRD, self::DEFAULT_THIRD);
    }

    /**
     * @return list<int> the three test ports in server order
     */
    public static function all(): array
    {
        return [self::http(), self::second(), self::third()];
    }

    /**
     * Base cache directory for a non-default port set, or null for the
     * defaults (which keep the historical `var/cache/<env>` layout).
     *
     * Symfony caches the resolved listen addresses, so a run with WMB_PORT_*
     * overrides must not reuse the container written for another port set.
     */
    public static function cacheDir(string $projectDir): ?string
    {
        $ports = self::all();
        if ($ports === [self::DEFAULT_HTTP, self::DEFAULT_SECOND, self::DEFAULT_THIRD]) {
            return null;
        }

        return $projectDir . '/var/cache/ports-' . \implode('-', $ports);
    }

    /**
     * Address the daemon binds to, as set by WMB_LISTEN_ADDR.
     */
    public static function host(): string
    {
        return \getenv('WMB_LISTEN_ADDR') ?: '127.0.0.1';
    }

    /**
     * Address a client uses to reach the daemon. A wildcard bind address
     * (0.0.0.0 or ::) is not routable, so clients use loopback instead.
     */
    public static function connectHost(): string
    {
        $host = self::host();

        return \in_array($host, ['0.0.0.0', '::'], true) ? '127.0.0.1' : $host;
    }

    /**
     * Workerman listen address for one of the test ports.
     */
    public static function listen(int $port): string
    {
        return 'http://' . self::formatHost(self::host()) . ':' . self::portOrFail($port);
    }

    /**
     * HTTP URL a test client can call on one of the test ports.
     */
    public static function url(int $port, string $path = ''): string
    {
        return 'http://' . self::formatHost(self::connectHost()) . ':' . self::portOrFail($port) . $path;
    }

    private static function formatHost(string $host): string
    {
        if (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }

        return $host;
    }

    private static function resolve(string $name, int $default): int
    {
        $value = \getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return self::parse($name, $value);
    }

    private static function parse(string $name, string $value): int
    {
        if (\preg_match('/^\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a TCP port number between 1 and 65535, got "%s".',
                $name,
                $value,
            ));
        }

        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(\sprintf(
                '%s must be a TCP port number between 1 and 65535, got "%s".',
                $name,
                $value,
            ));
        }

        return $port;
    }

    private static function portOrFail(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(\sprintf(
                'A TCP port must be between 1 and 65535, got %d.',
                $port,
            ));
        }

        return $port;
    }
}
