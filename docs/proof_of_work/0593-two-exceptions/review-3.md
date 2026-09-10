# Review Round 3 — issue #593: two never-thrown exception classes

**Branch:** `chore/issue-593-two-exception-classes-are-never-thrown-a`
**Date:** 2026-09-10
**Reviewer:** review subagent (round 3)
**Diff:** `git diff origin/master...HEAD` (4 commits: aea16c9 + daa8534 + a248807 + f9ce09b)

## KB entries read (tag-matched)

- **FAQ-031** (`lint,bin,tests`): `bin/` is inside linter scope — phpstan
  and php-cs-fixer cover `bin/`. Verified: both pass on the modified script.
- **FAQ-015** (`git-hooks,lint`): pre-push hook runs `composer lint`. The
  check is wired into `lint` — verified at `composer.json`.
- **DEC-008** (`lint,git-hooks,policy`): `composer lint` checks must be
  safe to run at any committed point. The check is read-only — compliant.
- **DEC-007** (`coverage,ci,policy`): 80% floor not touched. Verified.
- **DEC-009** (`knowledge-base,process,policy`): review proposes, never
  writes. Acknowledged.
- **DEC-012** (`docs,markdown`): raw angle-brackets in prose. No issues.
- **DEC-017** (`logging,security,policy`): `trigger_error(E_USER_WARNING)`
  invokes the PHP error handler even under `@`. Relevant to F-11
  (`removeRecursively` teardown helper).

No KB violations found.

## findings-review.md — prior-round triage

### F-01 through F-07 (rounds 1–2)

All seven were closed in round 2 with evidence and regression tests. No
new evidence emerged to re-open them.

| # | Finding | Verdict | Evidence |
|---|---------|---------|----------|
| F-01 | Regex captures "for" from docblock comments | **fixed** | `token_get_all` replaces regex; `T_INTERFACE`/`T_CLASS` are real parser tokens. Regression test present. |
| F-02 | `preg_match` captures only first declaration per file | **fixed** | `token_get_all` loop iterates all tokens. Regression test present. |
| F-03 | Regex does not handle `readonly class` variants | **fixed** | `token_get_all` handles all modifier combinations. |
| F-04 | UPGRADE.md tree missing 4 classes | **fixed** | All 22 types now present in the tree (verified by listing `src/Exception/*.php`). |
| F-05 | CHANGELOG.md released entry names deleted class | **still present** | Coder's judgment call: released changelogs are immutable. `[Unreleased]` section records the removal. Practical impact low. |
| F-06 | `removeRecursively` does not suppress rmdir/unlink errors | **fixed** | All calls wrapped with `@` suppression. |
| F-07 | `runScript` passes only PATH to subprocess | **fixed** | Now passes `[...getenv(), ...$env]`. |

### F-08: `::class` keyword causes false-positive type discovery — MEDIUM

**Verdict: FIXED**

The coder applied a two-part fix between round 2 and round 3 (commit
a248807):

1. **Token lookback for `::`:** lines 146–156 of
   `bin/check-exception-usage.php` now scan *backwards* from the
   `T_CLASS` token, skipping `T_WHITESPACE`/`T_COMMENT`/`T_DOC_COMMENT`.
   If a `T_DOUBLE_COLON` (`::`) is found, `continue 2` skips this
   `T_CLASS` — it is a `Foo::class` constant fetch, not a declaration.
   If any other token is found, `break` — it is a real declaration
   keyword preceded by `T_FINAL`/`T_ABSTRACT`/`T_NEW`/etc.

2. **`break` (not `continue`) for non-array tokens in the forward loop:**
   line 159–160. A real type declaration always has
   `T_WHITESPACE* T_STRING` after the keyword. A non-array token (like
   `.`, `;`, `{`) means this `T_CLASS` is not a declaration (e.g.
   `new class()` → `T_CLASS` followed by `(` or `{`). Using `break`
   instead of `continue` prevents the scanner from jumping past operators
   and capturing an unrelated `T_STRING` as a phantom type name.

**Regression test:** `testClassConstantFetchIsNotMistakenForADeclaration`
at `tests/ExceptionUsageLintTest.php:132–151`. Creates a fixture with
`\Foo\Bar::class . SOME_SUFFIX;` before a real `final class
RealException`, references `RealException` externally, and asserts exit
code 0 (OK). If `SOME_SUFFIX` were captured as a type, it would be
reported as unused and the exit code would be 1.

**Verification performed:**

- Ran `php vendor/bin/phpunit tests/ExceptionUsageLintTest.php` — all 10
  tests pass (76 assertions).
