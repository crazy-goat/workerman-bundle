# Review Round 1 — #619

**Branch:** process/issue-619-skip-full-lint-test-matrix-for-docs-only
**Commit:** 4a67d42
**Reviewer:** review subagent (round 1)

## KB entries read (tag-index guided)

- FAQ-032 (ci,tests,process): multiple test files pin the same workflow YAML — swept with `grep -rl 'tests.yaml' tests/`, found exactly `tests/GithubWorkflowsTest.php` and `tests/CoverageCiGateTest.php`. Both run.
- FAQ-033 (ci,github-actions): concurrency group per-ref + cancel-in-progress scoped to pull_request. Unchanged in this diff — still correct.
- FAQ-034 (ci,yaml,tests): `on:` read as boolean `true` by YAML 1.1. Tests use regexes on raw text — unaffected.
- DEC-007 (coverage,ci,policy): 80% floor in composer.json only — not touched. ✓
- DEC-008 (lint,git-hooks,policy): `composer lint` is canonical — not bypassed. ✓

## Tests run

| Command | Result |
| --- | --- |
| `php vendor/bin/phpunit tests/GithubWorkflowsTest.php tests/CoverageCiGateTest.php` | 14 tests, 66 assertions, OK (exit 0; exit 1 is the no-coverage-driver warning only) |
| `composer lint` | exit 0 — php-cs-fixer, phpstan, rector, kb-lint, check-changelog all clean. One pre-existing kb-lint warning about faq.md line budget, unrelated to this diff. |
| `composer test` (full suite, run by coder) | 2342 tests, 17065 assertions, 32 skipped, exit 0 |

## Findings

**No open findings.** The implementation is correct.

### Correctness analysis

1. **Skip-propagation gotcha (FAQ-033 adjacent):** `detect-changes` has `needs: lint` and no event-level `if`, so it runs on **every** trigger (pull_request, push, schedule, workflow_dispatch). It is never skipped, so `tests` (`needs: [lint, detect-changes]`) and `benchmark` never inherit a skip from it. On non-PR events the classifier sets `docs-only=false` and exits 0, so the heavy jobs run normally. ✓

2. **`tests`/`benchmark` conditional:** `if: github.event_name != 'schedule' && needs.detect-changes.outputs.docs-only != 'true'` — on a docs-only PR, `docs-only` is `'true'`, so the condition is false and the jobs skip. On a non-docs PR, `docs-only` is `'false'`, condition is true, jobs run. On schedule, first clause is false. On push/dispatch, `docs-only` is `'false'`. All correct. ✓

3. **`ci` aggregator:** `if: always()` so it runs regardless. New logic checks `docs_only` first; if `'true'`, exits 0 (green). Otherwise falls through to the existing tests/tests-scheduled check. When `detect-changes` itself **fails**, `ci` still runs (always()), `docs_only` is empty (not `'true'`), falls through, `needs.tests.result` is `skipped`/`failure` → exit 1 → RED. Correct — a classifier failure is not silently green. ✓

4. **Shell classify step:** `set -euo pipefail`; non-PR → `docs-only=false`, exit 0. Empty `base.sha` → merge-base fallback against `origin/${{ github.base_ref }}`; if still empty → `docs-only=false`. Empty `files` → `docs-only=false`. Case pattern `docs/*|*.md|*.mdx` — in shell case globbing `*` matches `/`, so `docs/foo/bar.md` matches `docs/*`. `.github/workflows/tests.yaml` matches none → `docs_only=false` → full matrix runs. ✓

5. **Template expressions on non-PR events:** `${{ github.event.pull_request.base.sha }}` evaluates to empty string on non-PR events (not an error); those lines are after `exit 0` so never executed. Safe. ✓

6. **Regex pins:** all existing regexes still match (the `on:` block is unchanged). Two regexes in `testScheduledRunsTrimTheMatrixToASingleLeg` updated to pin the new `needs: [lint, detect-changes]` and new `if` lines. New test `testDocsOnlyChangeSkipsHeavyJobsButKeepsLintAndCi` pins the detect-changes job, the case pattern, the non-PR guard, and the ci aggregator's docs-only green path. All pass. ✓

7. **Lint still runs on `.github/**` changes:** lint has no `if` condition — it always runs. A workflow YAML change is not classified as docs-only (case pattern doesn't match `.github/`), so even the heavy jobs run. ✓

8. **`fetch-depth: 0`** on detect-changes checkout: needed for `git diff` against base sha and merge-base. Correct. ✓

### Notes (not findings)

- CHANGELOG says `docs/**` while the actual shell pattern is `docs/*`; in shell case globbing `*` matches `/` so they are functionally equivalent. Cosmetic imprecision, not a behavior defect — nit, not worth a fix.
- Coder's `findings-coder.md` reports 2338 tests / 17053 assertions; the post-commit run shows 2342 / 17065. The drift is the 4 new/modified assertions in the test file — the coder's count was from an earlier state. Not a defect.

## Conclusion

Round 1 is **clean** — no open findings. The implementation correctly skips the heavy jobs for docs-only PRs while keeping lint and the `ci` aggregator green, and the test pins are updated to match the new intended structure.
