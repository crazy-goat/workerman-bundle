<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * A compiled exclude pattern for PHAR file filtering.
 *
 * Takes a raw user-supplied pattern string and compiles it to a regex.
 * The compilation rules are:
 *  - If the pattern starts and ends with the same non-alphanumeric character
 *    (e.g. /pattern/ or #pattern#), that character is treated as a regex
 *    delimiter and stripped.
 *  - If the resulting inner expression does not start with ^, it is prefixed.
 *  - The final expression is wrapped in #...# delimiters for preg_match.
 *
 * @internal
 */
final readonly class ExcludePattern
{
    private string $regex;

    public function __construct(string $raw)
    {
        $inner = $raw;

        if (strlen($raw) > 2 && $raw[0] === $raw[strlen($raw) - 1]) {
            $inner = substr($raw, 1, -1);
        }

        if (!str_starts_with($inner, '^')) {
            $inner = '^' . $inner;
        }

        $this->regex = '#' . $inner . '#';
    }

    public function matches(string $path): bool
    {
        return (bool) preg_match($this->regex, $path);
    }
}
