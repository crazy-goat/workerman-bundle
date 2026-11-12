<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * @internal
 */
final class ProcFileReader
{
    private function __construct()
    {
    }

    /**
     * @phpstan-impure
     */
    public static function read(string $path): ?string
    {
        if (!\is_readable($path)) {
            return null;
        }

        $content = @\file_get_contents($path);

        return \is_string($content) && $content !== '' ? $content : null;
    }
}
