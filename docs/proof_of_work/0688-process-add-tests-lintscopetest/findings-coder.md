# Findings — coder — issue #688

## Obstacles / surprises

1. **PHPStan `$argc`/`$argv` in bin/ scripts:** all three bin/ scripts used the
   global `$argc`/`$argv` directly, which PHPStan flags as "might not be defined"
   because it does not assume a CLI SAPI. The fix (`$_SERVER['argv'] ?? []`) is
   the standard PHPStan-compatible pattern but required touching every script.

2. **kb-lint.php `$entries[count-1]['body'][]` pattern:** the indirect append
   through a computed offset was both a PHPStan pain point and a code smell
   (repeated count arithmetic on every body line). The `$currentBody` reference
   variable refactor fixed both the type error and made the code more readable.

3. **xpath() return type:** `SimpleXMLElement::xpath()` can return `false` OR
   `null` OR an array. PHPStan's type is `array<SimpleXMLElement>|false|null`.
   The original guards checked `!== false && !== []` but missed the `null` case;
   switching to `is_array()` covers all three.

## Bugs / weak spots noticed (including out-of-scope)

1. **bin/check-coverage.php:36** — original code `$aggregate !== false && $aggregate !== []`
   did not guard against `null` from `xpath()`. On a malformed XML without a
   `<project>` element, `xpath()` can return `false`, but the `null` path was
   unguarded — `$aggregate[0]` on `null` would have been a runtime error.
   Fixed by switching to `is_array()`.

2. **bin/check-coverage.php:48** — same issue on the fallback path:
   `$fileMetrics === false || $fileMetrics === []` missed `null`.
   `foreach` over `null` would have been a runtime error. Fixed.

3. **bin/pick-issue.php:457 (was 462)** — `ghApi()` returns `mixed`; the
   `is_array()` guard narrows to `non-empty-array<mixed>`, but `sortMilestones()`
   expects `list<array<string, mixed>>`. Without `array_values()`, a non-list
   array (associative) could cause `usort` to behave unexpectedly. Fixed with
   `array_values()` normalization.

4. **bin/pick-issue.php:519** — the issue #688 task description mentions a live
   `Undefined array key 'number'` at `bin/pick-issue.php:517`. In the current
   code this is at line ~519: `'number' => isset($issue['number']) ? (int) $issue['number'] : null`.
   This is already guarded with `isset()` — the "live" error was likely from
   a different code state or a PHPStan level that flags the ternary pattern
   differently. No fix needed here; the guard is correct.

5. **tests/MarkdownLinkTest.php:20** — comment referenced `tests/fixtures` as an
   extant case collision. Updated to past tense since the collision is now
   resolved. This is a doc accuracy fix, not a code bug.

6. **phpstan.neon.dist ignoreErrors scope:** the existing `ignoreErrors` entry
   for `tests/` and `benchmarks/` uses `reportUnmatched: false`. Now that `bin/`
   is in scope, if any bin/ file ever triggers that same error pattern it would
   NOT be ignored (the path filter is `tests/` and `benchmarks/` only). This is
   correct behavior but worth noting: bin/ has no ignoreErrors safety net.
