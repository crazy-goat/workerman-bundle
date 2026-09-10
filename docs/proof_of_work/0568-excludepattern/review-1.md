# Code review — round 1 — issue #568

**Branch:** perf/issue-568-excludepattern-matches-performs-4-ini-se
**Files reviewed:** src/Phar/PcreLimitGuard.php, src/Phar/ExcludePattern.php,
src/Phar/PharBuilder.php, tests/Phar/PcreLimitGuardTest.php,
tests/Phar/PharBuilderTest.php, tests/Phar/ExcludePatternTest.php, CHANGELOG.md

## 1. Knowledge base — tag index scan

Tags matching the diff files: `phpstan` (FAQ-029), `php82` (FAQ-037),
`tests` (many), `coverage` (FAQ-011/DEC-007), `lint` (FAQ-031/DEC-008),
`performance` (DEC-013, DEC-018, DEC-019), `security` (DEC-006, DEC-013,
DEC-016, DEC-017), `process` (DEC-009, DEC-011).

Entries read and assessed:

- **FAQ-029** (by-ref out-params, PHPStan `parameterByRef.unusedType`):
  No by-ref params in the diff. Not applicable.
- **FAQ-037** (new PHP syntax vs minimum supported version): Checked all
  touched files for 8.3+ constructs (anonymous readonly class, typed class
  constants, property hooks, `#[\Override]`). `PcreLimitGuard` is a
  plain `final class` with union types (`string|false|null`, available
  since 8.0). No 8.3+ syntax. **No violation.**
- **DEC-007** (80% coverage floor, single source of truth): No coverage
  floor lowered. Not applicable.
- **DEC-008** (`composer lint` canonical entry point): No new lint check
  added. Not applicable.
- **DEC-013** (optimization gates on security-relevant parsers must fail
  open): The ReDoS guard in `matches()` is a security-relevant parser
  guard. The change *moves* the limit establishment from per-call to
  per-pass but keeps the `@preg_match` + `false`-result safety net.
  However — see finding F-3 below for a fail-open concern when `matches()`
  is called outside a guard-protected pass.
- **DEC-006** (security hardening intact): The ReDoS structural check in
  the constructor is unchanged. No hardening loosened. **No violation.**
- **DEC-017** (no-logger warnings use `error_log()`, not
  `trigger_error`): No new warning paths in the diff. Not applicable.
- **DEC-009** (single writer for KB): I am proposing candidates, not
  writing to docs/helpers/. **Compliant.**

No documented decision violations found.

### Candidate KB entries (proposed, not written)

**Candidate 1** — "Per-process ini scope guards: idempotency, restore-on-throw, and the outside-the-pass trap"
- Tags: `phpstan`, `tests`, `performance`, `security`
- Trigger: "adding a scope guard that raises/restores a PHP ini value for a
  batch, or reviewing PcreLimitGuard / a similar enter/exit guard"
- Paragraph: A scope guard that sets ini values for a batch (`enter()` /
  `exit()`) must be idempotent on both sides (a second `enter()` without
  `exit()` must not overwrite the captured priors; `exit()` without
  `enter()` must be a no-op so a `finally` is safe even if `enter()`
  threw), must restore-on-throw via `try/finally`, and must capture the
  *prior* value (not the default) so a test that distorts before `enter()`
  proves restoration to the distorted value, not the PHP default. The
  non-obvious trap: code that calls the guarded operation *outside* the
  guard's scope (e.g. `ExcludePattern::matches()` called directly in a
  test, or `isExcluded()` which has its own bare `preg_match` calls)
  runs with whatever ini values the process currently has — the safety net
  (`false` → no match) still works if the default limits are bounded, but
  a caller that lowered the limits below the guard's ceiling before the
  call would get a false "no match" sooner. The guard only covers its own
  pass; document that direct callers are outside the guard's protection.

## 2. Prior findings (findings-review.md)

`findings-review.md` does not exist yet (round 1). No prior review findings
to revisit. The coder's own `findings-coder.md` recorded 4 items — these
are coder self-observations, not review findings, but I address them:

- Coder item 1 (`FailingUnlinkStreamWrapper` dynamic property
  deprecation): pre-existing, out of scope for #568. **Not a finding for
  this diff.** Worth a separate issue.
