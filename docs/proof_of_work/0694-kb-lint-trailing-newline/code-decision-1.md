# code-decision-1 — #694: kb-lint `--fix` trailing newline + index separator

## Approach taken

Two changes in `bin/kb-lint.php::writeIndex()` (lines 549–576):

1. **Normalise the write path.** The final write is now
   `file_put_contents($absolute, rtrim(implode("\n", $lines), "\n") . "\n")`.
   Whatever trailing-newline state the input had, the file written ends in
   exactly one `"\n"`. `readLines()` / `parseFile()` are untouched, so the
   parser still sees the input's real line structure; only the bytes emitted by
   `--fix` are normalised. This applies to both branches (regenerate and
   create), as required.
2. **Make the create-index separator explicit.** In the `$index === null`
   branch, before the section is spliced in, a leading `''` element is added
   when the line just before the insertion point is not already blank:

   ```php
   if ($at > 0 && trim($lines[$at - 1] ?? '') !== '') {
       $section = ['', ...$section];
   }
   ```

   The rest of the block (`['## Tag index', '', INDEX_START, ...$rendered,
   INDEX_END, '']`) is unchanged. With a trailing-newline input, `$lines` ends
   in `''`, `$at - 1` points at that blank, so no extra line is added — the
   output is byte-identical to before. Without a trailing newline, the line
   before the insertion point is body text, so the separator is supplied
   explicitly. Both inputs now normalise to the same bytes.

Regression tests added to `tests/KnowledgeBase/KbLintScriptTest.php`:

- `testFixNormalizesAStrippedTrailingNewline` — strips the `"\n"` from the
  fixture, makes the index out of sync (so `--fix` actually calls
  `writeIndex()`), then asserts the result ends in exactly one `"\n"`
  (`str_ends_with($c, "\n")` and `!str_ends_with($c, "\n\n")`).
- `testFixCreatesTheIndexWithABlankSeparatorRegardlessOfTrailingNewline` —
  removes both the tag-index block and the `## Section` heading so the create
  path appends at end-of-file (the case the issue describes), strips the
  trailing newline, runs `--fix`, asserts `"\n\n## Tag index"`, then runs
  `--fix` over the with-newline equivalent and asserts `assertSame` on the two
  normalised results, and finally asserts a second (no-`--fix`) `kbLint()`
  passes, i.e. the created index is in sync.

## Rejected alternatives

- **Change `readLines()` to normalise on read** (e.g. append `''` always, or
  drop a trailing `''`). Rejected: `readLines()` output feeds `parseFile()`
  line numbers and `indexFootprint()`; changing it is a wider blast radius and
  the task explicitly says read semantics must not change. Only the write path
  needed normalising.
- **Conditionally write only when a change is detected.** More code with no
  benefit: `writeIndex()` is only reached when the index is missing or out of
  sync, so a write is already warranted.
- **`rtrim($contents, "\n") . "\n"` before `explode`.** Would fix the trailing
  newline but not the create-index separator, and would change what the parser
  sees. Rejected as the issue asks for the separator to be explicit, not for
  the parser to be fooled.
- **Unconditionally prepend `''` on the create path.** Rejected: with a
  trailing-newline input that already contributes a blank element this would
  produce two blank lines before `## Tag index`.
- **Use `str_ends_with()` in the script.** The script targets `^8.2`; the
  point is moot — we do not branch on the trailing state, we always emit one
  newline.

## Uncertainties / notes

- The separator guard uses `trim($lines[$at - 1] ?? '') !== ''`, i.e. any
  whitespace-only line counts as blank. That matches the existing
  `trim(...) === ''` tests used elsewhere in the file (e.g. `indexFootprint`).
- If the input deliberately ends with *two* newlines, the create path keeps
  both (the "before" line is already blank, so nothing is prepended) and the
  final `rtrim(..., "\n") . "\n"` collapses them to exactly one anyway. No
  double-blank can be introduced by the new code.
- `writeIndex()` is only invoked when the index is missing or out of sync
  (see `main()`, lines ~710–728). A file with an in-sync index but no trailing
  newline therefore still passes lint and `--fix` never touches it — the write
  path is normalised, but the *decision to write* is not. This is outside the
  task's stated scope (which targets `writeIndex()`) but is recorded in
  `findings-coder.md` as a weak spot.
