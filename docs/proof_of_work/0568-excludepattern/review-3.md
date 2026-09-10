# Code review — round 3 (final) — issue #568

**Branch:** perf/issue-568-excludepattern-matches-performs-4-ini-se
**Diff base:** origin/master
**Focus:** final sweep — verify round 2 fixes (F-7, F-8), re-check all
prior findings, look for any new issues.

## 1. Knowledge base — tag index scan

Tags matching the diff files (`src/Phar/*`, `tests/Phar/*`):
`phpstan` (FAQ-029), `php82` (FAQ-037), `tests` (FAQ-010/011/014/030/032/038),
`coverage` (DEC-007), `lint` (DEC-008), `performance` (DEC-013),
`security` (DEC-006/013).

Entries read and assessed:

- **FAQ-029** (by-ref out-params, PHPStan `parameterByRef.unusedType`):
  No by-ref params in the diff. Not applicable.
- **FAQ-037** (new PHP syntax vs minimum supported version): Re-checked
  all touched files. No 8.3+ constructs (no anonymous readonly, no typed
  class constants, no property hooks, no `#[\Override]`). `PcreLimitGuard`
  uses union types (`string|false|null`, 8.0+). **No violation.**
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
  → no match. The `matches()` docblock (lines 106-111) warns about
  outside-the-pass usage. The constructor's structural check is unchanged.
  **No violation.**
- **DEC-006** (security hardening intact): Structural ReDoS check in
  constructor unchanged. No hardening loosened. **No violation.**

No documented decision violations found.

### Candidate KB entries (proposed, not written)

No new candidates beyond rounds 1–2's Candidate 1 ("Per-process ini scope
guards"). No new lesson emerged in round 3.

## 2. Prior findings (findings-review.md)

### F-1: testExitRestoresEvenWhenProtectedBlockThrows does not distort
before enter — weaker than testExitRestoresPriorValues

**Status: fixed (round 2, verified round 3).** Lines 69-76 of the current
`PcreLimitGuardTest.php` capture both `$originalBacktrack` and
`$originalRecursion`, distort both (777/666) before `enter()`, and lines
93-94 assert the distorted values after `exit()`. The test now proves
restore-to-distorted-prior, not restore-to-default. **Confirmed fixed.**

### F-2: restore-on-throw unit test catches the exception in a catch block;
production has no catch (exception propagates through finally)

**Status: still present (accepted).** Lines 81-88 still use a `catch`
block. Semantically equivalent — PHP `finally` runs regardless of whether
the exception is caught. The integration test
(`PharBuilderTest::testBuildRestoresPcreLimitsWhenPassThrows`, line 386)
models the production shape (throw inside `build()`, catch at test level).
No change required.

### F-3: matches() called outside a PcreLimitGuard pass — docblock gap

**Status: fixed (round 2, verified round 3).** Lines 106-111 of
`ExcludePattern.php` now have a proper docblock: prose on lines 107-109,
`@see PcreLimitGuard` on its own line 111. The `@see` inline-prose issue
(F-8) was also fixed. **Confirmed fixed.**

### F-4: PcreLimitGuard has no __destruct — forgotten exit() leaks lowered
limit

**Status: still present (open, no change recommended).** No `__destruct`
was added. By design — explicit enter/exit, noted as a trade-off. Test
`tearDown` uses `ini_restore` defensively. No change recommended.

### F-5: PcreLimitGuard::exit() method name visually collides with exit()
language construct

**Status: still present (open, nit).** Method still named `exit()`.
PHPStan and PHP distinguish `$guard->exit()` from the language construct.
Purely stylistic. No change required.

### F-6: PharBuilderTest::tearDown uses GLOB_BRACE (pre-existing, not
touched by this diff)

**Status: still present (open, pre-existing, out of scope).** Line 23
still uses `GLOB_BRACE`. Not touched by this diff. Worth a follow-up issue.

### F-7: testExitRestoresEvenWhenProtectedBlockThrows restores only
pcre.backtrack_limit, not pcre.recursion_limit

**Status: fixed (round 2, verified round 3).** Commit e318282 added
`$originalRecursion = ini_get('pcre.recursion_limit')` (line 70),
`self::assertIsString($originalRecursion)` (line 72), and
`ini_set('pcre.recursion_limit', $originalRecursion)` (line 97). The test
now captures and restores both limits, consistent with
`testExitRestoresPriorValues` and
`testBuildRestoresPcreLimitsWhenPassThrows`. **Confirmed fixed.**

### F-8: @see tag has inline prose on the same line — non-standard PHPDoc

**Status: fixed (round 2, verified round 3).** Commit e318282 restructured
the docblock (lines 106-111): prose is now on lines 107-109, `@see
PcreLimitGuard` is on its own line 111 with a blank line 110 separating
them. Standard PHPDoc form. **Confirmed fixed.**

## 3. New findings

No new findings. The diff is clean after rounds 1–2 fixes.

Re-checked areas:
- **PSR-4 / Symfony Bundle conventions:** `PcreLimitGuard` is in the
  correct namespace (`CrazyGoat\WorkermanBundle\Phar`), marked
  `@internal`, follows the existing `final class` pattern in the
  directory. No violations.
- **Type correctness (PHPStan level 8):** Ran
  `vendor/bin/phpstan analyse` on all 6 touched files — 0 errors.
  Union types (`string|false|null`) are correct for `ini_set()` return
  values.
- **Error handling and edge cases:** `enter()` idempotency (second call
  is no-op, does not overwrite captured priors). `exit()` idempotency
  (safe without prior `enter()`). Restore-on-throw via `finally` in
  `PharBuilder::build()`. All covered by tests.
- **Coding style (PSR-12, php-cs-fixer):** Ran
  `vendor/bin/php-cs-fixer fix --dry-run` on the full project — 0 of 258
  files to fix.
- **Test coverage:** 5 unit tests (`PcreLimitGuardTest`) + 1 integration
  test (`PharBuilderTest::testBuildRestoresPcreLimitsWhenPassThrows`) +
  updated existing test (`ExcludePatternTest`). All pass (94 tests, 130
  assertions, 26 skipped — skips are phar.readonly/root).
- **Security (Workerman child processes, HTTP input, process
  supervision):** Not applicable — this is PHAR build-time code, not
  runtime request handling. The ReDoS guard (DEC-013) is preserved.
- **Outdated documentation:** CHANGELOG entry is accurate and matches the
  implementation. Class docblocks are up to date.

## 4. Automated checks run

| Check | Result |
|-------|--------|
| `php -l` on all 6 touched files | No syntax errors |
| PHPStan level 8 (6 files) | **[OK]** No errors |
| php-cs-fixer dry-run (full project, 258 files) | **[OK]** 0 files to fix |
| PHPUnit (`PcreLimitGuardTest|ExcludePatternTest|PharBuilderTest`) | **[OK]** 94 tests, 130 assertions, 26 skipped |
| PHP 8.2 syntax sweep (FAQ-037) | No 8.3+ constructs found |
| `git log origin/master...HEAD --oneline` | 4 commits (b08fbd1 + 3 review-fix commits) |

## 5. Verdict

**Clean.** All actionable findings from rounds 1–2 (F-1, F-3, F-7, F-8)
are fixed and verified. The remaining open findings (F-2, F-4, F-5, F-6)
are all accepted/by-design/pre-existing-out-of-scope at low/nit severity
— none block merge.

No new findings in round 3.
