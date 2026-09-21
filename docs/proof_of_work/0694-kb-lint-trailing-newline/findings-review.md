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

## Round 2

Adjudication of round 1 (F-1..F-6) against the round-2 commit `7dc27a0`, plus
new findings F-7/F-8. Evidence: `review-2.md` §§0,3-6; replay matrix of
master / round-1 `b1b5d39` / HEAD against a scratch sandbox.

| # | file:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| F-1 | `bin/kb-lint.php:568-578` | Create-index append (`$firstSection === null`) kept the trailing blank run; 2+ trailing newlines yielded two blanks before the created heading. | low | **fixed for the branch it named** — the run is collapsed (`:568-570`) and one blank re-added (`:574-576`); 0/1/2/3 trailing `\n` now produce byte-identical output; `testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` fails against master/round-1 and passes now. The same collapse is broken on the sibling `$firstSection !== null` branch → **F-7**. |
| F-2 | `bin/kb-lint.php:595` | `normalizeTrailingNewline()` advertised a bool return the caller ignored. | nit | **fixed** — now `void`; docblock describes write-only-when-changed. |
| F-3 | `bin/kb-lint.php:17-18`, `:620-621`, `bin/README.md:168` | `--fix` help/file docblock only said "regenerate the tag index". | nit | **fixed** — all three now mention the trailing-newline normalisation; no stale string remains in `bin/`. |
| F-4 | `tests/KnowledgeBase/KbLintScriptTest.php:210-294` | New tests mixed `str_ends_with`+`assertTrue/False` with the file's `assertStringEnds*` style. | nit | **fixed** — only `assertStringEndsWith`/`assertStringEndsNotWith` remain in the file. |
| F-5 | `bin/kb-lint.php:605` | `rtrim($contents, "\n")` could not collapse a `\r\n\r\n` tail. | nit | **fixed** — first `rtrim($contents, "\r\n") . "\n"`, then superseded by the F-8 fix: `normalizeTrailingNewline()` now leaves any file containing `\r` untouched and normalises LF-only files with `rtrim($contents, "\n") . "\n"`. |
| F-6 | `bin/kb-lint.php:558-578` | `## Tag index` heading present but markers missing → create path splices a second heading that still lints clean. | low | **still present — deliberately not fixed.** Reproduced (`…Body.\n\n## Tag index\n\nSome stale text.\n` → duplicate heading, exit 0). Acceptable to defer for #694 **only if** a separate issue is opened with the reproduction; otherwise it remains open. Round 2 does not worsen it. |
| F-7 | `bin/kb-lint.php:568-578` | **NEW (regression).** The collapse moves `$at` back over the blank run but the tail is sliced from the collapsed `$at` (`:578`), so the run stays in the tail and the unconditional `''` prepend (`:574-576`) doubles it. A file whose first `##` heading is preceded by a blank line (normal Markdown / a new KB file with sections) gets two blanks between the created index and that heading. master and round-1 produced one. Contradicts the "exactly one blank for every input state" claim in `code-decision-2.md`; the new tests miss it because they delete `## Section`, forcing the append branch. | medium | **fixed** — the head slice now ends at `$headEnd` (start of the collapsed blank run) while the tail still slices from the original `$at`, so dropped blanks are not re-emitted. New test `testFixCreatesTheIndexWithoutDoublingTheSeparatorBeforeAnExistingHeading` keeps a blank-preceded `## Section` and asserts exactly one blank before both headings; it fails on round-2 `7dc27a0` and passes now. |
| F-8 | `bin/kb-lint.php:603-611` | **NEW (nit).** `normalizeTrailingNewline()` rewrites only the tail, so an in-sync CRLF file ends with a bare LF and keeps `\r\n` everywhere else (verified: 11 `\r\n` + 1 `\n`). master/round-1 left such a file fully CRLF; `writeIndex()` converts the whole file to LF, so the paths disagree. | nit | **fixed** — `normalizeTrailingNewline()` now returns early for any file containing `\r` (LF-only normalisation), so it cannot introduce mixed line endings; docblock states the LF-only scope. |