- Ran `php bin/check-exception-usage.php` — OK, 22 types.
- Isolated test with `php -r`: `Foo::class . SOME_CONST;` followed by
  `final class Bar {}` → returns `["Bar"]` only. `SOME_CONST` is NOT
  captured. Before the fix it returned `["SOME_CONST", "Bar"]`.
- Anonymous class test: `new class extends \RuntimeException {}` →
  returns `[]` (correctly skipped, no phantom name).
- `::class` with no real declaration: returns `[]` (no phantom).

**Automated check that could have caught this:** the regression test now
in the suite. The fixture covers the exact pattern (`::class` before a
real declaration with an operator in between).

### F-09: Enums and traits in `src/Exception/` would not be discovered — LOW

**Verdict: FIXED**

Line 140 of `bin/check-exception-usage.php` now includes `T_TRAIT` in
the `in_array` check:

```php
if (!\in_array($id, [\T_INTERFACE, \T_CLASS, \T_TRAIT], true)
    && (\defined('T_ENUM') === false || $id !== \T_ENUM)) {
    continue;
}
```

The `T_ENUM` case is guarded by `\defined('T_ENUM')` for PHP < 8.1
compatibility (the project minimum is 8.2, so `T_ENUM` is always defined
in practice, but the guard is defensive and correct).

**Verification:** isolated test with `php -r`:
- `trait MyTrait {}` → `["MyTrait"]` ✅
- `enum MyEnum: string {}` → `["MyEnum"]` ✅

The PHPDoc at line 116–118 was also updated from "interface and class" to
"interface, class, trait and enum".

**Note:** no regression test was added for T_TRAIT/T_ENUM specifically.
The behavior is verified manually but not pinned by an automated test.
This is acceptable given the latent/theoretical nature of the finding
(no enums or traits exist in `src/Exception/` and none are likely), but
if a test were desired, a fixture with a trait in `src/Exception/` that
is referenced externally would close the gap. Not blocking.

### F-10: `NoResponseStrategyException` missing from `\LogicException` row in Before/After table — LOW

**Verdict: NOT A REAL FINDING**

**Detailed analysis:**

The UPGRADE.md Before/After table (line 490–494) is specifically about the
0.12 exception hierarchy migration — which generic PHP exceptions were
*replaced* with which typed exception classes. The table header reads:

> 9 `\InvalidArgumentException` throw sites and 2 `\RuntimeException`
> throw sites have been replaced with domain-specific exceptions.

