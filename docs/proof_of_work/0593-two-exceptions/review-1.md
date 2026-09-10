# Review Round 1 — issue #593: two never-thrown exception classes

**Branch:** `chore/issue-593-two-exception-classes-are-never-thrown-a`
**Date:** 2026-09-10
**Reviewer:** review subagent (round 1)

## KB entries read (tag-matched)

- **FAQ-031** (`lint,bin,tests`): `bin/` is inside linter scope — phpstan and
  php-cs-fixer cover `bin/`; coverage floor excludes it. Relevant because the
  PR adds `bin/check-exception-usage.php` and `tests/ExceptionUsageLintTest.php`.
- **FAQ-015** (`git-hooks,lint`): pre-push hook runs `composer lint`. Relevant
  because the new check is wired into `composer lint`.
- **DEC-008** (`lint,git-hooks,policy`): `composer lint` is the canonical entry
  point; a check inside `lint` must be safe to run at any point in a cycle.
  Relevant because the new check is appended to the `lint` script.
- **DEC-007** (`coverage,ci,policy`): 80% line-coverage floor, single source of
  truth in `composer.json`. Checked — not touched by this PR.
- **DEC-009** (`knowledge-base,process,policy`): review proposes KB entries,
  never writes. Acknowledged — proposals at the end of this report.
- **DEC-012** (`docs,markdown`): raw angle-bracket placeholders must be
  backticked. Checked the new `bin/README.md` prose — no raw placeholders.

No KB violations found.

## findings-review.md — prior-round review

`findings-review.md` did not exist before this round (first review). No
prior findings to triage.

## Coder's findings-coder.md — triage

The coder raised 5 items in `findings-coder.md`. Triage:

1. **Runner constructor bare `\InvalidArgumentException`** — **out of scope,
   acknowledged.** Confirmed at `src/Runner.php:27`. This is a real pattern
   escape but explicitly out of scope for #593. No action required this PR.
2. **Runner `warmUpCache()` 7 bare `\RuntimeException` throws** — **out of
   scope, acknowledged.** Confirmed at `src/Runner.php:85,124,131,136,144,
   148,154`. Real issue, explicitly out of scope. No action required this PR.
3. **Inconsistent mkdir parenthesization** — **cosmetic, pre-existing.**
   Confirmed: line 182 wraps mkdir in extra parens, line 190 does not.
   Behavior identical. Not a bug.
4. **UPGRADE.md historical inaccuracy** — **addressed.** The coder corrected
   the living UPGRADE.md table (removed `ConfigurationValidationException` from
   the `\InvalidArgumentException` row and the hierarchy tree). The released
   CHANGELOG entry at line 1507 still names the class — this is a judgment call
   (see finding F-06 below).
5. **faq.md over 300-line budget** — **pre-existing, acknowledged.** Confirmed:
   `kb-lint.php` reports 404 lines, 300-line limit. Not introduced by this PR.

## Automated checks run

| Check | Result |
|-------|--------|
| `php bin/check-exception-usage.php` | OK (22 types) |
| `vendor/bin/phpstan analyse` | OK (no errors) |
| `vendor/bin/php-cs-fixer fix --dry-run` | 0 files to fix |
| `vendor/bin/rector process --dry-run` | OK |
| `vendor/bin/phpunit` (full suite) | 2645 tests, 1 pre-existing PHAR guard failure (unrelated) |
| `php bin/kb-lint.php` | OK with 1 pre-existing warning (faq.md over budget) |

## Findings

### F-01: Regex matches "interface"/"class" inside docblock comments, producing wrong type names — HIGH

**File:** `bin/check-exception-usage.php:155`
**Severity:** high

The type-discovery regex:
```
/\b(?:interface|abstract\s+class|final\s+class|class)\s+(\w+)/
```
uses `preg_match` (first-match-only) against the raw file source, including
docblock comments. If a docblock contains the word "interface" or "class"
followed by a word, the regex captures that word instead of the actual
declared type name.

**Confirmed impact on the real tree:** both interface files have docblocks
that contain the word "interface" before the actual declaration:

- `src/Exception/ClientInputExceptionInterface.php` — docblock line 8:
  "Marker interface for exceptions" → regex captures `for` instead of
  `ClientInputExceptionInterface`.
