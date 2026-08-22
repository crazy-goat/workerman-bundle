# Findings — Review (#619)

Appended across review rounds. One entry per finding: `file:line`, what is
wrong, severity, and what happened to it.

## Round 1

No findings opened in round 1. The implementation is correct across all
checked dimensions (GitHub Actions skip-propagation, ci aggregator behavior,
shell classify edge cases, regex pins, lint/test results).

### Notes (not findings, recorded for transparency)

- **CHANGELOG.md:Unreleased** — says `docs/**` while the actual shell case
  pattern is `docs/*`. In shell case globbing `*` matches `/`, so they are
  functionally equivalent. Severity: nit. Status: not a real finding
  (cosmetic imprecision, no behavior difference).
- **findings-coder.md** — test count reported as 2338/17053 vs post-commit
  2342/17065. Drift is the 4 new/modified assertions from the test changes.
  Severity: nit. Status: not a real finding (stale count, not a code defect).
