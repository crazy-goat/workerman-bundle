<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

final class LogFilePath
{
    private const STREAM_PATH_PATTERN = '~^(?:php://)|^/dev/(?:std(?:in|out|err)|tty|fd/\d+)$|^/proc/(?:self|\d+)/fd/\d+$~i';

    public static function isStreamPath(string $path): bool
    {
        return preg_match(self::STREAM_PATH_PATTERN, $path) === 1;
    }
}
