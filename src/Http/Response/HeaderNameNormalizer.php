<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

/**
 * @internal
 */
final class HeaderNameNormalizer
{
    public const HEADER_CACHE_MAX_SIZE = 512;

    public const HEADER_NAME_MAX_BYTES = 128;

    private const CORRECTIONS = [
        'etag' => 'ETag',
        'content-md5' => 'Content-MD5',
        'www-authenticate' => 'WWW-Authenticate',
        'dnt' => 'DNT',
    ];

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * @param-out string $lower
     */
    public static function normalize(string $name, ?string &$lower = null): string
    {
        $lower = strtolower($name);

        return self::$cache[$lower] ?? self::cacheMiss($lower, $name);
    }

    private static function cacheMiss(string $lower, string $name): string
    {
        $normalized = self::CORRECTIONS[$lower]
            ?? implode('-', array_map(ucfirst(...), explode('-', $name)));

        if (strlen($lower) > self::HEADER_NAME_MAX_BYTES) {
            return $normalized;
        }

        if (count(self::$cache) >= self::HEADER_CACHE_MAX_SIZE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }

        return self::$cache[$lower] = $normalized;
    }

    /**
     * @internal
     * @return array<string, string>
     */
    public static function cache(): array
    {
        return self::$cache;
    }

    /**
     * @internal
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
