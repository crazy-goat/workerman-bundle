# Review Round 2 — issue #593: two never-thrown exception classes

**Branch:** `chore/issue-593-two-exception-classes-are-never-thrown-a`
**Date:** 2026-09-10
**Reviewer:** review subagent (round 2)
**Diff:** `git diff origin/master...HEAD` (2 commits: aea16c9 + daa8534)

## KB entries read (tag-matched)

- **FAQ-031** (`lint,bin,tests`): `bin/` is inside linter scope — phpstan
  and php-cs-fixer cover `bin/`. Verified: both pass on the new script.
- **FAQ-015** (`git-hooks,lint`): pre-push hook runs `composer lint`. The
  new check is appended to `lint` — verified at `composer.json:114`.
- **DEC-008** (`lint,git-hooks,policy`): `composer lint` checks must be
  safe to run at any committed point. The new check is read-only —
  compliant.
- **DEC-007** (`coverage,ci,policy`): 80% floor not touched. Verified.
- **DEC-009** (`knowledge-base,process,policy`): review proposes, never
  writes. Acknowledged.
- **DEC-012** (`docs,markdown`): raw angle-brackets in prose. Checked
  new `bin/README.md` text — no raw placeholders.
- **DEC-017** (`logging,security,policy`): `trigger_error(E_USER_WARNING)`
  invokes the PHP error handler even under `@`. Relevant to the
  `removeRecursively` teardown helper (F-11 below).

No KB violations found.

## findings-review.md — prior-round triage

| # | Finding | Verdict | Evidence |
|---|---------|---------|----------|
| F-01 | Regex captures "for" from docblock comments instead of real type name | **fixed** | `bin/check-exception-usage.php:130-157` now uses `token_get_all()` to find `T_INTERFACE`/`T_CLASS` tokens and extracts the next `T_STRING`. String literals and docblock comments are distinct token types, so "interface" in a docblock is `T_DOC_COMMENT`, not `T_INTERFACE`. Regression test `testTheWordInterfaceInADocblockIsNotMistakenForADeclaration` added at `tests/ExceptionUsageLintTest.php:91-106`. Verified: `php bin/check-exception-usage.php` reports "OK — 22 type(s)" and all 22 are real type names (confirmed by checking that `ClientInputExceptionInterface` and `WorkermanExceptionInterface` appear in the output, not `for`). |
| F-02 | `preg_match` captures only first declaration per file | **fixed** | The `token_get_all` loop at `checkExceptionUsageDeclaredTypes()` iterates all tokens, so every `T_INTERFACE`/`T_CLASS` in a file is discovered. Regression test `testMultipleTypesInOneFileAreAllDiscovered` at `tests/ExceptionUsageLintTest.php:108-123` creates a fixture with an interface + class and asserts both are checked. |
| F-03 | Regex does not handle `readonly class` variants | **fixed** | With `token_get_all`, modifiers like `readonly`, `final`, `abstract` are separate tokens (`T_READONLY`, `T_FINAL`, `T_ABSTRACT`) that *precede* `T_CLASS`. The scanner only looks for `T_CLASS`/`T_INTERFACE` and then the next `T_STRING`, so all modifier combinations work naturally. Verified with `php -r` that `readonly class`, `final readonly class`, `abstract class` all yield `T_CLASS` followed by `T_STRING`. |
| F-04 | UPGRADE.md tree missing 4 classes | **fixed** | The tree at `UPGRADE.md:498-522` now includes all 22 types: `ClientInputExceptionInterface`, `MalformedRequestException`, `SfxExtractionException`, `UnsupportedListenSchemeException` were added. The Before/After table at `UPGRADE.md:492-493` also added `MalformedRequestException` to the `\InvalidArgumentException` row and `SfxExtractionException` + `UnsupportedListenSchemeException` to the `\RuntimeException` row. Verified all 22 types in `src/Exception/` are present in the tree. |
| F-05 | CHANGELOG.md:1507 still names deleted `ConfigurationValidationException` | **still present** | `CHANGELOG.md` released `## [0.12.0]` entry still names `ConfigurationValidationException` under `ValidationException`. Coder's explicit judgment call: released changelogs are an immutable historical ledger; the `[Unreleased]` section at `CHANGELOG.md:81` correctly records the removal. Practical impact low — the removal is discoverable. No action needed this round. |
| F-06 | `removeRecursively` does not suppress rmdir/unlink errors | **fixed** | `tests/ExceptionUsageLintTest.php:247-260` now wraps all `rmdir()`/`unlink()` calls with `@` suppression and emits `trigger_error(E_USER_WARNING)` on failure. The top-level `rmdir($path)` is also guarded. Improvement over the original bare calls. |
| F-07 | `runScript` passes only `PATH` to the subprocess | **fixed** | `tests/ExceptionUsageLintTest.php:222` now passes `[...getenv(), ...$env]` — inheriting the full parent environment overlaid with explicit overrides. The docblock at lines 195-197 documents this choice. PHPStan clean at level 8. |

