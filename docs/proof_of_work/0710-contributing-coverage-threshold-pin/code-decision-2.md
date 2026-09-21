# Code decision — #710, review round 1 answers

Round 1 found no high/medium issues: one low nit in the new test (F-1) and four
nits (F-2..F-5), plus a pre-existing low in `bin/check-coverage.php` (F-6).

## What changed

- **F-1 docblock corrected.** The docblock claimed the threshold appears
  "twice"; the guard is `assertGreaterThan(0, ...)`. The guard is deliberate:
  the drift class is a *stale figure*, and the loop asserts the value on every
  line carrying the anchor phrase, so drift in either occurrence fails. A
  legitimate consolidation of the prose to one line should stay green, so
  requiring two mentions would be over-constraining. The docblock now states
  the >=1 rule and the reasoning instead of the inaccurate "twice".

## Deliberately not changed (with reasons, recorded in findings-review.md)

- **F-2** phrase-keyed selection: the stable phrase is what lets the test assert
  every occurrence; the non-vacuity guard catches rewordings loudly.
- **F-3** duplicated regex: a shared helper couples two independent assertions.
- **F-4** per-script loop: the single-entry invariant is already pinned by
  `CoverageCiGateTest`; duplicating it adds nothing.
- **F-5** one-decimal display: matches the documented threshold form.
- **F-6** `bin/check-coverage.php` defaulting the threshold to `0.0` when
  `$argv[2]` is omitted: a real pre-existing gate bug, but outside #710 (which
  pins the prose to the composer script). Filed as a candidate issue at step 14.

## Alternatives rejected

1. **Tighten the guard to `>= 2` to match the old docblock.** Rejected: it
   would fail a valid prose consolidation and does not add drift protection
   (every phrase-bearing line is already asserted).
2. **Extract the threshold parsing into a shared test helper (F-3).** Rejected:
   two call sites, each cheap; a helper adds indirection and a shared failure
   mode.

## Uncertainties

- None. The only code change this round is a docblock on a test.