- `src/Exception/WorkermanExceptionInterface.php` — docblock line 8:
  "Marker interface for all WorkermanBundle exceptions" → regex captures
  `for` instead of `WorkermanExceptionInterface`.

The script then searches for the word `for` across all PHP files — `for` is
a PHP keyword appearing in countless files — so the check passes vacuously.
**The two marker interfaces are never actually checked for references.** An
unreferenced interface would go undetected.

**Proof:** ran the regex against `ClientInputExceptionInterface.php` →
matched `for`, not `ClientInputExceptionInterface`. Created a synthetic
fixture with an unused `UnusedInterface` (docblock: "Marker interface for
unreferenced exceptions") → the script reported `for` as unused (because the
minimal fixture had no other `for`), NOT `UnusedInterface`. The real unused
type was missed.

**Fix:** strip comments/docblocks before matching, or require the keyword at
the start of a line (after optional whitespace), or use `token_get_all()` to
find real T_INTERFACE/T_CLASS tokens.

**Automated check that could have caught this:** a unit test that creates a
fixture whose docblock contains "interface" before the declaration and
asserts the script discovers the actual type name, not the docblock word.
The existing `testAReferenceInsideTheSameFileDoesNotCount` test is close but
its docblock uses `@return SelfReferentialException` (no "interface"/"class"
keyword before the name), so it doesn't exercise this bug.

### F-02: `preg_match` captures only the first declaration per file — MEDIUM

**File:** `bin/check-exception-usage.php:155`
**Severity:** medium

`preg_match` returns only the first match. If a file in `src/Exception/`
declares multiple types (e.g., an interface and a class in the same file),
only the first is discovered and checked; subsequent declarations are
silently skipped. No current file has multiple declarations, so this is not
breaking today, but it is a latent gap.

**Fix:** use `preg_match_all` or `token_get_all`.

**Automated check that could have caught this:** a test fixture with two
type declarations in one file where the second is unreferenced.

### F-03: Regex does not explicitly handle `readonly class` variants — LOW

**File:** `bin/check-exception-usage.php:155`
**Severity:** low

The regex alternatives are `interface`, `abstract\s+class`, `final\s+class`,
and bare `class`. It does not include `readonly\s+class`, `final\s+readonly
\s+class`, or `abstract\s+readonly\s+class`. In practice, the bare `class`
alternative catches all `readonly` variants by matching the `class` keyword
within them, so the type name IS discovered — but only by accident. If
someone adds `readonly enum` (PHP 8.3+ backed enums can implement interfaces),
or if the regex is later tightened to be more precise without accounting for
`readonly`, this could break.

No current exception file uses `readonly`, so not breaking today.

### F-04: UPGRADE.md hierarchy tree is missing 4 classes (pre-existing) — LOW

**File:** `UPGRADE.md:498-517`
**Severity:** low (pre-existing, not introduced by this PR)

The hierarchy tree under "Upgrading to 0.12" does not list
`MalformedRequestException`, `SfxExtractionException`,
`UnsupportedListenSchemeException`, or `ClientInputExceptionInterface`.
These classes exist in `src/Exception/` but were presumably added after the
0.12 UPGRADE section was written. This PR only removed
`ConfigurationValidationException` from the tree (correct), but the tree was
already incomplete before. Flagging for awareness; no action required this PR.

### F-05: CHANGELOG.md line 1507 still names deleted `ConfigurationValidationException` — LOW

**File:** `CHANGELOG.md:1507`
**Severity:** low

The released `## [0.12.0]` entry at line 1507 still lists
`ConfigurationValidationException` under `ValidationException`. The coder
explicitly chose to leave this as an immutable historical record (documented
in `code-decision-1.md` and `findings-coder.md`). The `[Unreleased]` section
at line 81 correctly records the removal. The coder's reasoning is sound
(released changelogs as historical ledger). However, if the issue's
acceptance criterion "no reference survives anywhere" is read strictly,
this is a remaining reference. The `Unreleased` entry makes the deletion
discoverable, so the practical impact is low.

### F-06: `removeRecursively` in test does not handle errors from rmdir/unlink — NIT

**File:** `tests/ExceptionUsageLintTest.php:199-210`
**Severity:** nit

The `removeRecursively` helper calls `rmdir()` and `unlink()` without
suppressing or checking errors. If a file has restrictive permissions or is
locked, the cleanup will emit a warning. This is a test-teardown helper, so
the practical risk is minimal, but wrapping with `@` or checking the return
value would be cleaner.

