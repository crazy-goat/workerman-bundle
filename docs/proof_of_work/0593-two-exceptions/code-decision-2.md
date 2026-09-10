# Code Decision 2 — issue #593: fix review round 1 findings

## Summary

Round 1 review raised 7 findings (F-01 through F-07). This round fixes all
actionable ones in the three files the review touched: the type-discovery
logic in `bin/check-exception-usage.php`, the test helper robustness in
`tests/ExceptionUsageLintTest.php`, and the hierarchy tree/table in
`UPGRADE.md`.

## F-01 (high) + F-02 (medium) + F-03 (low): regex → tokenizer

**Root cause:** `bin/check-exception-usage.php:155` discovered declared types
with a `preg_match` against the raw file source:

```php
preg_match('/\b(?:interface|abstract\s+class|final\s+class|class)\s+(\w+)/', $source, $matches)
```

This has three defects, all fixed by the same change:

1. **F-01 (high):** "interface"/"class" inside docblock comments is matched
   before the real declaration. Both marker interfaces have docblocks
   containing "Marker interface for exceptions" — the regex captured `for`
   instead of the type name, so the two interfaces were silently unchecked.
   `for` is a PHP keyword appearing in countless files, so the check passed
   vacuously.
2. **F-02 (medium):** `preg_match` returns only the first match, so a file
   declaring multiple types would have subsequent ones silently skipped.
3. **F-03 (low):** `readonly class` variants are not explicitly handled (the
   bare `class` alternative catches them by accident).

**Fix:** replaced the regex with `token_get_all()`. A new function
`checkExceptionUsageDeclaredTypes(string $source): list<string>` walks the
token stream, finds every `T_INTERFACE` / `T_CLASS` token, and extracts the
following `T_STRING` (skipping whitespace and comments). This:

- ignores "interface"/"class" inside comments and strings (T_COMMENT /
  T_DOC_COMMENT are skipped between keyword and name);
- collects ALL types per file (not just the first);
- handles `abstract class`, `final class`, `readonly class`,
  `final readonly class`, etc. uniformly — the modifiers are separate tokens
  that precede `T_CLASS`, so the scanner sees `T_CLASS` regardless.

The call site in `checkExceptionUsageMain` now iterates
`checkExceptionUsageDeclaredTypes($source)` and appends each discovered name
to `$declared`.

**Regression tests added** (per the review's suggestion):

- `testTheWordInterfaceInADocblockIsNotMistakenForADeclaration`: a fixture
  whose docblock says "Marker interface for unreferenced exceptions" before
  the real `interface UnusedMarkerInterface` declaration. Asserts the script
  reports `UnusedMarkerException` as unused, NOT `for`.
- `testMultipleTypesInOneFileAreAllDiscovered`: a fixture declaring both
  `FirstInterface` and `SecondException` in one file, with the first
  referenced and the second not. Asserts only `SecondException` is reported
  as unused.

Both tests fail against the old regex (confirmed: the old regex captures
`for` from the fixture docblock) and pass with the tokenizer.

## F-06 (nit): removeRecursively error handling

The `removeRecursively` test-teardown helper called `rmdir()` and `unlink()`
without suppressing or checking return values. On restrictive permissions or
locked files, PHP would emit a warning that could pollute test output.

**Fix:** each `rmdir`/`unlink` call is now prefixed with `@` to suppress the
warning, and the return value is checked. On failure,
`trigger_error(..., E_USER_WARNING)` provides a diagnostic that PHPUnit can
surface if something is genuinely wrong — without an unsuppressed PHP warning
on every teardown.

## F-07 (nit): subprocess environment

`runScript` previously passed only `['PATH' => getenv('PATH')]` to the
subprocess — minimal but fragile if the script later needs `HOME`, a CI
variable, or anything else the parent has.

**Fix:** the subprocess now inherits the full parent environment via
`[...getenv(), ...$env]`, with explicit overrides still taking precedence.
This is the standard, least-surprising default for subprocess tests: the
child sees the same environment the test runner sees.

## F-04 (low): UPGRADE.md hierarchy tree missing 4 classes

The "Upgrading to 0.12" hierarchy tree was missing
`MalformedRequestException`, `SfxExtractionException`,
`UnsupportedListenSchemeException`, and `ClientInputExceptionInterface`.
This was pre-existing (not introduced by round 1), but trivially verifiable
from `src/Exception/`:

- `UnsupportedListenSchemeException` extends `WorkermanException` → added
  under `WorkermanException`.
- `SfxExtractionException` extends `\RuntimeException` implements
  `WorkermanExceptionInterface` → added as a top-level branch.
- `ClientInputExceptionInterface` extends `WorkermanExceptionInterface` →
  added as a sub-interface of `WorkermanExceptionInterface`.
- `MalformedRequestException` extends `\InvalidArgumentException` implements
  `ClientInputExceptionInterface` → added under
  `ClientInputExceptionInterface`.

`FileUploadValidationException` appears under both `ValidationException`
(its parent class) and `ClientInputExceptionInterface` (its interface) —
correct for a tree showing both `extends` and `implements` relationships.

The migration table was also updated: `MalformedRequestException` added to
the `\InvalidArgumentException` row; `SfxExtractionException` and
`UnsupportedListenSchemeException` added to the `\RuntimeException` row.

## F-05 (low): CHANGELOG 0.12.0 entry — no edit

The released `## [0.12.0]` entry at `CHANGELOG.md:1507` still names the
deleted `ConfigurationValidationException`. Per the coder's decision in
round 1 (and endorsed by the reviewer), released changelog entries are an
immutable historical ledger. The `[Unreleased]` section at line 81 correctly
records the removal. **No edit made.** Confirmed in this report.

## What I rejected

1. **Stripping comments with a regex instead of using the tokenizer.**
   Rejected: a comment-stripping regex (e.g. removing `/\*.*?\*/` and
   `//.*$`) is itself fragile (nested comments, strings containing `*/`,
   edge cases). The tokenizer is PHP's own parser — it is the correct tool
   and handles all edge cases by construction.

2. **Keeping the minimal `PATH`-only env and documenting why.** Rejected:
   inheriting the parent env is strictly more robust and is the standard
   convention. There is no downside — the script does not rely on the
   absence of any env var, and explicit overrides still work.

## KB candidate entries (proposed, not written)

### Candidate 1 (new — from F-01 fix)

- **Title:** "Use token_get_all() to discover PHP type declarations, not
  regex"
- **Tags:** `lint`, `bin`, `tests`
- **Trigger:** "writing a bin/ script that discovers interface/class
  declarations by scanning PHP source"
- **Paragraph:** A regex matching `interface Foo` / `class Foo` against raw
  file source will match "interface" or "class" inside docblock comments,
  capturing the wrong name (e.g. "Marker interface for exceptions" yields
  `for` instead of the declared type). Use `token_get_all()` and look for
  `T_INTERFACE` / `T_CLASS` tokens followed by a `T_STRING` name — this also
  handles multiple types per file and `readonly class` variants uniformly.
  Discovered in #593's `bin/check-exception-usage.php`, where both marker
  interfaces were silently unchecked because the regex captured `for` from
  their docblocks.

### Candidates 2 and 3 — same as round 1 (endorsed by reviewer), not repeated.
