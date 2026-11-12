<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * @internal
 */
final readonly class ExcludePattern
{
    public const BACKTRACK_LIMIT = 1_000_000;

    public const RECURSION_LIMIT = 100_000;

    private const REGEX_PROBE = 'probe';

    private string $regex;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(string $raw)
    {
        $inner = $raw;

        if (strlen($raw) > 2 && $raw[0] === $raw[strlen($raw) - 1]) {
            $inner = substr($raw, 1, -1);
        }

        if (!str_starts_with($inner, '^')) {
            $inner = '^' . $inner;
        }

        $this->guardAgainstNestedUnboundedQuantifiers($raw, $inner);

        $regex = '#' . $inner . '#';

        $previousBacktrack = ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        $previousRecursion = ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT);
        try {
            $probe = @preg_match($regex, self::REGEX_PROBE);
            if ($probe === false) {
                throw new \InvalidArgumentException(sprintf(
                    'Exclude pattern "%s" is not a valid PCRE regex (error code %d).',
                    $raw,
                    preg_last_error(),
                ));
            }
        } finally {
            if (is_string($previousBacktrack)) {
                ini_set('pcre.backtrack_limit', $previousBacktrack);
            }
            if (is_string($previousRecursion)) {
                ini_set('pcre.recursion_limit', $previousRecursion);
            }
        }

        $this->regex = $regex;
    }

    /**
     * @see PcreLimitGuard
     */
    public function matches(string $path): bool
    {
        $result = @preg_match($this->regex, $path);
        if ($result === false) {
            return false;
        }

        return $result === 1;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function guardAgainstNestedUnboundedQuantifiers(string $raw, string $inner): void
    {
        $length = strlen($inner);
        $i = 0;
        $groupStack = [];

        while ($i < $length) {
            $i = match ($inner[$i]) {
                '\\' => $i + 2,
                '[' => $this->skipCharacterClass($inner, $i),
                '(' => $this->openGroup($inner, $i, $groupStack),
                ')' => $this->closeGroup($raw, $inner, $i, $groupStack),
                '+', '*', '?' => $this->consumeQuantifier($groupStack, $i + 1),
                '{' => $this->consumeBraceQuantifier($inner, $i, $groupStack),
                default => $i + 1,
            };
        }
    }

    private function skipCharacterClass(string $inner, int $open): int
    {
        $length = strlen($inner);
        $j = $open + 1;
        while ($j < $length && $inner[$j] !== ']') {
            $j += $inner[$j] === '\\' ? 2 : 1;
        }

        return min($j + 1, $length);
    }

    /**
     * @param list<bool> $groupStack
     * @param-out list<bool> $groupStack
     */
    private function openGroup(string $inner, int $open, array &$groupStack): int
    {
        $groupStack[] = false;
        if (($inner[$open + 1] ?? '') !== '?') {
            return $open + 1;
        }

        $marker = $inner[$open + 2] ?? '';
        if (in_array($marker, [':', '=', '!'], true)) {
            return $open + 3;
        }

        if ($marker === '<') {
            $end = strpos($inner, '>', $open + 3);

            return $end === false ? strlen($inner) : $end + 1;
        }

        return $open + 2;
    }

    /**
     * @param list<bool> $groupStack
     * @param-out list<bool> $groupStack
     * @throws \InvalidArgumentException
     */
    private function closeGroup(string $raw, string $inner, int $close, array &$groupStack): int
    {
        if ($groupStack === []) {
            return $close + 1;
        }

        $groupHasInnerQuantifier = array_pop($groupStack);
        if ($groupHasInnerQuantifier && $this->isQuantifierAt($inner, $close + 1)) {
            throw $this->rejectNestedUnbounded($raw);
        }

        return $close + 1;
    }

    private function isQuantifierAt(string $inner, int $position): bool
    {
        $next = $inner[$position] ?? '';
        if (in_array($next, ['+', '*', '?'], true)) {
            return true;
        }

        return $next === '{' && str_contains(substr($inner, $position + 1), '}');
    }

    /**
     * @param list<bool> $groupStack
     * @param-out list<bool> $groupStack
     */
    private function consumeQuantifier(array &$groupStack, int $next): int
    {
        $groupStack = array_map(static fn(): bool => true, $groupStack);

        return $next;
    }

    /**
     * @param list<bool> $groupStack
     * @param-out list<bool> $groupStack
     */
    private function consumeBraceQuantifier(string $inner, int $open, array &$groupStack): int
    {
        $end = strpos($inner, '}', $open);
        if ($end === false) {
            return $open + 1;
        }

        return $this->consumeQuantifier($groupStack, $end + 1);
    }

    private function rejectNestedUnbounded(string $raw): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf(
            'Exclude pattern "%s" contains a nested unbounded quantifier '
            . '(e.g. "(a+)+", "(.+)*", "(a|a)+"). Such patterns can trigger '
            . 'catastrophic PCRE backtracking and would hang the PHAR build. '
            . 'Please rewrite the pattern using atomic groups ("(?>...)") '
            . 'or possessive quantifiers supported by PCRE, or constrain it '
            . 'with explicit non-nested bounds.',
            $raw,
        ));
    }
}
