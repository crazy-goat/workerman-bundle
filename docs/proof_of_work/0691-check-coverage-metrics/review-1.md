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