- Coder item 2 (`GLOB_BRACE` portability in `PharBuilderTest::tearDown`):
  pre-existing (line 23, not touched by this diff). **Not a finding for
  this diff** but a real portability bug — see F-6.
- Coder item 3 (constructor still does per-pattern ini_set): intentional,
  correctly documented. **Not a finding.**
- Coder item 4 (`isExcluded()` 4 `preg_match` per file): explicitly out
  of scope per issue. **Not a finding.** See F-3 for the guard-scope angle
  though.

## 3. New findings

### F-1: `testExitRestoresEvenWhenProtectedBlockThrows` does not distort
before enter — weaker than `testExitRestoresPriorValues`
**File:** tests/Phar/PcreLimitGuardTest.php:67-88
**Severity:** low

The test captures `$originalBacktrack`, enters the guard (which captures
the *current* = original value as prior), throws, catches, finally exits
(restoring to original), then asserts `$originalBacktrack`. This proves
`exit()` runs in the `finally`, but it does NOT prove restoration of a
*distorted* prior after a throw — if `exit()` had a bug where it restored
to PHP's default (1_000_000) instead of the captured prior, this test
would still pass whenever the original happened to equal the default.
`testExitRestoresPriorValues` (line 43) does distort but does not throw.
`testBuildRestoresPcreLimitsWhenPassThrows` (PharBuilderTest:378) distorts
AND throws — that integration test covers the gap, but the unit test
named "restore on throw" should also distort to be self-sufficient. The
coder's code-decision-1.md acknowledges the integration test is
belt-and-suspenders; the unit test should be the load-bearing one.

**Automated check:** a test review lint could flag "restore-on-throw test
that asserts against the pre-enter value without distorting it first" —
same class as FAQ-035 (accept + reject). Not worth a gate; fix the test.

### F-2: No test covers `PcreLimitGuard` restore when the exception
*propagates* (not caught)
**File:** tests/Phar/PcreLimitGuardTest.php:67-88
**Severity:** low

`testExitRestoresEvenWhenProtectedBlockThrows` catches the exception in a
`catch` block, so the `finally` runs and then the test continues. The
real production scenario in `PharBuilder::build()` has no `catch` — the
exception propagates *through* the `finally`. PHP's `finally` semantics
are the same either way (`finally` runs before the exception propagates),
so this is semantically equivalent, but a test that expects the exception
(`$this->expectException` or try/catch at the test level with the throw
inside the guard's try) would more faithfully model the production shape.
The integration test (`PharBuilderTest:378`) does model it correctly
(catch at the test level, throw inside `build()`). Low severity — the
contract is covered, the unit test's shape is just slightly divergent from
production.

### F-3: `matches()` called outside a guard-protected pass runs with
process-default limits — no test pins this, and the docblock doesn't warn
**File:** src/Phar/ExcludePattern.php:106-120
**Severity:** low (security-adjacent)

`matches()` no longer sets the limits itself. If called outside a
`PcreLimitGuard` pass (direct unit test, a future caller, or
`isExcluded()` which has its own separate `preg_match` calls), it runs
with whatever `pcre.backtrack_limit` / `pcre.recursion_limit` the
process currently has. The `false`-result safety net still prevents a
hang *if the limits are bounded*, but:

1. PHP's default `pcre.backtrack_limit` is 1_000_000 = `BACKTRACK_LIMIT`,
   so today this is safe by coincidence (the same coincidence the coder
   noted in findings-coder.md item re: the renamed test).
2. If a future PHP release raises the default above 1_000_000, or if a
   caller lowered the limit, the safety net's effectiveness changes.
3. The class docblock (line 25-30) says the limits "are now established
   once per filtering pass by `PcreLimitGuard`" but does not state that
   `matches()` called outside a pass relies on the process defaults
   matching the ceilings.

The `isExcluded()` static method (PharBuilder:183-202) runs 4 bare
`preg_match` calls with no limit guard at all — these are *also* outside
the guard's scope but run inside `shouldInclude()` which is called within
`buildFromIterator()`, so they ARE inside the guard's pass. That's fine.
The concern is only about *direct* callers of `matches()` outside
`build()`.

**Recommendation:** Add a one-line note to the `matches()` docblock:
"Relies on `PcreLimitGuard` having set the bounded limits for the
filtering pass; direct callers outside a pass use PHP's process defaults."
No code change needed — the safety net handles it. This is the
DEC-013-adjacent documentation gap.

### F-4: `PcreLimitGuard` has no `__destruct` — a forgotten `exit()`
leaks the lowered limit for the process lifetime
**File:** src/Phar/PcreLimitGuard.php:40-85
**Severity:** low

If a caller calls `enter()` and then discards the guard without calling
`exit()` (no `try/finally`), the lowered limits persist for the
remainder of the process. In `PharBuilder::build()` this cannot happen
(`exit()` is in a `finally`), but the guard is a standalone `@internal`
class that could be reused. A `__destruct` that calls `exit()` would be
defensive, but it would also mask bugs (a forgotten `exit()` silently
restoring instead of failing loud). The coder's design is explicit
enter/exit by design — this is a judgment call, not a defect. Noting it so
a future reader knows the trade-off was considered.

**No change recommended.** The unit test `tearDown` uses `ini_restore`
defensively, which is the right test-side guard.

### F-5: `PcreLimitGuard::exit()` method name shadows nothing but reads
as a control-flow verb
**File:** src/Phar/PcreLimitGuard.php:68
**Severity:** nit

`exit()` is a perfectly good method name but `exit` is also a PHP
language construct (`exit()` the function). PHPStan and PHP have no
problem distinguishing `$guard->exit()` from `exit()`, but a reader
skimming might double-take. `release()`, `restore()`, or `leave()`
would avoid the visual collision. This is purely stylistic — the code is
correct, PHPStan-clean, and the docblock + usage site make the intent
clear. No change required; noted for the record.

### F-6: `PharBuilderTest::tearDown` uses `GLOB_BRACE` (pre-existing,
not touched by this diff but in a file this diff modifies)
**File:** tests/Phar/PharBuilderTest.php:23
**Severity:** low (portability)

