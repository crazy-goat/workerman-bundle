# Review 1 — #710 CONTRIBUTING coverage threshold pin

Branch: `test/issue-710-contributing-md-coverage-threshold-80-is`
Diff: `tests/BinDirectoryTest.php` (+53) and
`docs/proof_of_work/0710-contributing-coverage-threshold-pin/` (new).
Reviewer: review subagent, round 1. Read-only.

## Earlier findings

`docs/proof_of_work/0710-contributing-coverage-threshold-pin/findings-review.md`
did not exist before this round (confirmed absent), so there is nothing to
adjudicate. `findings-coder.md` exists and is discussion, not a review record;
its weak-spot list is addressed below.

## Docs/helpers consulted (TAG INDEX)

Tags matching the diff (`tests`, `coverage`, `ci`, `docs`, `bin`, `phpstan`):

- **DEC-007** (`coverage,ci,policy`) — "80% line-coverage floor, single source
  of truth": `composer.json`'s `coverage:check` is the only place the threshold
  is defined; lowering it is forbidden outright. The new test *enforces* DEC-007
  against the docs and does not move or duplicate the value. **No violation.**
- **FAQ-011** (`tests,coverage,ci`, promoted) — floor defined in
  `composer.json`, asserted by `tests/CoverageCiGateTest.php`. The new test
  extends the assertion surface to `CONTRIBUTING.md`; consistent.
- **FAQ-010** (`tests,coverage`, promoted) — test-suite mechanics; unaffected.
- **FAQ-031** (`lint,bin,tests`) — `bin/` is inside linter scope. Not touched.
- **FAQ-032** (`ci,tests,process`) — only applies to `.github/workflows/*`
  diffs; this diff touches none. Not applicable.
- **FAQ-037** (`php82,ci,lint,tests`) — no new syntax; the file already uses
  `str_contains` (PHP 8.0) and named args are absent. Not applicable.
- **FAQ-014** (`tests,phpstan`), **FAQ-019** (`docs,listen-scheme`) — read,
  not relevant to doc-vs-config pinning.

No documented decision is violated by the diff.

## What was verified

- Test runs green:
  `vendor/bin/phpunit tests/BinDirectoryTest.php tests/CoverageCiGateTest.php`
  → 21 tests, 75 assertions, OK (only the pre-existing XDEBUG_MODE warning).
- `vendor/bin/phpstan analyse tests/BinDirectoryTest.php --level=8` → no errors.
- `php-cs-fixer fix tests/BinDirectoryTest.php --dry-run` → 0 of 1 files need
  fixing.
- Threshold extraction: against the current
  `["php bin/check-coverage.php var/coverage.xml 80.0"]` the regex captures
  `80.0`; normalisation `80.0 === floor(80.0)` → `"80"`, and `85.5` (not
  integral) → `number_format(..., 1)` → `"85.5"`. Both asked-for cases correct.
- Both prose occurrences are reached: `CONTRIBUTING.md:86` (`**80%**`) and
  `CONTRIBUTING.md:264` (`80%`) each contain `line-coverage threshold`, so the
  loop asserts against both, not just the first.

### Scratch mutation results (no mutation committed; `git status` clean after)

| Mutation | Result | Meaning |
|---|---|---|
| `composer.json` `80.0` → `85.5` | **FAIL** | composer change is caught |
| `CONTRIBUTING.md:86` `**80%**` → `**90%**` | **FAIL** | first occurrence drift caught |
| `CONTRIBUTING.md:264` `(80%` → `(90%` | **FAIL** | second occurrence drift caught |
| delete the whole line 86 occurrence | PASS | guard is `>= 1`, not `== 2` (see F-1) |

So the coder's "mutation-verified" claim holds for value drift in composer and
in *either* prose occurrence. The test is non-vacuous for the value it pins.

## Findings

### F-1 — `tests/BinDirectoryTest.php:200-204` — guard is `>= 1`, so deleting one of the two prose occurrences passes silently — low

The method docblock states the threshold "in prose twice", but the only
non-vacuity guard is `assertGreaterThan(0, $matchedLines)`. Scratch mutation D
above: removing the `CONTRIBUTING.md:86` sentence entirely leaves the test
green. If a later edit deletes one occurrence (as opposed to editing its
value), the doc silently loses redundancy the test's own comment assumes.