### F-07: `runScript` environment is minimal (only PATH) — NIT

**File:** `tests/ExceptionUsageLintTest.php:173`
**Severity:** nit

The subprocess is run with only `PATH` in the environment. While the script
doesn't need other env vars today, this is fragile if the script later reads
an env var. Not breaking now.

## What is correct

- **Exception class count:** 22 files - 2 interfaces = 20 classes. README
  updated to "20 exception classes (plus 2 marker interfaces)" in both the
  feature bullet (line 51) and the DX line (line 73). Verified accurate.
- **`InvalidCacheDirectoryException` wiring:** two `\RuntimeException` throw
  sites in `Runner::applyWorkermanConfig()` correctly replaced with
  `InvalidCacheDirectoryException`. BC preserved (extends `KernelException` →
  `WorkermanException` → `\RuntimeException`). `@throws` docblock updated.
- **`ConfigurationValidationException` deletion:** class removed, no dangling
  references in `src/` or `tests/`. UPGRADE.md table and tree corrected.
- **Test coverage:** existing test updated to assert the concrete type; new
  dedicated test asserts concrete type + `WorkermanExceptionInterface` +
  `\RuntimeException` via `assertInstanceOf` (correctly avoiding the
  `expectException` double-call trap). Both tests trigger the real mkdir
  condition.
- **`composer lint` wiring:** check appended to the `lint` array (not
  `lint-fix`, which is correct — the check has no fix mode). Consistent with
  `check-changelog.php` pattern. DEC-008 compliant: safe to run at any
  committed point.
- **`bin/README.md` documentation:** thorough, accurate description of the
  script, exit codes, and word-boundary behavior.
- **PHPStan, php-cs-fixer, rector all clean** on the new files.

## KB candidate entries (proposed, not written)

### Candidate 1

- **Title:** "Type-discovery regexes must strip comments first"
- **Tags:** `lint`, `bin`, `tests`
- **Trigger:** "writing a bin/ script that discovers PHP type declarations by
  regex"
- **Paragraph:** A regex that matches `interface Foo` / `class Foo` against
  raw file source will match the words "interface" or "class" inside
  docblock comments, capturing the wrong name (e.g. "Marker interface for
  exceptions" yields `for` instead of the declared type). Either strip
  comments/docblocks before matching, anchor the keyword to the start of a
  line, or use `token_get_all()` to find real T_INTERFACE/T_CLASS tokens.
  Discovered in #593's `bin/check-exception-usage.php`, where both marker
  interfaces were silently unchecked because the regex captured `for` from
  their docblocks and `for` (a PHP keyword) matched everywhere.

### Candidate 2 (same as coder's proposal — endorsed)

- **Title:** "Exception-hierarchy usage is gated, not just counted"
- **Tags:** `lint`, `ci`, `architecture`
- **Trigger:** "adding or removing a class in src/Exception/, or touching the
  exception-hierarchy claim in README.md"
- **Paragraph:** `bin/check-exception-usage.php` (added by #593, wired into
  `composer lint`) verifies every type in `src/Exception/` is referenced by at
  least one PHP file outside its own definition. The README advertises the
  hierarchy's size as a feature, so an unused member is dead code that
  inflates a selling point. Add new exception classes only when a real throw
  site exists; the gate will fail the build otherwise. Editing the README
  count requires updating both the feature bullet and the "Code quality / DX"
  line; the count is `final classes + abstract bases` (excluding the two
  marker interfaces).

### Candidate 3 (same as coder's proposal — endorsed)

- **Title:** "Released CHANGELOG entries are immutable; UPGRADE.md is living"
- **Tags:** `docs`, `upgrade`, `bc`
- **Trigger:** "deleting a class or changing a thrown type that a past
  released CHANGELOG version mentions"
- **Paragraph:** When removing a class that a released `## [x.y.z]` CHANGELOG
  entry names, record the removal in a new `[Unreleased]` section rather than
  editing the released entry — released changelogs are a historical ledger.
  `UPGRADE.md`, by contrast, is living guidance: correct its past-version
  sections when they become inaccurate. Discovered in #593, where
  `ConfigurationValidationException` was deleted: the 0.12 CHANGELOG entry
  kept the name (history) while the 0.12 UPGRADE table was corrected
  (guidance).