## Automated checks run

| Check | Command | Result |
|-------|---------|--------|
| Exception usage gate | `php bin/check-exception-usage.php` | OK — 22 types |
| PHPStan | `vendor/bin/phpstan analyse bin/check-exception-usage.php tests/ExceptionUsageLintTest.php` | OK (no errors) |
| php-cs-fixer | `vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php` | 0 files to fix |
| PHPUnit (ExceptionUsageLintTest) | `vendor/bin/phpunit --filter ExceptionUsageLintTest` | 13 tests, 72 assertions, OK |
| PHPUnit (RunnerTest) | `vendor/bin/phpunit --filter RunnerTest` | 42 tests, 99 assertions, OK |
| PHPUnit (BinDirectoryTest) | `vendor/bin/phpunit --filter BinDirectoryTest` | 18 tests, 40 assertions, OK |

## New findings

### F-08: `::class` keyword causes false-positive type discovery — MEDIUM

**File:** `bin/check-exception-usage.php:149-157`
**Severity:** medium (latent — no `::class` in `src/Exception/` today)

The inner loop that scans for the type name after `T_CLASS` uses
`continue` for non-array tokens (single-character tokens like `.`, `;`,
`(`, etc.):

```php
for ($j = $i + 1; $j < $count; ++$j) {
    if (!\is_array($tokens[$j])) {
        continue;   // <-- skips non-array tokens, keeps scanning
    }
    if (\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
        continue;
    }
    if ($tokens[$j][0] === \T_STRING) {
        $names[] = $tokens[$j][1];
    }
    break;
}
```

`Foo::class` produces a `T_CLASS` token. After it, if the next non-array
token is an operator like `.` (string concatenation), the loop `continue`s
past it and may find an unrelated `T_STRING` further on, recording it as a
declared type name.

**Proof:**
```php
$source = "<?php\nFoo::class . SOME_CONST;\nclass Bar {}\n";
// checkExceptionUsageDeclaredTypes() returns ['SOME_CONST', 'Bar']
// 'SOME_CONST' is a false positive — it is not a declared type.
```

Verified with `php -r` simulating the exact loop logic: `SOME_CONST` is
captured as a type name alongside the real `Bar`.

