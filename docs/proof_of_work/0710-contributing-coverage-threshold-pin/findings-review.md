# Findings — Review (#710)

Round 1. New file: no earlier `findings-review.md` existed, so every entry
below is new. Format: file:line | what is wrong | severity | what happened to it.

- `tests/BinDirectoryTest.php:200-204` | Non-vacuity guard is `assertGreaterThan(0, $matchedLines)` while the docblock claims the threshold appears "twice"; deleting one prose occurrence (scratch mutation D) leaves the test green. Value drift in the *surviving* occurrence is still caught. | low | **deliberately not fixed (guard); docblock corrected** — the drift class is a stale *figure*, and every phrase-bearing line is still asserted, so drift in either occurrence fails. Requiring two mentions would make a legitimate prose consolidation fail for no correctness reason; the docblock now states the >=1 rule and why. |
- `tests/BinDirectoryTest.php:190-197` | Phrase-keyed selection is brittle: any future line containing `line-coverage threshold` without `<threshold>%` fails even without drift, and a value stated without the phrase is not pinned. Hypothetical today. | nit | **deliberately not fixed** — keying on a stable anchor phrase is what lets the test iterate *every* occurrence instead of one hardcoded line; a reword that drops the phrase trips the non-vacuity guard loudly rather than passing silently. |
- `tests/BinDirectoryTest.php:173` | Threshold regex duplicated from `tests/CoverageCiGateTest.php:71`; the two can diverge if the script form changes. Coder consciously rejected a shared helper (self-contained test). | nit | **deliberately not fixed** — a two-line regex shared via a test helper would couple two independent doc/gate assertions; each failing on its own is clearer. |
- `tests/BinDirectoryTest.php:170-176` | Loop overwrites `$threshold` per matching script, so a second disagreeing `coverage:check` entry is invisible *in this test*; the single-entry invariant lives only in `CoverageCiGateTest::testComposerCoverageCheckDefinesNonZeroThreshold`. Together sound. | nit | **not a defect** — the single-entry invariant is already enforced by `CoverageCiGateTest`; duplicating it here adds no coverage. |
- `tests/BinDirectoryTest.php:181-183` | `number_format($threshold, 1)` cannot represent a non-one-decimal threshold (`80.25` renders `80.3`), so such a doc value would fail. Accepted per the issue's one-decimal spec; noted for the record. | nit | **deliberately not fixed** — the threshold is a line-coverage percentage where one decimal is the documented form; a 2-decimal threshold is out of spec. |
- `bin/check-coverage.php:22` | Pre-existing, out of scope: missing `$argv[2]` defaults the threshold to `0.0`, so a direct invocation passes unconditionally. The new test guards the composer script and CI is forbidden from calling the binary directly. Coder already flagged it; propose a separate issue (require the argument / exit 2). | low | **not fixed (pre-existing, out of scope)** — candidate issue at step 14. |

## Disagreements / coder weak spots adjudicated

- Coder weak spot 1 (regex duplication): confirmed as F-3, nit.
- Coder weak spot 2 (count invariant only in CoverageCiGateTest): confirmed as F-4, nit — not a defect because the count is enforced elsewhere.
- Coder weak spot 3 (implicit prose display convention): confirmed as F-5, nit.
- Coder weak spot 4 (check-coverage.php `$argv[2]` default): confirmed, but pre-existing/out of scope — recorded as low, do not fix in this PR.

## Gate status

Not lowered. PHPStan level 8 on the changed file: clean. php-cs-fixer: clean.
`tests/BinDirectoryTest.php` + `tests/CoverageCiGateTest.php`: 21 tests, 75
assertions, OK. Scratch mutations were reverted and `git status` is clean;
nothing committed by review.