Not a correctness hole for the pinned value — a stale value in the surviving
line still fails — but the guard could assert the expected occurrence count
(or, more simply, not claim "twice" if only `>= 1` is enforced). No automated
check in the repo catches this class; it is a deliberate design decision, so
recorded as low rather than blocking.

### F-2 — `tests/BinDirectoryTest.php:190-197` — phrase-keyed line selection is brittle to benign rewording — nit

Every line containing the literal `line-coverage threshold` must also contain
`<threshold>%`. A legitimate future sentence such as "The line-coverage
threshold lives in `composer.json`" (no figure) would fail the test even though
nothing drifted; a sentence stating `80%` without the phrase would not be
pinned. The phrase does not currently appear in such a form, so this is
hypothetical. Keying on the phrase rather than line numbers (which aged from
the issue's `~78/~148` to `86/264`) is the right call per the coder's note.

### F-3 — `tests/BinDirectoryTest.php:173` — threshold regex duplicated from `tests/CoverageCiGateTest.php:71` — nit

`/check-coverage\.php\s+\S+\s+(\d+(?:\.\d+)?)/` now exists in two test classes.
If the script form changes both must move together. The coder rejected a shared
helper as cross-test coupling; that is defensible (two lines), but it is the
same duplication class the repo already struggles with for workflow YAML
(FAQ-032) and is worth a note, not a block.

### F-4 — `tests/BinDirectoryTest.php:170-176` — loop keeps the last matching script, so a second disagreeing script is invisible here — nit

`$threshold` is overwritten on each match; only `CoverageCiGateTest`
(`assertCount(1, $scripts)`) forbids a second `coverage:check` entry. Together
the two tests are sound (the coder noted this); standalone the new test would
accept a second matching line. No action needed while the count invariant
exists in the other file.

### F-5 — `tests/BinDirectoryTest.php:181-183` — `number_format(..., 1)` cannot express an arbitrary-precision threshold — nit

A future `80.25` composer threshold would render `"80.3"` and fail even if
`CONTRIBUTING.md` correctly says `80.25`. The issue explicitly specifies one
decimal and failing loudly is preferable to silently matching a prefix, so this
is accepted; recorded only so the limitation is on the record.

### F-6 (out of scope, pre-existing) — `bin/check-coverage.php:22` — missing `$argv[2]` defaults to `0.0` — low

A direct invocation without the threshold passes unconditionally. The new test
guards the `composer.json` script, and `CoverageCiGateTest` forbids CI invoking
the script directly, so the practical exposure is an operator/future edit. The
coder flagged this; it predates the diff and must not be fixed inside this PR.
Propose a separate issue: make the threshold argument required (exit 2 when
absent) rather than defaulting to `0.0`.

## Verdict

The diff does what #710 asked: it extracts the threshold from `composer.json`,
normalises `80.0 -> "80"` / `85.5 -> "85.5"`, and catches value drift in the
config and in either of the two `CONTRIBUTING.md` prose occurrences (verified
by scratch mutation). `tests/BinDirectoryTest.php` is the correct home, matching
the #693 precedent it cites. No high or medium findings; F-1 is the only
finding worth considering fixing in this PR.

## Candidate docs/helpers entries (proposal — retro decides)

1. **Title:** Doc numeric prose pinned to config must key on a phrase, not line
   numbers. **Tags:** `tests,docs,coverage`. **Trigger:** "pinning a numeric
   figure in a markdown doc against a config file". **Body:** Issue bodies
   cite line numbers that age (the #710 issue said lines ~78/~148; the text was
   at 86/264). A pinning test should match the stable prose phrase
   (`line-coverage threshold`) and assert the figure inside those lines; a
   whole-file `assertStringContainsString` only proves one occurrence matches
   and lets a second stale occurrence through (#710, #693).
2. **Title:** Coverage threshold prose form drops the trailing `.0`.
   **Tags:** `coverage,tests,docs`. **Trigger:** "changing the coverage
   threshold in composer.json". **Body:** `CONTRIBUTING.md` writes `80%` while
   `composer.json` writes `80.0`; the pinning test normalises integral values
   to no decimal and non-integral values to one decimal, so a non-one-decimal
   threshold needs a deliberate decision. DEC-007 still makes `composer.json`
   the single source of truth.
