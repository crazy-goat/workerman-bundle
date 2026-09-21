# Review 2 — #710 CONTRIBUTING coverage threshold pin

Branch: `test/issue-710-contributing-md-coverage-threshold-80-is`
Diff: `git diff master...HEAD` → `tests/BinDirectoryTest.php` (+58 net) and
`docs/proof_of_work/0710-contributing-coverage-threshold-pin/` (new).
Round 2 commit under review: `2b3504a` — docblock only
(`tests/BinDirectoryTest.php` 7 lines: +6/−1), no logic change.
Reviewer: review subagent, round 2. Read-only; nothing committed.

## Docs/helpers consulted (TAG INDEX)

Tags matching the diff (`tests`, `coverage`, `docs`, `bin`): **DEC-007**
(`coverage,ci,policy` — composer `coverage:check` is the single source of the
80% floor; lowering forbidden), **FAQ-011** (`tests,coverage,ci` — floor
asserted in `tests/CoverageCiGateTest.php`), **FAQ-010** (`tests,coverage`),
**FAQ-031** (`lint,bin,tests` — `bin/` in linter scope). FAQ-032 applies to
`.github/workflows/*` diffs only; this diff touches none. No documented
decision is violated: the test enforces DEC-007 and does not move or duplicate
the threshold. Same conclusion as round 1.

## Earlier findings — adjudication

`findings-review.md` round 1 (F-1..F-6) read first; every entry adjudicated
against current lines. No finding was deleted.

- **F-1 — `tests/BinDirectoryTest.php:154-164` (docblock) / `:205-209`
  (guard) — FIXED (acceptably).** The docblock no longer claims the threshold
  appears "twice"; it now (a) drops the word "twice", (b) states that every
  phrase-bearing line is asserted, and (c) states the guard requires at least
  one mention, with the consolidation rationale. The guard is unchanged and
  still `assertGreaterThan(0, $matchedLines)` (lines 205-209), so docblock and
  code now agree. The reasoning is sound: the pinned defect class is a *stale
  figure*, and scratch mutation in round 1 already showed drift in either of
  the two prose occurrences (`CONTRIBUTING.md:86`, `:264`) fails the test;
  raising the floor to `>= 2` would fail a legitimate prose consolidation
  without adding drift protection. Accept the non-fix of the guard; the
  round-1 low was only about the inaccurate docblock, which is gone.
- **F-2 — `tests/BinDirectoryTest.php:194-195` — still present, accepted nit.**
  Selection is still keyed on the literal `line-coverage threshold`, and a
  phrase-bearing line without `<threshold>%` would still fail. Still
  hypothetical today (both occurrences carry `80%`). Accept: the phrase is the
  stable anchor that lets the loop cover *every* occurrence instead of one
  hardcoded line, and a reword that drops the phrase trips the non-vacuity
  guard loudly rather than silently. Non-fix is a defensible test-design
  trade-off, not a correctness gap.
- **F-3 — `tests/BinDirectoryTest.php:178` — still present, accepted nit.**
  `/check-coverage\.php\s+\S+\s+(\d+(?:\.\d+)?)/` still duplicates the parse in
  `tests/CoverageCiGateTest.php` (that test's gate at lines 66-75). Accept: two
  call sites, each two lines; a shared test helper would couple a doc assertion
  to a CI-gate assertion and give them one shared failure mode. Not worth a
  helper.
- **F-4 — `tests/BinDirectoryTest.php:175-181` — still present, not a defect.**
  The loop still overwrites `$threshold` per match; the single-entry invariant
  lives in `CoverageCiGateTest::testComposerCoverageCheckDefinesNonZeroThreshold`
  (`assertCount(1, $scripts)` at `tests/CoverageCiGateTest.php:68`) and is
  verified to still be there. Together the two tests are sound; duplicating the
  count here adds no coverage.
- **F-5 — `tests/BinDirectoryTest.php:184-188` — still present, accepted nit.**
  `number_format($threshold, 1, '.', '')` still cannot represent a two-decimal
  threshold (`80.25` → `"80.3"`). Matches the issue's one-decimal spec and the
  documented prose form; a 2-decimal threshold would need a deliberate change
  here. Out of scope.
- **F-6 — `bin/check-coverage.php:21` (round 1 wrote `:22`; the assignment is
  on line 21) — still present, pre-existing, deferral acceptable.** Unchanged:
  `isset($argv[2]) ? (float) $argv[2] : 0.0`, so a direct invocation without
  the threshold passes unconditionally. It is not in this diff, the new test
  guards the composer script, and `CoverageCiGateTest` forbids CI calling the
  binary directly. Correctly filed as a candidate issue for step 14 and must
  not be fixed inside #710.

All six are adjudicated; F-1 is genuinely fixed, F-2..F-5 are acceptable nits
with recorded reasoning, F-6 is a correctly deferred pre-existing low.

## New issues (round 2)

**None.** The only source change since round 1 is the F-1 docblock, and the
round-2 commit touches no executable line of the test (confirmed via
`git show 2b3504a -- tests/BinDirectoryTest.php`: +6/−1 inside the docblock).
The remaining diff is proof-of-work markdown.

One nuance deliberately *not* raised as a new finding: the corrected docblock
says "every line naming the threshold is checked", which is strictly "every
line carrying the anchor phrase" — a figure stated without the phrase is not
pinned. That is exactly F-2, already recorded and accepted as a nit; I am not
re-reporting it under a new id.

## Verification run this round

- `vendor/bin/phpunit tests/BinDirectoryTest.php tests/CoverageCiGateTest.php`
  → **21 tests, 75 assertions, OK** (only the pre-existing XDEBUG_MODE warning).
- `bin/phpstan analyse tests/BinDirectoryTest.php` (level 8 from
  `phpstan.neon.dist`) → **No errors**.
- `php-cs-fixer fix tests/BinDirectoryTest.php --dry-run` → **0 of 1 files**
  need fixing.
- `git status` clean; nothing committed by review.

Gates were not lowered; no coverage floor, PHPStan level or lint rule was
touched.

## Verdict

**Clean — ready to ship.** Round 1's only non-nit finding (F-1, docblock now
matches the `>= 1` guard) is fixed; F-2..F-5 are accepted nits with sound
reasoning; F-6 is a correctly deferred pre-existing issue for step 14. Test
satisfies DEC-007 and extends FAQ-011's assertion surface to `CONTRIBUTING.md`;
no new findings.

## Candidate docs/helpers entries (carried from round 1; retro decides)

1. **Title:** Doc numeric prose pinned to config must key on a phrase, not line
   numbers. **Tags:** `tests,docs,coverage`. **Trigger:** "pinning a numeric
   figure in a markdown doc against a config file". **Body:** Issue bodies cite
   line numbers that age (#710 said lines ~78/~148; text was at 86/264). Match
   the stable prose phrase and assert the figure on those lines; a whole-file
   `assertStringContainsString` proves only one occurrence matches and lets a
   second stale occurrence through (#710, #693).
2. **Title:** Coverage threshold prose form drops the trailing `.0`.
   **Tags:** `coverage,tests,docs`. **Trigger:** "changing the coverage
   threshold in composer.json". **Body:** `CONTRIBUTING.md` writes `80%` while
   `composer.json` writes `80.0`; the pinning test normalises integral values to
   no decimal and non-integral values to one decimal, so a non-one-decimal
   threshold needs a deliberate decision. DEC-007 keeps `composer.json` the
   single source of truth.
