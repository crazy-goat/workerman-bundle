<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

/**
 * @internal
 */
final class StaticFilesRealPathCache
{
    /** @var array<string, array{path: string|false, time: int}> */
    private static array $cache = [];

    /**
     * @return array<string, array{path: string|false, time: int}>
     */
    public static function &all(): array
    {
        return self::$cache;
    }

    /**
     * @internal
     * @return array<string, array{path: string|false, time: int}>
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
