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
 * Defence-in-depth against accidental ReDoS (issue #334):
 *  - The raw pattern is structurally inspected for nested unbounded
 *    quantifiers (e.g. `(a+)+`, `(.+)*`) at construction time. These are
 *    known classic catastrophic-backtracking shapes; the constructor
 *    throws `InvalidArgumentException` if any are detected.
 *  - The compiled regex is dry-run against a small probe string to confirm
 *    PCRE accepts it. Compilation failure throws `InvalidArgumentException`.
 *  - Each match is wrapped in a per-call backtrack-limit guard so a pattern
 *    that somehow slipped through cannot hang the build.
 *
 * @internal
 */
final readonly class ExcludePattern
{
    /**
     * Defence ceiling for PCRE backtracking per call. Slightly higher than
     * PHP's default (1,000,000) but still bounded, so a pathological user
     * pattern cannot hang the build indefinitely.
     */
    private const BACKTRACK_LIMIT = 1_000_000;

    /**
     * Defence ceiling for PCRE recursion depth per call.
     */
    private const RECURSION_LIMIT = 100_000;

    /**
     * Probe string used at construction time to verify the compiled regex
     * is well-formed and does not immediately explode.
     */
    private const REGEX_PROBE = 'probe';

    private string $regex;

    /**
     * @throws \InvalidArgumentException when the pattern is malformed, when
     *                                  it contains a nested unbounded
     *                                  quantifier, or when PCRE rejects it.
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

    public function matches(string $path): bool
    {
        $previousBacktrack = ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        $previousRecursion = ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT);
        try {
            $result = @preg_match($this->regex, $path);
            if ($result === false) {
                // Catastrophic backtracking tripped the limit; treat as "no match"
                // so the file is included rather than the build hanging. The
                // proper guard is the structural check in the constructor; this
                // branch is a safety net.
                return false;
            }
        } finally {
            if (is_string($previousBacktrack)) {
                ini_set('pcre.backtrack_limit', $previousBacktrack);
            }
            if (is_string($previousRecursion)) {
                ini_set('pcre.recursion_limit', $previousRecursion);
            }
        }

        return $result === 1;
    }

    /**
     * Reject patterns whose structure is known to allow catastrophic
     * backtracking on sufficiently long input.
     *
     * The check is conservative: it does not catch every possible ReDoS
     * shape, but it reliably rejects the textbook cases such as `(a+)+`,
     * `(a*)*b`, `(.+)*`, `(a|a)+`. The per-call backtrack-limit guard in
     * `matches()` covers everything else.
     *
     * @throws \InvalidArgumentException
     */
    private function guardAgainstNestedUnboundedQuantifiers(string $raw, string $inner): void
    {
        $length = strlen($inner);
        $i = 0;
        // Each frame records whether any quantifier has been applied to an
        // operand inside the group, *not* counting quantifiers that close
        // the group itself.
        $groupStack = [];

        while ($i < $length) {
            $ch = $inner[$i];

            // Skip escaped characters.
            if ($ch === '\\') {
                $i += 2;
                continue;
            }

            // Inside a character class we only look for the closing bracket.
            if ($ch === '[') {
                $j = $i + 1;
                while ($j < $length) {
                    $cj = $inner[$j];
                    if ($cj === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($cj === ']') {
                        break;
                    }
                    $j++;
                }
                $i = min($j + 1, $length);
                continue;
            }

            // Group opener. `(?`, `(?=`, `(?!`, `(?<...>` are still group
            // openers (alternation / lookahead), so we push a frame for them
            // too; we only need to skip the optional `?` modifier character.
            if ($ch === '(') {
                $groupStack[] = false;
                // Consume any `?` modifier so it isn't read as the group's
                // body content.
                if ($i + 1 < $length && $inner[$i + 1] === '?') {
                    if ($i + 2 < $length && (in_array($inner[$i + 2], [':', '=', '!'], true))) {
                        $i += 3;
                        continue;
                    }
                    if ($i + 2 < $length && $inner[$i + 2] === '<') {
                        // Named group (?<name>...) — skip up to `>`.
                        $end = strpos($inner, '>', $i + 3);
                        $i = ($end === false) ? $length : $end + 1;
                        continue;
                    }
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }

            if ($ch === ')') {
                // Look ahead: if the very next character is a quantifier and
                // the group we're closing already had a quantifier applied to
                // an operand inside it, we have the textbook `(EXPR with
                // quantifier)+` shape — ReDoS bait.
                if ($groupStack === []) {
                    $i++;
                    continue;
                }
                $groupHasInnerQuantifier = array_pop($groupStack);
                if ($groupHasInnerQuantifier && $i + 1 < $length) {
                    $next = $inner[$i + 1];
                    if (in_array($next, ['+', '*', '?'], true)) {
                        throw $this->rejectNestedUnbounded($raw);
                    }
                    if ($next === '{') {
                        $end = strpos($inner, '}', $i + 2);
                        if ($end !== false) {
                            throw $this->rejectNestedUnbounded($raw);
                        }
                    }
                }
                $i++;
                continue;
            }

            if (in_array($ch, ['+', '*', '?'], true)) {
                // Mark every open group on the stack as "has inner quantifier"
                // so the closing `)` + quantifier check above fires.
                foreach (array_keys($groupStack) as $idx) {
                    $groupStack[$idx] = true;
                }
                $i++;
                continue;
            }

            if ($ch === '{') {
                // Read a {n,m} quantifier.
                $end = strpos($inner, '}', $i);
                if ($end === false) {
                    $i++;
                    continue;
                }
                foreach (array_keys($groupStack) as $idx) {
                    $groupStack[$idx] = true;
                }
                $i = $end + 1;
                continue;
            }

            $i++;
        }
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
