# findings-review.md — #654 (round 1, review-critical)

One entry per finding. Format: `file:line | what is wrong | severity | status`.
All statuses OPEN — this is round 1; `findings-review.md` did not exist before.

bin/check-changelog.php:135 + tests/ChangelogStructureTest.php:57–325 | The equality boundary of the descending-order rule (`version_compare(...) >= 0`) is unpinned: the out-of-order fixture only uses strictly ascending versions (0.10.0 → 0.11.0), so relaxing `>= 0` to `> 0` (allowing duplicate released versions) passes all 14 tests | medium | OPEN

tests/ChangelogStructureTest.php:57–325 | Fixture gaps for four more validator behaviors: "no `## [...]` headings at all" (script :91–93), "no released version headings" / only-Unreleased file (script :147–149, coder F-6 behavior), silent skip of unknown/misspelled subheadings like `### Fix` (script :156), and continuation-line reference acceptance (script :180) — a regression in any of these passes CI | medium | OPEN

bin/check-changelog.php:217,239,249 | Fenced code blocks are not skipped by versionHeadings/versionBlocks/subheading capture: a `## [x.y.z] - date` or `### Fixed` line inside ``` ``` ``` becomes phantom structure — it counts as a released heading, splits version blocks, and can both manufacture violations and hide real duplicates (probed: a changelog whose only released heading came from inside a fence was accepted). Latent — real CHANGELOG.md has no fences | low | OPEN

bin/check-changelog.php:180 | Reference regex `\[#\d+\]|\(#\d+\)` accepts non-references: a backtick-wrapped `` `(#123)` `` in inline code or an anchor link `[x](#123)` satisfies rule 4 without being an issue reference (both probed). Extends coder F-5 with concrete false-positive shapes | low | OPEN

bin/check-changelog.php:307,324,366 | Global symbol collisions are wider than coder F-1 records: besides a third global `main()`, the new script re-declares `parseArgs()` and `printUsage()` already declared by bin/pick-issue.php:345,361 — including any two of these scripts in one process is a redeclare fatal. The void-main-internal-exit change silenced only the PHPStan cross-file resolution symptom, not the hazard (latent today: all invocations are separate processes, tests use proc_open) | low | OPEN

docs/proof_of_work/0654-changelog-validation/code-decision-1.md:53–55 | Proof-of-work misstates the sibling convention: claims kb-lint.php and pick-issue.php both use "void main() that exits internally"; actually kb-lint declares `main(): int` (internal exit(0), declared return never used) and pick-issue's void main returns normally on success. The actual PHPStan fix was dropping `exit(...)` around the bare call. A future reader could "restore consistency" from this wrong premise | low | OPEN

bin/check-changelog.php:121 | Dates validated by shape only — `2026-13-45` passes (probed) — and never checked for monotonicity against the version order. Inherited verbatim from the pre-refactor test, so not a regression | nit | OPEN

bin/check-changelog.php:98 | Exact whitespace-sensitive match on `'## [Unreleased]'`: a trailing space yields the misleading pair "found 0" + "does not match ## [x.y.z] - YYYY-MM-DD" instead of one precise message (probed); rtrim before comparison would fix the diagnostics | nit | OPEN