**Impact today:** none. No file in `src/Exception/` uses `::class`. But
the bug is structural: a future file that uses `FooException::class` for
a `throw new` expression before the class declaration would produce a
spurious "type" that happens to match everywhere (if the constant name
collides with a common word) or, more likely, a spurious unused-type error
(if the constant name is unique and doesn't appear elsewhere).

**Fix:** change `continue` to `break` for non-array tokens. A real type
declaration always has `T_WHITESPACE* T_STRING` immediately after
`T_CLASS`/`T_INTERFACE` — non-array tokens (operators, punctuation) signal
that this `T_CLASS` is not a declaration (e.g., `::class`). The same
applies to `T_EXTENDS`, `T_IMPLEMENTS`, and `{` for anonymous classes,
which are already handled correctly because they are array tokens that
trigger `break` without matching `T_STRING`.

**Automated check that could have caught this:** a test fixture with
`FooException::class` before a real class declaration, asserting only the
real declaration is discovered.

### F-09: Enums and traits in `src/Exception/` would not be discovered — LOW

**File:** `bin/check-exception-usage.php:141`
**Severity:** low (latent — no enums or traits in `src/Exception/` today)

The scanner only checks for `T_INTERFACE` and `T_CLASS` tokens. PHP 8.1+
enums produce `T_ENUM` and traits produce `T_TRAIT`, neither of which is
checked. If someone adds an enum or trait to `src/Exception/`, it would
not be discovered or checked for references.

**Impact today:** none. `src/Exception/` contains only interfaces, abstract
classes, and final classes — the correct types for an exception hierarchy.
Enums cannot extend classes and are not used as exceptions. Traits are
similarly not part of exception hierarchies. The gap is theoretical.

**Fix (if desired):** add `T_ENUM` and `T_TRAIT` to the condition at line
141. Low priority since the directory's purpose makes enums/traits
unlikely additions.

### F-10: `NoResponseStrategyException` missing from `\LogicException` row in Before/After table — LOW (pre-existing)

**File:** `UPGRADE.md:494`
**Severity:** low (pre-existing, not introduced by this PR)

The Before/After table at `UPGRADE.md:494` lists only
`InvalidCronExpressionException` under `\LogicException`, but
`NoResponseStrategyException` extends `\LogicException` directly and is
absent from the row. The hierarchy tree at line 521 correctly lists it
under `(extends \LogicException)`. This was already missing before this
PR — the PR modified the `\InvalidArgumentException` and `\RuntimeException`
rows but not the `\LogicException` row.

**Action:** could be fixed by adding `NoResponseStrategyException` to the
`\LogicException` row, but it is out of scope for #593 (pre-existing
inaccuracy).

### F-11: `trigger_error(E_USER_WARNING)` in `removeRecursively` invokes the error handler even under `@` — NIT

**File:** `tests/ExceptionUsageLintTest.php:249,253,259`
**Severity:** nit