### Notes on checks (round 2)

- F-7: caught by a create-path test on the `$firstSection !== null` branch; the
  same class as F-1, so the assertion should be added in this PR.
- F-8: no automated check; still no CRLF fixture in the suite.
- F-1/F-2/F-3/F-4/F-5 resolutions: not machine-checkable by PHPStan /
  php-cs-fixer / Rector (all ran clean on the changed files).
- Gates run this round: PHPUnit 24/265 + 41/1152, PHPStan 8 clean,
  php-cs-fixer 0/2, Rector clean, real-tree `--fix` exit 0 with no diff.

## Round 3

Adjudication of the open findings (F-6, F-7, F-8) against round-3 commit
`ba46f16`, plus new finding F-9. Evidence: `review-3.md` §§0,3-6; create-path
replay matrix of master / round-2 `7dc27a0` / HEAD against scratch sandboxes.

| # | file:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| F-6 | `bin/kb-lint.php:559-579` | `## Tag index` heading present but the start/end markers missing → create path splices a second `## Tag index` before the existing heading; still lints clean. | low | **still present — deliberately deferred.** Reproduced at HEAD (`Body.\n\n## Tag index\n\nSome stale text.\n` → duplicate heading, exit 0). Unchanged by round 3. Acceptable to defer for #694 **only if** step 14 opens the issue with this reproduction; otherwise it remains open. Round 3 does not worsen it. |
| F-7 | `bin/kb-lint.php:568-579` | Round 2 collapsed the blank run by moving `$at`, but sliced the tail from that moved index, so the dropped blanks stayed in the tail and the separator doubled them. Any blank-preceded first `##` heading got two blanks. | medium | **fixed** — `:568-571` walks `$headEnd` (leaving `$at` at the original insertion point), `:579` slices head from `$headEnd` and tail from `$at`, `:575-577` gates the separator on `$headEnd > 0`. HEAD matrix: 0/1/2/3 trailing `\n` (append) and blank-preceded `## Section` all produce exactly one blank; round-2 produced two before the heading. New test `tests/KnowledgeBase/KbLintScriptTest.php:273-295` fails on `7dc27a0`, passes now. Second `--fix` pass byte-identical in all 12 states. |
| F-8 | `bin/kb-lint.php:604-610` | `normalizeTrailingNewline()` rewrote only the tail, turning an in-sync CRLF file into mixed `\r\n` + bare `\n`. | nit | **fixed** — `:606-608` returns early for any file containing `\r`; `:610` is `rtrim($contents, "\n") . "\n"`. Verified: in-sync CRLF fixture with the final newline stripped is byte-identical after `--fix` (9 `\r\n`, last byte `0d`), no mixed endings. Residual: a CRLF file keeps a stripped trailing newline (LF-only contract, documented at `:604-605`); out-of-sync CRLF→LF conversion is pre-existing. |
| F-9 | `bin/kb-lint.php:604-608` | **NEW (low, test gap).** The CRLF early return that fixes F-8 is the only thing preventing mixed line endings, and no test exercises it — deleting/inverting it re-introduces F-8 silently. `grep -rn '\r' tests/KnowledgeBase/` finds no CRLF fixture. | low | **fixed** — `testFixLeavesCrlfFilesUntouched` writes an in-sync CRLF fixture with the trailing newline stripped, runs `--fix`, and asserts the bytes are unchanged; removing the `\r` early return makes it fail. |

### Notes on checks (round 3)

- F-7/F-9: caught by create-path / CRLF fixture tests; F-7's assertion was added
  in this PR (same class as F-1), F-9's is proposed for this PR or a follow-up.
- F-6: no automated check; needs marker-vs-heading reconciliation or a new lint
  error with its own tests.
- F-1..F-5 remain fixed at HEAD; F-2/F-3/F-4 resolutions still not
  machine-checkable by PHPStan / php-cs-fixer / Rector (all ran clean).
