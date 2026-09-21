# findings-review — #694

One entry per finding, appended across rounds. Nothing is ever deleted: a
finding the coder believes fixed and the review still sees stays on the record.

## Round 1

No `findings-review.md` existed before this round — nothing to adjudicate.

| # | file:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| F-1 | `bin/kb-lint.php:559-569` | Create-index append (`$firstSection === null`) keeps every trailing empty element of `readLines()`, so an input ending in 2+ newlines yields two blank lines before the created `## Tag index` (0/1 newline inputs yield one). `rtrim(..., "\n") . "\n"` only strips the file tail, not the now-internal blank run, contradicting the "RegardlessOfTrailingNewline" test title and the code-decision note. Pre-existing behaviour; not introduced by this diff. | low | **fixed** — the create path now drops the blank run immediately before the insertion point (`while ($at > 0 && trim($lines[$at - 1] ?? '') === '') { --$at; }`) and re-adds exactly one blank; new test `testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` starts from a `"\n\n\n"` tail. |
| F-2 | `bin/kb-lint.php:585-602`, `:770` | `normalizeTrailingNewline()` is documented to return whether it rewrote the file, but the only caller ignores the bool. Drop to `void` or use it to report the rewrite. | nit | **fixed** — changed to `void`; docblock now states that an already-normalised file is left untouched. |
| F-3 | `bin/kb-lint.php:17`, `:610` | `--fix` help/file-docblock still says only "regenerate the tag index", but `--fix` now also normalises the trailing newline even when the index is in sync (silently). Update the usage text (and `bin/README.md` if it repeats it). | nit | **fixed** — both the file docblock and `printUsage()` mention the newline normalisation; `bin/README.md:168` updated too. |
| F-4 | `tests/KnowledgeBase/KbLintScriptTest.php:217-218`, `:261` | New tests use `str_ends_with(...)` + `assertTrue`/`assertFalse` while the same file already uses PHPUnit's `assertStringEndsNotWith` (`:210`). Prefer `assertStringEndsWith`/`assertStringEndsNotWith`. | nit | **fixed** — switched to `assertStringEndsWith` / `assertStringEndsNotWith`. |
| F-5 | `bin/kb-lint.php:593` | `rtrim($contents, "\n")` cannot collapse a `\r\n\r\n` tail (the `\r` blocks it), so CRLF files with multiple trailing line endings are not normalised despite the docblock's "does not end in exactly one newline". Repo is LF-only and `writeIndex()` converts CRLF→LF when it rewrites, so impact is low. | nit | **fixed** — `rtrim($contents, "\r\n") . "\n"` collapses a stray `\r\n` tail and always terminates with one LF. |
| F-6 | `bin/kb-lint.php:558-569` | If a file keeps its `## Tag index` heading but loses the start/end markers, the create path splices a second `## Tag index` section before the existing heading, producing a duplicate heading that still lints clean. Pre-existing; adjacent to the modified branch. | low | **deliberately not fixed** — distinct pre-existing defect, not #694's subject; fixing it needs marker-vs-heading reconciliation logic and its own tests. Recorded as a candidate issue at step 14. |

### Notes on checks

- F-1: no automated check catches it — the new test only compares 0 vs 1
  trailing newlines. A regression test ending in `"\n\n"` would.
- F-5: no automated check; no CRLF fixture exists in the suite.
- F-2/F-3/F-4: not machine-checkable by PHPStan / php-cs-fixer / Rector (all
  three ran clean on the changed files).
