# Findings — Coder

**Issue:** #674 — Provide a Dockerfile for running the test suite without local PHP/extensions
**Branch:** feat/issue-674-provide-a-dockerfile-for-running-the-tes
**Date:** 2026-08-22

## Biggest problem

No major obstacle. The issue was well-specified with explicit acceptance
criteria, proposed file contents, and usage examples. The main task was
matching CI's PHP 8.2 leg exactly (extensions, ini-values, coverage driver)
so a green local Docker run reproduces the CI gate — the `.github/workflows/tests.yaml`
file provided all the required values.

One minor wrinkle: the CHANGELOG `[Unreleased]` block already had an `### Added`
subheading further down (line ~214). Adding a new `### Added` at the top
violated `bin/check-changelog.php`'s uniqueness rule (at most one subheading
per version block). The fix was to merge the Dockerfile entry into the existing
`### Added` section instead of creating a new one. (Fixed by the orchestrating
session after the coder hit its iteration limit.)

## Discovered bugs / places to improve

1. **bin/README.md: duplicate paragraph (introduced by the coder)**
   - `bin/README.md` around line 176 — the `kb-lint` section's closing
     paragraph was accidentally duplicated (copy-paste). The `### docker-test`
     entry was inserted after the duplicate. Fixed by the orchestrating
     session: removed the duplicate paragraph.
   - Severity: low (cosmetic, but sloppy).

2. **CHANGELOG.md: duplicate `### Added` heading (introduced by the coder)**
   - `CHANGELOG.md` line 10 — a new `### Added` was added at the top of the
     `[Unreleased]` block while an existing `### Added` already existed at
     line ~214. `bin/check-changelog.php` enforces at most one per version
     block. Fixed by merging the entry into the existing section.
   - Severity: low (CI would have caught it via `composer lint`).

No bugs or weak spots were discovered outside this issue's scope during this
implementation.
