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

---

# Round 3 — dispositions for round-2 findings (commit 03d6543)

bin/check-changelog.php:86–128 (NF-1, tilde fences) | FIXED — `outsideFences()` tracks the opening marker and closes only on the same one; fixture `testATildeFencedHeadingNeverCountsAsStructure` pins the original scenario, `testABacktickFenceIsNotClosedByATildeMarker` pins mixed markers, and the reversed mix (~~~ swallowing ```) verified by probe. Residual refinement of the same feature → new NF-5 | low | FIXED

bin/check-changelog.php:86–128 + validateChangelogLines short-circuit (NF-2, unterminated fence) | FIXED — single violation `line N: unterminated code fence opened here — nothing after this line was checked`; fixture asserts message and absence of generic messages. Probes confirm fail-closed exit 1, no stale state across sequential closed fences, and the documented tradeoff that pre-fence violations are suppressed until the fence is fixed (accepted) | nit | FIXED

bin/check-changelog.php:350–355 (NF-3, subheading rtrim) | FIXED for trailing whitespace — capture rtrimmed; editor-proof fixture injects trailing spaces via preg_replace so whitespace-stripping settings cannot defuse it. Leading-whitespace variant persists → new NF-6 | nit | FIXED

bin/check-changelog.php:117–121 (NF-4, entryProse heuristic) | ACCEPTED AS DOCUMENTED per round-2 disposition; findings-coder.md note stands as documentation of record | nit | ACCEPTED

## Round 3 — new findings

bin/check-changelog.php:86–128 | Fence model ignores CommonMark's "closing fence at least as long as opener": in a 4-backtick fence containing ``` lines (standard nested-fence idiom), the inner ``` prematurely closes and later content leaks into the parse — probed a `## [0.9.9] - 2020-01-01` line inside a ```` fence counted as a real released heading (file passed with zero actual releases). Fail-open but requires exotic input for a changelog | nit | OPEN

bin/check-changelog.php:350–355 | Subheading fix rtrims only: extra leading spaces (`###   Fixed`) render as "Fixed" in Markdown but are still treated as unknown here, so the duplicate-detection dodge remains open against leading whitespace (probed: `###   Fixed` + `### Fixed` passes). Catcher: trim()/`^###\s+(.+?)\s*$` + fixture | nit | OPEN

---

# Round 4 — dispositions for round-3 findings (commit 3ffb7cc)

bin/check-changelog.php:108–127 (NF-5, closer-length rule) | FIXED — opener captures the full marker run (`{3,}`), closer requires a same-char run ≥ opener length with only trailing whitespace (`^{char}{len,}\s*$`, which also correctly forbids closer info strings per CommonMark). Fixture pins the nested idiom; re-probed round-3's exact false-pass now fails "no released version headings"; edges probed correct: trailing tab closes, info-string line inside fence does not close, longer same-char closer closes, 2-backtick content lines do not close | nit | FIXED

bin/check-changelog.php:350–355 (NF-6, leading-whitespace subheadings) | FIXED — capture uses trim(); fixture injects `###   Fixed` via str_replace and asserts "has 2"; spaces-only capture degrades to "" and is skipped harmlessly | nit | FIXED

tests/ChangelogStructureTest.php (--help carried nit) | FIXED — `testHelpExitsZeroAndPrintsUsage` covers both `--help` and `-h`: exit 0, usage line, `--root=DIR`, env-var name on stdout. Nothing left of the round-1 medium finding | nit | FIXED

## Round 4 — new findings

bin/check-changelog.php:108–127 | Fence toggling runs on trim($line), so a ``` / ~~~ marker indented 4+ spaces — an indented code block per CommonMark, not a fence — still toggles scanner state; structure between two such markers is blanked although CommonMark renders it outside any fence (probed benign direction; misbehavior direction is real but contrived for a changelog) | nit | OPEN

bin/check-changelog.php (versionHeadings/versionBlocks) | `## [` heading detection anchors to column 0, so an ATX heading indented up to 3 spaces — rendered as a real heading by Markdown — is invisible to every heading rule: probed `   ## [Unreleased]` → false "found 0" (fail-closed); an indented released heading would likewise escape ordering/duplicate-version checks (fail-open dodge). Same class as NF-6, one level up. Catcher: allow ≤3 leading spaces in heading matchers + fixtures | nit | OPEN
