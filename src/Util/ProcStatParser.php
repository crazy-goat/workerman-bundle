<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * @internal
 */
final class ProcStatParser
{
    private function __construct()
    {
    }

    /**
     * @return list<string>|null
     */
    public static function splitFields(string $stat): ?array
    {
        $closeParen = \strrpos($stat, ')');
        if ($closeParen === false) {
            return null;
        }

        $afterParts = \preg_split('/\s+/', \trim(\substr($stat, $closeParen + 1)));
        if (!\is_array($afterParts)) {
            return null;
        }

        return $afterParts;
    }
}
