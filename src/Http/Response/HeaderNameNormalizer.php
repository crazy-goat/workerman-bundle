<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

/**
 * Normalizes header names to proper case (e.g., "content-type" → "Content-Type").
 *
 * Results are cached in a static lookup table so each unique header name is
 * normalised at most once while its entry remains cached — the hot path then
 * becomes O(1). The cache is bounded (HEADER_CACHE_MAX_SIZE) so an application
 * deriving response header names from request data cannot grow it without
 * limit in a long-lived worker process (issue #574), mirroring how Workerman
 * bounds its own header caches (Protocols/Http/Request.php).
 *
 * A corrections table handles irregular acronyms that ucfirst cannot produce:
 *   "etag"             → "ETag"
 *   "content-md5"      → "Content-MD5"
 *   "www-authenticate" → "WWW-Authenticate"
 *   "dnt"              → "DNT"
 *
 * Per RFC 9110, HTTP header names are case-insensitive, so the uncorrected
 * forms would still be valid; the corrections just match common usage.
 *
 * @internal
 */
final class HeaderNameNormalizer
{
    /**
     * Upper bound on the normalisation cache. Real applications draw header
     * names from a small fixed vocabulary, so the cap is never approached on
     * the happy path — it exists to keep the process-lifetime cache bounded.
     */
    public const HEADER_CACHE_MAX_SIZE = 512;

    /**
     * Header names longer than this are not real header names and do not
     * deserve a permanent cache slot. Mirrors Workerman's
     * MAX_CACHE_STRING_LENGTH approach.
     */
    public const HEADER_NAME_MAX_BYTES = 128;

    private const CORRECTIONS = [
        'etag' => 'ETag',
        'content-md5' => 'Content-MD5',
        'www-authenticate' => 'WWW-Authenticate',
        'dnt' => 'DNT',
    ];

    /** @var array<string, string> */
    private static array $cache = [];

    public static function normalize(string $name): string
    {
        $lower = strtolower($name);

        return self::$cache[$lower] ?? self::cacheMiss($lower, $name);
    }

    private static function cacheMiss(string $lower, string $name): string
    {
        $normalized = self::CORRECTIONS[$lower]
            ?? implode('-', array_map(ucfirst(...), explode('-', $name)));

        // Implausibly long header names are normalised every time instead of
        // occupying a permanent cache slot.
        if (strlen($lower) > self::HEADER_NAME_MAX_BYTES) {
            return $normalized;
        }

        // Enforce the cap on every insert: evict the oldest entry (first
        // insert wins in insertion-ordered PHP arrays). unset() on
        // array_key_first() is ~5x faster than array_shift() (#558).
        if (count(self::$cache) >= self::HEADER_CACHE_MAX_SIZE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }

        return self::$cache[$lower] = $normalized;
    }

    /**
     * @internal Test affordance only. Production code must not call this.
     *
     * @return array<string, string>
     */
    public static function cache(): array
    {
        return self::$cache;
    }

    /**
     * @internal Test affordance only. Production code must not call this.
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