- Gates run this round: PHPUnit 25/280 + 42/1167, PHPStan 8 clean,
  php-cs-fixer 0/2, Rector clean, real-tree `--fix` exit 0 with no diff,
  `grep -rln kb-lint tests/` sweep (no workflow file touched, FAQ-032 N/A).

## Round 4

Adjudication of the open findings against round-4 commit `9272588`
("test(bin): cover LF-only normalisation of CRLF files (#694)"), which adds only
`testFixLeavesCrlfFilesUntouched`. Evidence: `review-4.md` §§0,3-5; mutation run
of the new test against a scratch copy with the CRLF guard deleted.

| # | file:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| F-1 | `bin/kb-lint.php:568-571`, `:579` | Append/create path kept the trailing blank run, giving two blanks before the created heading. | low | **fixed at HEAD** (intact through round 4; `testFixCollapsesExtraTrailingNewlinesBeforeACreatedIndex` green). |
| F-2 | `bin/kb-lint.php:596` | `normalizeTrailingNewline()` advertised an ignored bool. | nit | **fixed** — still `void`, docblock still states write-only-when-changed. |
| F-3 | `bin/kb-lint.php:17-18`, `:625-626`, `bin/README.md:168` | `--fix` help only mentioned the tag index. | nit | **fixed** — docblock, `printUsage()` and README all mention trailing-newline normalisation. |
| F-4 | `tests/KnowledgeBase/KbLintScriptTest.php` | Tests mixed `str_ends_with`+`assertTrue/False` with the file's `assertStringEnds*` style. | nit | **fixed** — only `assertStringEndsWith`/`assertStringEndsNotWith` remain. |
| F-5 | `bin/kb-lint.php:606-610` | `rtrim($contents, "\n")` could not collapse a `\r\n\r\n` tail. | nit | **fixed/superseded** — the CRLF guard means such files are intentionally left untouched (LF-only contract), and LF tails collapse via `rtrim(..., "\n") . "\n"`. |
| F-6 | `bin/kb-lint.php:559-579` | `## Tag index` heading present but start/end markers missing → create path splices a second `## Tag index` before the existing heading; the file still lints clean. | low | **still present — deliberately deferred.** Reproduced at HEAD in round 4 (marker-less `## Tag index` file → two `## Tag index` headings, `--fix` exit 0, `kb-lint` exit 0). Unchanged by round 4 (test-only delta). Acceptable to defer for #694 **only if step 14 opens the issue with this reproduction**; until that issue exists it stays open on the record. |
| F-7 | `bin/kb-lint.php:568-579` | Round 2 sliced the tail from the collapsed index, doubling the blank before a blank-preceded `##` heading. | medium | **fixed at HEAD** (intact through round 4; `testFixCreatesTheIndexWithoutDoublingTheSeparatorBeforeAnExistingHeading` green; create matrix in review-3 §3 unchanged). |
| F-8 | `bin/kb-lint.php:604-608` | `normalizeTrailingNewline()` produced mixed `\r\n` + `\n` for CRLF files. | nit | **fixed at HEAD** — `:606` returns early for any file containing `\r`; round 4 adds the missing regression test (F-9). |
| F-9 | `tests/KnowledgeBase/KbLintScriptTest.php:297-312` | The CRLF early return that fixes F-8 had no test. | low | **fixed** — `testFixLeavesCrlfFilesUntouched` (round 4) builds an in-sync CRLF fixture with its trailing newline stripped, runs `--fix` and asserts byte-for-byte equality. Mutation-verified: deleting the guard at `bin/kb-lint.php:606` makes the test fail (tail rewritten to a bare `\n`); the test passes at HEAD. |

### Notes on checks (round 4)

- F-6: no automated check; needs marker-vs-heading reconciliation or a new lint
  error with its own tests. Deferred to step 14, unchanged condition.
- F-9: caught by the new test itself. Mutation run (guard deleted) fails the
  assertion, so the guard is genuinely pinned. No other check covers `bin/`
  (outside coverage `<source>`).
- F-1..F-5/F-7/F-8: no regression; the round-4 delta is test + proof-of-work
  only (`git diff ba46f16..HEAD`). All gates green; no gate lowered.
