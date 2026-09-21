# code-decision-4 — #694, review round 3 fix (F-9)

Round 3 was clean apart from one low test-coverage gap: the CRLF early return in
`normalizeTrailingNewline()` (the F-8 fix) had no test, so deleting it would
silently reintroduce mixed line endings.

## What changed

Added `testFixLeavesCrlfFilesUntouched` to `tests/KnowledgeBase/KbLintScriptTest.php`:

- builds the normal valid fixture, converts every `\n` to `\r\n`, strips the
  trailing `\r\n` (so the file needs normalisation in principle) while leaving
  the tag index in sync;
- runs `kb-lint --fix` and asserts the file is byte-for-byte unchanged.

Because the index is in sync, `writeIndex()` is not called; only
`normalizeTrailingNewline()` would touch the file, and the `\r` early return
prevents it. Removing that return makes the test fail (the tail would be
rewritten to a bare `\n`).

## Alternatives rejected

1. **A CRLF fixture that also needs the index regenerated.** Would exercise
   `writeIndex()`, which converts CRLF→LF for the whole file — the outcome F-8
   deliberately avoids for untouched files. Testing the in-sync case is the one
   that isolates the guard.
2. **Leave F-9 as a follow-up.** It is a one-test gap directly under the change,
   and `bin/` is outside the coverage `<source>`, so nothing else would catch a
   regression.

## Uncertainties

- None. F-6 (duplicate heading on a marker-less file) remains deferred to a
  separate issue, per rounds 2-3.
