# review-2.md — #654 round 2 (commit 27cbe28)

Reviewer: review-critical, round 2. Diff under review: `c54cde4..27cbe28`.
Round-1 findings file read first; per-finding dispositions below (also appended
to [findings-review.md](findings-review.md)).

## Verdict

**APPROVE.** All eight round-1 findings are addressed: the two mediums are
fixed with fixtures that pin exactly the regressions I named, the lows are
either fixed or narrowed to documented latent nits, and nothing regressed.
Four new (low/nit) findings, all of the same latent-edge-case class as the
round-1 lows — none blocks merge.

## Round-1 dispositions (evidence for each)

1. **Equality boundary unpinned (medium) — FIXED.**
   `testDuplicateReleasedVersionHeadingsFail` uses `## [0.2.0] - 2025-01-01`
   twice and asserts `line 15: version 0.2.0 is not strictly older than`; the
   fixture comment states explicitly that relaxing `version_compare(...) >= 0`
   to `> 0` breaks the suite. Verified present in the diff and green in the
   run.
2. **Fixture gaps (medium) — FIXED.** All four named behaviors now have
   fixtures: `testAFileWithNoVersionHeadingsAtAllFails` (asserts both the
   no-headings and found-0 messages), `testAnOnlyUnreleasedFileFailsForWantOfR$
   eleasedHeadings` (coder F-6 behavior now pinned as deliberate),
   `testAnUnknownSubheadingIsSilentlySkipped` (silent skip pinned as documented
   scope: custom sections neither fail nor join duplicate detection),
   `testAReferenceOnAContinuationLineIsAccepted`. Residual nit: a `--help`/`-h$
   ` exit-0 fixture still does not exist (was a sub-item of this finding);
   recorded as leftover, not blocking.
3. **Fences not skipped (low) — FIXED for ``` fences.**
   `outsideFences()` blanks fenced lines and markers 1:1 (array keys
   preserved → line numbers survive; verified by the line-numbered assertions
   in the new fixtures). Probed: released heading only inside a fence → fails
   "no released version headings"; one real + one fenced `### Fixed` → passes;
   two real + one fenced → fails with exactly "has 2". **Partially:** tilde
   fences (`~~~`) are still invisible to the sanitizer → new finding NF-1.
4. **Reference false positives (low) — FIXED.** Rule 4 now matches prose only:
   `entryProse()` strips inline-code spans, then `\]\(#\d+\)` anchor links are
   removed before the reference regex runs. Fixtures pin both shapes I probed
   in round 1 (backticked `` `(#123)` `` → fail; `[x](#123)` anchor → fail).
   Canonical forms verified unaffected: real-tree check OK, continuation-line
   link form accepted, bare `(#N)` accepted (pre-existing fixture).
5. **Global symbol collisions wider than F-1 (low) — FIXED for this script.**
   Bootstrap trio renamed to `checkChangelogMain()` / `checkChangelogParseArgs()$
   ` / `checkChangelogPrintUsage()`; grep confirms every function/const in
   bin/check-changelog.php is now unique to that file. The remaining
   kb-lint ↔ pick-issue collisions (`main`, `parseArgs`, `printUsage`) are
   pre-existing and were declared out of scope for this issue — noted, not
   charged to this diff.
6. **PoW misstatement about sibling conventions (low) — FIXED.**
   code-decision-1.md now states accurately that kb-lint declares `main(): int$
   ` (exits internally on every path, declared return never observed),
   pick-issue's void main returns normally on success, and the actual PHPStan
   fix was dropping `exit(...)` around the bare call.
7. **Date shape-only validation (nit, part a) — FIXED.** Released-heading
   regex now captures the date and `checkChangelogIsIsoDate()` re-checks shape
   plus `checkdate()` with correct argument order `(month, day, year)`;
   `testAnImpossibleCalendarDateFails` pins `2026-13-45` rejection with the
   ISO message. The dead "unable to extract the version" branch was eliminated
   as a side-effect of merging the two regexes — good.
