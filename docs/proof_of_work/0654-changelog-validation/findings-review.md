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

---

# Round 2 — dispositions for round-1 findings (commit 27cbe28)

Format: original finding → disposition (FIXED / PARTIALLY FIXED / ANSWERED / NOT A REAL FINDING), with evidence. New findings appended at the end.

bin/check-changelog.php:135 + tests/ChangelogStructureTest.php (equality boundary) | FIXED — `testDuplicateReleasedVersionHeadingsFail` pins `## [0.2.0]` twice failing with "line 15: version 0.2.0 is not strictly older than"; fixture comment states relaxing `>= 0` to `> 0` breaks the suite; verified green in run | medium | FIXED

tests/ChangelogStructureTest.php (fixture gaps) | FIXED — all four behaviors pinned: `testAFileWithNoVersionHeadingsAtAllFails`, `testAnOnlyUnreleasedFileFailsForWantOfReleasedHeadings` (F-6 now deliberate), `testAnUnknownSubheadingIsSilentlySkipped`, `testAReferenceOnAContinuationLineIsAccepted`. Residual: `--help`/`-h` exit-0 still unpinned (leftover nit, non-blocking) | medium | FIXED

bin/check-changelog.php:217,239,249 (fences) | PARTIALLY FIXED — `outsideFences()` blanks ``` -fenced lines and markers 1:1 with line numbers preserved (fixtures + probes confirm: fenced-only heading fails "no released version headings"; one-real+one-fenced passes; two-real+one-fenced fails "has 2"). Tilde fences (`~~~`) remain invisible → new NF-1 | low | PARTIALLY FIXED

bin/check-changelog.php:180 (reference false positives) | FIXED — prose-only matching: inline-code spans stripped (`entryProse()`), `\]\(#\d+\)` anchors removed before matching; both round-1 shapes pinned by `testABacktickedReferenceShapeIsNotAReference` and `testAnAnchorLinkIsNotAReference`; canonical forms verified unaffected (real tree OK) | low | FIXED

bin/check-changelog.php:307,324,366 (global symbol collisions) | FIXED for this script — bootstrap trio renamed `checkChangelogMain/ParseArgs/PrintUsage`; grep confirms every symbol in bin/check-changelog.php is unique to it. Residual kb-lint ↔ pick-issue collisions pre-existing and out of scope per instruction | low | FIXED

docs/proof_of_work/0654-changelog-validation/code-decision-1.md:53–55 (PoW misstatement) | FIXED — rewritten accurately: kb-lint `main(): int` with internal exits and never-observed return, pick-issue void main returns normally, bare call was the actual PHPStan fix | low | FIXED

bin/check-changelog.php:121 (date shape only) | FIXED — merged regex captures date, `checkChangelogIsIsoDate()` adds `checkdate()` with correct (month, day, year) order; `testAnImpossibleCalendarDateFails` pins `2026-13-45`; dead "unable to extract version" branch eliminated as a side-effect | nit | FIXED

bin/check-changelog.php:121 (date monotonicity vs versions) | ANSWERED — deliberate non-fix with recorded rationale (needs a decision on backdated patch releases); logged in findings-coder.md as possible follow-up | nit | ANSWERED (WONTFIX, rationale recorded)

bin/check-changelog.php:98 (trailing-space `[Unreleased]`) | FIXED — `versionHeadings()`/`versionBlocks()` rtrim headings; `testATrailingSpaceOnTheUnreleasedHeadingIsTolerated` pins the pass. Side-effect accepted: trailing space on released headings also tolerated now (consistent). Residual one level down → new NF-3 | nit | FIXED

## Round 2 — new findings

bin/check-changelog.php:93–110 | `outsideFences()` toggles only on ``` markers; CommonMark `~~~` fences are unrecognized, so a tilde-fenced example heading still counts as structure (probed: file whose only released heading is inside `~~~ … ~~~` passes). Same class as round-1's fence finding, one syntax over; latent (real file has no fences) | low | OPEN

bin/check-changelog.php:93–110 | Unterminated ``` fence blanks the rest of the file: lint fails closed (correct) but with generic messages ("no released version headings") that never mention the unclosed fence (probed) | nit | OPEN

bin/check-changelog.php:304–325 | Subheading capture `### (.+)$` is not rtrimmed: `### Fixed ` (trailing space) is silently treated as unknown and escapes duplicate detection — a whitespace dodge vector; same diagnostic class as the fixed `[Unreleased]` rtrim issue. Self-reported by coder in round-2 notes, left out of scope there | nit | OPEN

bin/check-changelog.php:117–121 | `entryProse()` unbalanced-backtick heuristic can misjudge references on malformed entries (odd tick counts): probed a stray-tick span swallowing an entry's only ref (flagged — fail-closed here, mirrored input would false-pass). Only reachable via malformed Markdown; coder self-documented. Acceptable lint heuristic | nit | OPEN