`glob($this->tempDir . '/**/*', GLOB_BRACE)` — `GLOB_BRACE` is a glibc
extension, undefined on musl libc (Alpine, distroless). On those builds the
constant is undefined → PHP warning + `glob` returns `false` →
`tearDown` silently skips file cleanup → temp dirs leak. The pattern has
no braces so the flag adds no value. The coder noted this in
findings-coder.md item 2 as out of scope, but since this diff adds a new
test method to this file (increasing the leak surface), it is fair to flag.
Fix: drop `GLOB_BRACE` or replace with the `RecursiveIteratorIterator`
already used in `removeDirectory()` below (which is portable and already
handles cleanup — the glob appears redundant).

**Automated check:** a PHPStan custom rule or CI leg on Alpine/musl would
catch this. Not in scope to add as a gate for this PR.

## 4. Automated checks run

| Check | Result |
|-------|--------|
| `php -l` on all 3 src files | No syntax errors |
| PHPStan level 8 (all 6 files) | **[OK]** No errors |
| php-cs-fixer dry-run (full project) | **[OK]** 0 of 258 files to fix |
| PHPUnit (`PcreLimitGuardTest|ExcludePatternTest|PharBuilderTest`) | **[OK]** 82 tests, 177 assertions |
| PHP 8.2 syntax sweep (FAQ-037) | No 8.3+ constructs found |

## 5. Summary

The implementation is clean, well-tested, and faithful to the issue's
acceptance criteria. PHPStan level 8, php-cs-fixer, and all 82 Phar tests
pass. The guard's idempotency, restore-on-throw, and single-source-of-truth
constant referencing are correctly implemented. The CHANGELOG entry is
well-formed and placed under `[Unreleased] → ### Changed`.

No high or medium findings. The 6 findings are all low/nit:

- F-1, F-2: test shape improvements (the contract IS covered, just not by
  the ideal unit test in isolation).
- F-3: documentation gap — `matches()` outside a guard pass relies on
  PHP defaults matching the ceilings; a docblock note would close it.
- F-4: no `__destruct` — by design, noted as a trade-off.
- F-5: `exit()` method name reads as a language construct — nit.
- F-6: pre-existing `GLOB_BRACE` portability bug in a file this diff
  touches.

**Verdict:** approve with minor improvements. None of the findings block
merge. F-1 and F-3 are the most worth addressing (test self-sufficiency
and documentation); F-6 is worth a follow-up issue.