8. **Date monotonicity vs version order (nit, part b) — ANSWERED, deliberate
   non-fix.** Coder's rationale (needs a decision on backdated patch releases;
   would be a new rule, not a refactor) is sound. Recorded in findings-coder.md$
   ` as possible follow-up. Accepted.
9. **Trailing-space `[Unreleased]` diagnostics (nit) — FIXED.**
   `versionHeadings()`/`versionBlocks()` rtrim captured headings;
   `testATrailingSpaceOnTheUnreleasedHeadingIsTolerated` pins the pass.
   Side-effect verified by probe: trailing space on *released* headings is now
   also tolerated instead of failing the shape regex — consistent loosening
   (Markdown ignores trailing whitespace), acceptable. Residual one level
   down: subheading capture still doesn't rtrim → new finding NF-3.

## New findings (round 2)

- **NF-1 (low)** — bin/check-changelog.php:93–110 — `outsideFences()` toggles
  only on ``` markers; CommonMark also allows `~~~` fences. Probed: a file
  whose only released heading sits inside `~~~ … ~~~` PASSES with the phantom
  heading counted (exact round-1 F-R2 scenario, one syntax over). Latent
  (real CHANGELOG.md has no fences of either kind). Catcher: fixture +
  extending the toggle test to `/^(```|~~~)/`.
- **NF-2 (nit)** — bin/check-changelog.php:93–110 — an unterminated ```
  fence blanks the rest of the file, so the lint fails closed but with generic
  messages ("no released version headings") that never mention the unclosed
  fence (probed). Correct outcome, misleading diagnosis. Catcher: track
  fence state at EOF and emit "unterminated code fence starting at line N".
- **NF-3 (nit)** — bin/check-changelog.php:304–325 — subheading capture
  (`### (.+)$`) is not rtrimmed, so `### Fixed ` (trailing space) is silently
  treated as an unknown subheading and escapes duplicate detection — a
  whitespace dodge vector, and the same diagnostic class round 1 flagged for
  `## [Unreleased] `. Self-reported by the coder in the round-2 notes; left
  out of scope there, recorded here so it is not lost. Catcher: trim the
  capture + fixture.
- **NF-4 (nit)** — bin/check-changelog.php:117–121 — the unbalanced-backtick
  heuristic in `entryProse()` can misjudge references on malformed entries:
  probed both directions (a stray tick whose span swallows an entry's only
  `(#901)` produced a true positive here, but the mirrored input — ref after
  the span closes — would false-pass). Only reachable with malformed Markdown
  (odd/unbalanced ticks); coder self-documented the risk. Acceptable for a
  lint; remember if a false pass is ever reported. Catcher: none practical
  beyond full inline-code parsing — not worth it.

## Judgment-call assessments (requested)

- **One-real-plus-one-fenced `### Fixed` passes:** correct resolution of the
  ambiguous round-1 instruction. With the fence copy ignored, one real
  subheading cannot be a duplicate; both readings are now pinned by fixtures
  (`…OneRealSubheadingPasses` and `…StillCaughtWhenACopyHidesInAFence` with
  exact "has 2"). The fenced copy neither hides nor inflates. Agreed.
- **Date short-circuit ordering:** when a released heading has an impossible
  date the script reports only the date violation and `continue`s *before*
  updating `$previousVersion` — so that heading's version escapes the ordering
  chain entirely (probed: bad-date 0.3.0 between good headings yields exactly
  one violation). Defensible: it avoids cascading noise from an already-flagged
  heading, and the date must be fixed first anyway. Residual: a hostile
  `## [9.9.9] - 2026-13-45` placed out of order is flagged only for the date —
  still flagged, so fail-closed. Accept.

## Gates executed locally (all green)

- `php bin/check-changelog.php` on the real tree → OK, exit 0.
- `vendor/bin/phpunit --filter ChangelogStructureTest` → 30/30 green (14 prior
  + 12 new + 4 repo metadata tests), 163 assertions.
- `vendor/bin/phpstan analyse bin/check-changelog.php tests/ChangelogStructureTest.php$
  ` → no errors.
- `vendor/bin/php-cs-fixer fix --dry-run --config=…` on both files → clean.
- Full `composer lint` end-to-end → green (only the pre-existing faq.md budget
  advisory, coder F-3).
- Symbol-uniqueness sweep across `bin/*.php`: every check-changelog symbol
  declared in exactly one file.

No commits made; docs/helpers untouched.
