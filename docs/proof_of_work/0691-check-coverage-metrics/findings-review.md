# Review findings — Issue #691

One entry per finding. Appended across rounds. Severity: `high` / `medium` /
`low` / `nit`.

## Round 1

- R1-1 | `bin/check-coverage.php` fallback path | **low** | The initial fallback
  `//file/metrics` + `[0]` reported only the first file's metrics for multi-file
  Clover without a project layer, silently underestimating coverage (regression
  vs old code that summed all file metrics). **Status: fixed** in round 1 — the
  fallback now sums all `/file/metrics` nodes; verified against a new fixture
  (`clover-files-only.xml`, 75.00%, exit 0/1) with two dedicated tests.
  Automated check that could catch this: a unit test exercising the fallback
  with a multi-file, no-project fixture asserting the aggregate equals the sum
  of all file nodes (added).

- R1-2 | `bin/check-coverage.php:62` | **nit** | Missing trailing comma in
  multi-line `printf`. Pre-existing (byte-identical to `master`); `bin/` is
  outside php-cs-fixer scope. **Status: not a new finding** — out of scope.

## Round 2

- R1-1 | **low** | **Status: fixed** — verified by running the script on
  `clover-files-only.xml` (75.00%, exit 0); the fallback sums both `/file/metrics` nodes.
  The new test fails against a `[0]`-only implementation. Closed.
- R1-2 | **nit** | **Status: not a new finding** (pre-existing, out of scope). Closed.
- Round 2 found no new findings.