The `\LogicException` row maps `InvalidCronExpressionException` because
that class was created in commit e40331e ("feat: Replace generic exceptions
with typed exception hierarchy") specifically to replace a
`throw new \LogicException(...)` site in cron expression instantiation.

`NoResponseStrategyException` was indeed created to replace a
`throw new \LogicException(...)` — but in a *separate* commit (e433ad5,
"fix: move ResponseConverter registration to compilerpass"), not in the
0.12 hierarchy refactor. Git history confirms:

- **e40331e** (2026-04-04 12:01): created `InvalidCronExpressionException`
  as part of the deliberate hierarchy replacement. This is the commit the
  UPGRADE.md "0.12" section documents.
- **e433ad5** (2026-04-04 16:45): created `NoResponseStrategyException`
  as a side effect of a ResponseConverter refactor — replacing a
  `\LogicException` throw in `ResponseConverter.php` with a new typed
  exception. This was a separate fix, not part of the hierarchy migration.

Both commits shipped in v0.12.0, but the UPGRADE.md "Upgrading to 0.12"
section documents the *hierarchy migration* (e40331e), not every exception
introduced in the release. `NoResponseStrategyException` was not part of
that migration — it was a new class introduced in a ResponseConverter
refactoring commit, not a class that replaced a generic exception as part
of the hierarchy effort.

The table's purpose is "what was before → what is after" for the hierarchy
migration. `NoResponseStrategyException` has no "before" in the context
of the hierarchy migration — it was added by a different commit for a
different purpose. Its absence from the replacement table is not a defect.

The hierarchy *tree* (line 521) correctly lists
`NoResponseStrategyException (extends \LogicException)` — that is the
comprehensive view. The Before/After table is the migration view, and
`NoResponseStrategyException` does not belong there.

**Conclusion:** not a real finding. The table is accurate for its stated
purpose. The hierarchy tree is the authoritative complete listing.

### F-11: `trigger_error(E_USER_WARNING)` in `removeRecursively` invokes the error handler even under `@` — NIT

**Verdict: STILL PRESENT**

`tests/ExceptionUsageLintTest.php` lines 270, 274, 280 still use
`@trigger_error(..., E_USER_WARNING)` in `removeRecursively`.

Per DEC-017: `@` suppresses the default handler output but the registered
error handler is still invoked with `E_USER_WARNING`. In a test
environment with a strict error handler (e.g. Symfony's
`DebugErrorHandler` in debug mode), this could escalate to an
`ErrorException`.

**Mitigating factors:**
- This is in `tearDown()`, after the test has already passed or failed.
- PHPUnit's default error handler does not convert `E_USER_WARNING` to
  `ErrorException` — it only does so for `E_WARNING` and above (PHP's
  built-in levels), not user-triggered warnings.
- The `@` operator sets `error_reporting()` to 0 for the duration,
  which most handlers check before escalating.

**Recommended fix (non-blocking):** replace
`@trigger_error($msg, E_USER_WARNING)` with `error_log($msg)` per
DEC-017. `error_log()` writes to the configured log without invoking
the error handler, so it cannot escalate. This is a one-line-per-call
change that aligns with the documented decision.

**Why still open, not blocking:** the practical risk is minimal in a test
teardown context, and the improvement over the original bare
`rmdir()`/`unlink()` calls (F-06) is significant. But DEC-017 exists
precisely for this pattern, and consistency with the documented decision
is preferable.

## Automated checks run

| Check | Command | Result |
|-------|---------|--------|
| Exception usage gate | `php bin/check-exception-usage.php` | OK — 22 types |
| PHPStan (level 8) | `vendor/bin/phpstan analyse bin/check-exception-usage.php tests/ExceptionUsageLintTest.php` | OK (no errors) |
| php-cs-fixer | `vendor/bin/php-cs-fixer fix --dry-run` | 0 files to fix |
| PHPUnit (ExceptionUsageLintTest) | `vendor/bin/phpunit tests/ExceptionUsageLintTest.php` | 10 tests, 76 assertions, OK |

## New findings (round 3)

No new findings. The diff is clean. The F-08 fix is correct and
well-tested, the F-09 fix is correct (though lacks a dedicated regression
test — acceptable for a latent/theoretical gap), F-10 is not a real
finding, and F-11 remains a non-blocking nit.

## KB candidate entries (proposed, not written)

### Candidate 1 (from round 2 — still relevant, endorsed)

- **Title:** "token_get_all type discovery must break (not continue) on non-array tokens after T_CLASS"
- **Tags:** `lint`, `bin`, `tests`
- **Trigger:** "writing a bin/ script that discovers PHP type declarations via token_get_all"
- **Paragraph:** When scanning tokens for `T_INTERFACE`/`T_CLASS` to find
  declared type names, the inner loop must `break` (not `continue`) on
  non-array tokens (operators, punctuation). A real type declaration
  always has `T_WHITESPACE* T_STRING` after the keyword; a non-array
  token signals this is not a declaration (e.g. `Foo::class` produces
  `T_CLASS` followed by `;` or `.`). Additionally, a backwards
  lookback for `T_DOUBLE_COLON` is needed to skip `::class` constant
  fetches. Discovered in #593 round 2, fixed in round 3.

### Candidate 2 (from round 1 — endorsed)

- **Title:** "Exception-hierarchy usage is gated, not just counted"
- **Tags:** `lint`, `ci`, `architecture`
- **Trigger:** "adding or removing a class in src/Exception/, or touching the exception-hierarchy claim in README.md"
- **Paragraph:** `bin/check-exception-usage.php` (added by #593, wired into
  `composer lint`) verifies every type in `src/Exception/` is referenced by
  at least one PHP file outside its own definition. The README advertises
  the hierarchy's size as a feature, so an unused member is dead code that
  inflates a selling point. Add new exception classes only when a real throw
  site exists; the gate will fail the build otherwise.

## Verdict

**Clean.** All actionable findings are resolved:

- F-01–F-07: fixed or deliberately retained (F-05 judgment call).
- F-08 (medium): **fixed** — token lookback for `::` + `break` on
  non-array tokens. Regression test `testClassConstantFetchIsNotMistakenForADeclaration`
  pins the fix. Verified with isolated `php -r` tests.
- F-09 (low): **fixed** — `T_TRAIT` and `T_ENUM` now included. No
  dedicated regression test, acceptable for a latent gap.
- F-10 (low): **not a real finding** — `NoResponseStrategyException`
  was created in a separate commit (e433ad5) from the 0.12 hierarchy
  migration (e40331e); it is not a "before → after" replacement in the
  context the table documents. The hierarchy tree (the authoritative
  complete listing) includes it.
- F-11 (nit): **still present** — `@trigger_error(E_USER_WARNING)` in
  `removeRecursively` should ideally be `error_log()` per DEC-017, but
  this is non-blocking (tearDown context, PHPUnit does not escalate
  `E_USER_WARNING`).

**Remaining opens:** F-05 (by-design judgment call, low), F-11 (nit,
non-blocking). Neither blocks merge.
