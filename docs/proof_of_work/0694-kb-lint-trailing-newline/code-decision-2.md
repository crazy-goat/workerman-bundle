# code-decision-2 — #694, review round 1 fixes

Round 1 raised six findings (two `low`, four `nit`). Resolutions:

- **F-1 (`low`) — double blank before a created index with 2+ trailing
  newlines.** The create path kept every trailing empty element from
  `readLines()`, so `rtrim(..., "\n") . "\n"` (file tail only) left an internal
  `"\n\n\n"` before the inserted heading. Fixed by collapsing the blank run
  immediately before the insertion point and re-adding exactly one:

  ```php
  while ($at > 0 && trim($lines[$at - 1] ?? '') === '') {
      --$at;
  }
  $section = ['## Tag index', '', INDEX_START, ...$rendered, INDEX_END, ''];
  if ($at > 0) {
      $section = ['', ...$section];
  }
  ```

  This subsumes the earlier `trim($lines[$at - 1]) !== ''` guard: after the
  collapse, the preceding line is non-blank whenever `$at > 0`, so the separator
  is always exactly one blank. Test:
  `testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` (input tail
  `"\n\n\n"`).

- **F-2 (`nit`) — ignored bool return.** `normalizeTrailingNewline()` is now
  `void`; the write-only-when-changed behaviour is documented but no longer
  advertised as a return value nobody reads.
- **F-3 (`nit`) — help text stale.** The file docblock, `printUsage()` and
  `bin/README.md:168` now say `--fix` also normalises the trailing newline.
- **F-4 (`nit`) — assertion style.** `str_ends_with(...)` + `assertTrue`/
  `assertFalse` replaced with `assertStringEndsWith` / `assertStringEndsNotWith`,
  matching the file's existing style.
- **F-5 (`nit`) — CRLF tail.** `rtrim($contents, "\r\n") . "\n"` now collapses a
  stray `\r\n` tail and always terminates with one LF.
- **F-6 (`low`) — duplicate `## Tag index` heading when markers are missing.**
  Deliberately not fixed: it is a distinct pre-existing defect (marker/heading
  reconciliation), not #694's subject, and needs its own tests. Recorded as a
  candidate issue at step 14 with the review's reproduction.

## Alternatives rejected

1. **Trim trailing blanks from `$lines` once at the start of `writeIndex()`.**
   Would also change the `$index !== null` (regenerate) path and the `tail`
   slice, widening the blast radius; the collapse is only needed at the create
   insertion point.
2. **Only prepend `''` unconditionally on the create path.** Produces a double
   blank when the input already had a blank run (F-1); the collapse is what
   makes "exactly one" true for every input state.
3. **Handle F-6 here.** Rejected to keep the PR on #694; the marker-less-file
   case is malformed input that the current lint does not reject, so the real
   fix likely belongs with a new "index heading without markers" error, which is
   a separate behavioural change.

## Uncertainties

- None new. The `while` collapse terminates because `$at` strictly decreases and
  is bounded below by 0.