The F-06 fix uses `@trigger_error(..., E_USER_WARNING)` to report
teardown failures. Per DEC-017, `@` suppresses the default handler output
but the registered error handler is still invoked — `@trigger_error` calls
the handler with `E_USER_WARNING`. In a test environment with a strict
error handler (e.g. Symfony's `DebugErrorHandler` in debug mode), this
could escalate to an `ErrorException`. However, this is in `tearDown()`,
after the test has already passed or failed, so the practical risk is
minimal. A cleaner approach would be `error_log()` per DEC-017, but the
current implementation is a significant improvement over the original
bare `rmdir()`/`unlink()` calls.

**Action:** consider switching to `error_log()` for consistency with
DEC-017, but not blocking.

## What is correct

- **token_get_all implementation:** correctly fixes F-01 (docblock
  false positives), F-02 (multiple declarations per file), and F-03
  (readonly class variants). The approach is the textbook solution:
  `T_INTERFACE`/`T_CLASS` are real parser tokens that cannot appear
  inside comments or strings.
- **Anonymous classes correctly skipped:** `new class {}` produces
  `T_CLASS` followed by `{` (non-array) or `T_EXTENDS`/`T_IMPLEMENTS`
  (array but not `T_STRING`), so no false type name is captured.
  Verified with `php -r`.
- **UPGRADE.md tree completeness:** all 22 types in `src/Exception/`
  are now present in the hierarchy tree. `FileUploadValidationException`
  appears under both `ClientInputExceptionInterface` (implements) and
  `ValidationException` (extends) — a reasonable representation of
  multiple inheritance in a tree view.
- **UPGRADE.md Before/After table:** `\InvalidArgumentException` row
  now correctly lists `MalformedRequestException` (was missing before).
  `\RuntimeException` row now correctly lists `SfxExtractionException`
  and `UnsupportedListenSchemeException` (were missing before).
  `ConfigurationValidationException` correctly removed from
  `\InvalidArgumentException` row (class deleted).
- **RunnerTest new test:** `testApplyWorkermanConfigMkdirFailureThrowsTypedExceptionInHierarchy`
  at `tests/RunnerTest.php:439-478` asserts all three hierarchy levels
  (`InvalidCacheDirectoryException`, `WorkermanExceptionInterface`,
  `\RuntimeException`) via `assertInstanceOf` — correctly avoiding the
  `expectException` double-call trap. Uses `set_error_handler` to
  suppress the expected `mkdir()` warning, with `restore_error_handler`
  in a `finally` block.
- **runScript environment:** full env inheritance via
  `[...getenv(), ...$env]` is the correct fix for F-07 — the subprocess
  gets `HOME`, `PATH`, CI vars, etc.
- **composer lint wiring:** check appended to the `lint` array,
  consistent with `check-changelog.php` pattern. DEC-008 compliant.
- **PHPStan, php-cs-fixer, PHPUnit all clean** on new and modified files.

## KB candidate entries (proposed, not written)

### Candidate 1 (revised from round 1 — now about token_get_all edge cases)

- **Title:** "token_get_all type discovery must break (not continue) on non-array tokens after T_CLASS"
- **Tags:** `lint`, `bin`, `tests`
- **Trigger:** "writing a bin/ script that discovers PHP type declarations via token_get_all"
- **Paragraph:** When scanning tokens for `T_INTERFACE`/`T_CLASS` to find
  declared type names, the inner loop must `break` (not `continue`) on
  non-array tokens (operators, punctuation). A real type declaration
  always has `T_WHITESPACE* T_STRING` after the keyword; a non-array
  token signals this is not a declaration (e.g. `Foo::class` produces
  `T_CLASS` followed by `;` or `.`). Using `continue` lets the scanner
  skip past operators and capture an unrelated `T_STRING` as a false
  type name. Discovered in #593 round 2: `Foo::class . SOME_CONST;`
  would record `SOME_CONST` as a declared type. The fix is `break` on
  the first non-array, non-whitespace, non-comment token that is not
  `T_STRING`.

### Candidate 2 (same as round 1 — endorsed)

- **Title:** "Exception-hierarchy usage is gated, not just counted"
- **Tags:** `lint`, `ci`, `architecture`
- **Trigger:** "adding or removing a class in src/Exception/, or touching the exception-hierarchy claim in README.md"
- **Paragraph:** `bin/check-exception-usage.php` (added by #593, wired into
  `composer lint`) verifies every type in `src/Exception/` is referenced by
  at least one PHP file outside its own definition. The README advertises
  the hierarchy's size as a feature, so an unused member is dead code that
  inflates a selling point. Add new exception classes only when a real throw
  site exists; the gate will fail the build otherwise. Editing the README
  count requires updating both the feature bullet and the "Code quality / DX"
  line; the count is `final classes + abstract bases` (excluding the two
  marker interfaces).

## Verdict

The coder's round-2 fixes address all 7 findings from round 1. F-01
through F-04 and F-06 through F-07 are **fixed** with evidence and
regression tests. F-05 is **still present** by design (immutable
changelog judgment call). Four new findings: F-08 (medium, latent
`::class` false positive) is the most significant — a one-line fix
(`continue` → `break` for non-array tokens) would close it; F-09 (low,
enums/traits not discovered) and F-10 (low, pre-existing table omission)
are non-blocking; F-11 (nit, `trigger_error` in teardown) is cosmetic.

**Recommendation:** address F-08 before merge (one-line fix + regression
test). F-09, F-10, F-11 can be deferred. The PR is otherwise solid: the
tokenizer approach is correct, the UPGRADE.md tree is now complete and
accurate, the test suite is thorough, and all automated checks pass.
