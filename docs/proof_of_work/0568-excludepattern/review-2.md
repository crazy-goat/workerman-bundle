# Code review — round 2 — issue #568

**Branch:** perf/issue-568-excludepattern-matches-performs-4-ini-se
**Focus re-check:** tests/Phar/PcreLimitGuardTest.php distort-before-throw
fix (commits 01757c1 + 912e962), src/Phar/ExcludePattern.php matches()
docblock (commit 01757c1).

## 1. Knowledge base — tag index scan

Tags matching the diff files: `phpstan` (FAQ-029), `php82` (FAQ-037),
`tests` (FAQ-010/011/014/030/032/038), `coverage` (DEC-007), `lint`
(DEC-008), `performance` (DEC-013), `security` (DEC-006/013).

Entries read and assessed:

- **FAQ-029** (by-ref out-params, PHPStan `parameterByRef.unusedType`):
  No by-ref params in the diff. Not applicable.
- **FAQ-037** (new PHP syntax vs minimum supported version): Re-checked
  all touched files. `PcreLimitGuard` uses union types (`string|false|null`,
  8.0+). No 8.3+ constructs (no anonymous readonly, no typed class
  constants, no property hooks, no `#[\Override]`). **No violation.**
- **FAQ-030** (fork-helper readiness markers): No fork helpers in this
  diff. Not applicable.
- **FAQ-032** (workflow YAML pinning by multiple test classes): No
  `.github/workflows/` files in this diff. Not applicable.
- **DEC-007** (80% coverage floor): No coverage floor lowered. Not
  applicable.
- **DEC-008** (`composer lint` canonical entry point): No new lint check
  added. Not applicable.
- **DEC-013** (optimization gates on security-relevant parsers must fail
  open): The ReDoS guard in `matches()` retains `@preg_match` + `false`
  → no match. The `matches()` docblock now warns about outside-the-pass
  usage. **No violation** — F-3 from round 1 is resolved.
- **DEC-006** (security hardening intact): Structural ReDoS check in
  constructor unchanged. No hardening loosened. **No violation.**

No documented decision violations found.

### Candidate KB entries (proposed, not written)

No new candidates beyond round 1's Candidate 1 ("Per-process ini scope
guards"). That candidate remains valid; no new lesson emerged in round 2.

## 2. Prior findings (findings-review.md)

### F-1: testExitRestoresEvenWhenProtectedBlockThrows does not distort
before enter — weaker than testExitRestoresPriorValues

**Status: fixed.** Commit 01757c1 added distortion (lines 73-74:
`ini_set('pcre.backtrack_limit', '777')` / `'666'`) before `enter()`.
Commit 912e962 corrected the assertions (lines 91-92) to check against
the distorted values (`'777'` / `'666'`) instead of `$originalBacktrack`.
The test now proves restore-to-distorted-prior, not restore-to-default.
Verified by reading lines 67-95 of the current file.

### F-2: No test covers PcreLimitGuard restore when the exception
propagates (not caught)

**Status: still present (accepted).** The unit test
`testExitRestoresEvenWhenProtectedBlockThrows` still catches the
exception in a `catch` block (line 81) rather than letting it propagate
through the `finally`. The integration test
`PharBuilderTest::testBuildRestoresPcreLimitsWhenPassThrows` (line 386)
does model the production shape (catch at the test level, throw inside
`build()`). PHP `finally` semantics are identical regardless of whether
the exception is caught, so the contract is covered. Severity remains
low; the test shape diverges from production but is semantically
equivalent. No change required for merge.

### F-3: matches() called outside a guard-protected pass — docblock gap

**Status: fixed.** Commit 01757c1 added a docblock to `matches()`
(lines 107-110): `@see PcreLimitGuard Must be called inside a
PcreLimitGuard pass (e.g. via PharBuilder::build()) for the bounded
ReDoS ceilings to apply; outside a pass the process-default PCRE limits
are in effect.` The `ExcludePatternTest::testMatchesCompletesQuicklyUnderBacktrackLimit`
comment (lines 72-76) also documents why direct calls are safe today.
Verified by reading the current file.

### F-4: PcreLimitGuard has no __destruct — forgotten exit() leaks
lowered limit

**Status: still present (open, no change recommended).** No `__destruct`
was added. This is by design (explicit enter/exit, noted as a trade-off
in round 1). The test `tearDown` uses `ini_restore` defensively. No
change recommended.

### F-5: PcreLimitGuard::exit() method name visually collides with
exit() language construct

