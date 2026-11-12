<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

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
     * @return list<int>
     */
    public static function all(): array
    {
        return [self::http(), self::second(), self::third()];
    }

    public static function cacheDir(string $projectDir): ?string
    {
        $ports = self::all();
        if ($ports === [self::DEFAULT_HTTP, self::DEFAULT_SECOND, self::DEFAULT_THIRD]) {
            return null;
        }

        return $projectDir . '/var/cache/ports-' . \implode('-', $ports) . '-' . self::hostHash();
    }

    public static function applyCacheDir(string $projectDir): void
    {
        $cacheDir = self::cacheDir($projectDir);
        if ($cacheDir !== null) {
            $_SERVER['APP_CACHE_DIR'] = $cacheDir;
        }
    }

    public static function host(): string
    {
        return \getenv('WMB_LISTEN_ADDR') ?: '127.0.0.1';
    }

    public static function connectHost(): string
    {
        $host = self::host();

        if ($host === '0.0.0.0') {
            return '127.0.0.1';
        }

        if ($host === '::') {
            $host = '::1';
        }

        return self::formatHost($host);
    }

    public static function listen(int $port): string
    {
        return 'http://' . self::formatHost(self::host()) . ':' . self::portOrFail($port);
    }

    public static function url(int $port, string $path = ''): string
    {
        return 'http://' . self::connectHost() . ':' . self::portOrFail($port) . $path;
    }

    private static function formatHost(string $host): string
    {
        if (\str_contains($host, ':') && !\str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }

        return $host;
    }

    private static function hostHash(): string
    {
        return \substr(\sha1(self::host()), 0, 8);
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
