# Review Round 1 — Issue #691

Reviewer role: automated review (`reviewer` subagent), read-only.

## Scope

`bin/check-coverage.php`, `tests/CheckCoverageGateTest.php`,
`tests/Fixtures/clover-small.xml` — change fixes `//metrics` summation to read
the single project-level aggregate, falling back to file metrics.

## Earlier-round findings

Round 1 — no earlier findings to reconcile (`findings-review.md` did not exist).

## Findings

| file:line | description | severity | status |
| --- | --- | --- | --- |
| `bin/check-coverage.php` (fallback) | Initial fix's fallback `//file/metrics` + `[0]` picked only the **first** file's metrics, silently under-reporting multi-file Clover without a project layer (a regression vs the old code which summed all file metrics). | low | **fixed** — fallback now sums all `/file/metrics` nodes; verified against `tests/Fixtures/clover-files-only.xml` (75.00%, exit 0/1 at thresholds) and dedicated tests. |
| `bin/check-coverage.php:62` | Missing trailing comma in multi-line `printf`. Pre-existing (byte-identical to `master`); `bin/` is outside php-cs-fixer scope. | nit | not a new finding — left as-is (out of scope) |

## Non-findings (checked, no defect)

- `$aggregate[0]` "offsetAccess.notFound" PHPStan warning — false positive; guard ensures non-empty array. `bin/` is not in PHPStan paths anyway.
- `$argc`/`$argv` PHPStan "might not be defined" — pre-existing, safe at runtime.
- Division by zero — guarded by existing `$totalStatements === 0` exit(2).
- Missing attribute (`?? '0'`) — preserved pre-existing behavior.
- Exit codes 0/1/2 and output format unchanged.

## Verdict

Change correctly and completely fixes issue #691 for real PHPUnit Clover output.
The regression test + fixture genuinely pin the old bug. No blocking issues.
Fallback edge case was the only real finding and has been fixed and tested.

# Review Round 2 — Issue #691

Automated review (`reviewer` subagent), read-only.

## Earlier-round findings

- **R1-1 (low, fallback `[0]`-only) — FIXED.** Verified by running the script on
  `clover-files-only.xml`: `Coverage: 75.00% (75/100 statements)`, exit 0 — the fallback
  sums both `/file/metrics` nodes (60+40=100, 40+35=75). Simulating the old `[0]`-only
  behaviour on the same fixture yields `40/60 = 66.67%`, so the new test genuinely fails
  against the old code. `clover-no-metrics.xml` has no `<metrics>` anywhere and triggers
  exit 2 as expected.
- **R1-2 (nit, trailing comma) — NOT A NEW FINDING.** Byte-identical to master; out of
  scope; unchanged.

## New findings

None that change behavior. Fallback double-counting checked (`//file/metrics` selects only
file-level nodes, no inflation); primary `/coverage/project/metrics` correctly prefers the
single aggregate; `statements=0` guard intact.

## Test run

`vendor/bin/phpunit --no-coverage tests/CheckCoverageGateTest.php` → OK (7 tests, 33
assertions). `php -l bin/check-coverage.php` → no syntax errors.

## Verdict

Complete and correct for issue #691. Round-1 fallback defect genuinely fixed, fixtures/tests
valid, full test file passes. No blocking issues.
