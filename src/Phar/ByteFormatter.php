<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * @internal
 */
final class ByteFormatter
{
    public static function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 2) . ' ' . $units[$i];
    }
}
