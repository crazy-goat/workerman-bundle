<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

/**
 * Process-lifetime realpath cache store for {@see StaticFilesMiddleware}.
 *
 * The cache must survive across middleware instances inside a worker process
 * while staying bounded (DEC-004, DEC-014). The storage cannot live on
 * StaticFilesMiddleware itself: that class is `final readonly` and PHP rejects
 * static properties in readonly classes — so the storage lives here, mirroring
 * the HeaderNameNormalizer pattern (DEC-014).
 *
 * @internal Production code reaches the entries only through the middleware.
 */
final class StaticFilesRealPathCache
{
    /** @var array<string, array{path: string|false, time: int}> */
    private static array $cache = [];

    /**
     * Mutable view for the middleware: returns the cache by reference so the
     * bounded insert/evict path (cacheStore) keeps working unchanged.
     *
     * @return array<string, array{path: string|false, time: int}>
     */
    public static function &all(): array
    {
        return self::$cache;
    }

    /**
     * @internal Test affordance only. Production code must not call this.
     *
     * @return array<string, array{path: string|false, time: int}>
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