**Status: still present (open, nit).** The method is still named
`exit()`. PHPStan and PHP have no problem distinguishing `$guard->exit()`
from the language construct. Purely stylistic; no change required.

### F-6: PharBuilderTest::tearDown uses GLOB_BRACE (pre-existing, not
touched by this diff)

**Status: still present (open, pre-existing, out of scope).** Line 23
still uses `GLOB_BRACE`. Not touched by this diff. Worth a follow-up
issue.

## 3. New findings

### F-7: testExitRestoresEvenWhenProtectedBlockThrows restores only
pcre.backtrack_limit, not pcre.recursion_limit

**File:** tests/Phar/PcreLimitGuardTest.php:94-95
**Severity:** low

The test distorts both `pcre.backtrack_limit` (777, line 73) and
`pcre.recursion_limit` (666, line 74) before `enter()`. At the end it
restores `pcre.backtrack_limit` to `$originalBacktrack` (line 94) but
does NOT restore `pcre.recursion_limit` to its original value. Compare
with `testExitRestoresPriorValues` (lines 63-64) which restores both,
and `testBuildRestoresPcreLimitsWhenPassThrows` (lines 436-437) which
also restores both.

The test also captures only `$originalBacktrack` (line 69), not
`$originalRecursion` — so it could not restore recursion even if it
tried.

The `tearDown` method (lines 26-27) calls `ini_restore()` on both
limits, which catches this — so no test pollution leaks to subsequent
tests. But the asymmetry is a cleanup inconsistency: the test distorts
both, asserts both (lines 91-92), but restores only one. If `tearDown`
were ever removed or changed, `pcre.recursion_limit` would be left at
'666' for the next test.

**Fix:** capture `$originalRecursion` at line 70 and restore both at
lines 94-95:
```php
$originalRecursion = ini_get('pcre.recursion_limit');
self::assertIsString($originalRecursion);
// ...
ini_set('pcre.backtrack_limit', $originalBacktrack);
ini_set('pcre.recursion_limit', $originalRecursion);
```

**Automated check:** a static analysis rule that flags `ini_set()` calls
in test methods without matching restores for the same key would catch
this. Not worth a gate; fix the test.

### F-8: @see tag has inline prose on the same line — non-standard
PHPDoc

**File:** src/Phar/ExcludePattern.php:107
**Severity:** nit

The docblock uses `@see PcreLimitGuard Must be called inside a
PcreLimitGuard pass ...` — the `@see` tag and its prose description are
on the same line. Standard PHPDoc separates `@see` (which references a
symbol) from prose (which describes the method). PHPStan and
php-cs-fixer do not flag this, and the intent is clear. Suggested form:
```
/**
 * Must be called inside a PcreLimitGuard pass (e.g. via
 * PharBuilder::build()) for the bounded ReDoS ceilings to apply;
 * outside a pass the process-default PCRE limits are in effect.
 *
 * @see PcreLimitGuard
 */
```
No change required; noted for style consistency.

## 4. Automated checks run

| Check | Result |
|-------|--------|
| `php -l` on all touched files | No syntax errors |
| PHPStan level 8 (6 files) | **[OK]** No errors |
| php-cs-fixer dry-run (full project) | **[OK]** 0 of 258 files to fix |
| PHPUnit (`PcreLimitGuardTest|ExcludePatternTest|PharBuilderTest`) | **[OK]** 94 tests, 129 assertions, 26 skipped |
| PHP 8.2 syntax sweep (FAQ-037) | No 8.3+ constructs found |

## 5. Summary

Round 1's two actionable findings (F-1, F-3) are both fixed. The coder
added distortion before the throw in `testExitRestoresEvenWhenProtectedBlockThrows`
and corrected the assertions to check the distorted values, and added a
`@see` docblock to `matches()` warning about outside-the-pass usage.

F-2, F-4, F-5, F-6 remain open at their original severities (low/nit,
all accepted or out of scope).

Two new findings, both low/nit:
- F-7: `testExitRestoresEvenWhenProtectedBlockThrows` restores only
  `pcre.backtrack_limit` at the end, not `pcre.recursion_limit` —
  asymmetry with the other tests that distort both. The `tearDown`
  catches it, but the test should be self-consistent.
- F-8: `@see` tag has inline prose on the same line — non-standard
  PHPDoc, no tool flags it.

**Verdict:** approve. F-7 is worth a quick fix (add the missing
`recursion_limit` capture and restore) but does not block merge.
