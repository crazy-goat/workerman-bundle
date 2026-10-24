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
     * Fail-closed read of a /proc pseudo-file.
     *
     * An unreadable path, a read failure and empty content all surface as
     * null, so callers keep their own fail-closed sentinel (0 / false /
     * null). The `@` suppresses the warning when the process exits between
     * `is_readable()` and the read.
     *
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
