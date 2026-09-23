# Review round 1 — #760

## Prior findings

No prior unresolved findings; initial review round.

## Diff under review

- `.github/workflows/tests.yaml`: new `tests-root-permissions` job; `ci` aggregator `needs` extended and gated on `needs.tests-root-permissions.result` for non-scheduled, non-docs-only runs; `detect-changes` comment updated.
- `tests/GithubWorkflowsTest.php`: new guard pinning the root job's `sudo` invocation, its skip-detection grep, and its presence in the aggregator.
- `CHANGELOG.md`: Unreleased entry.

## Review

- **Scope / fail-open:** the privileged leg runs only the three integration cases; the pure `checkCacheFilePermissions()` decision tests still run unprivileged in the matrix, so no existing coverage is removed and no privilege is granted to unrelated tests.
- **No false green:** the step fails if PHPUnit exits non-zero, if the summary shows a skip (`some tests were skipped` or `Skipped: [1-9]`), or if the expected three tests did not run. PHPUnit 10's clean summary is `OK (3 tests, N assertions)` — a shape the first draft did not accept; the final guard accepts both shapes. Verified locally against simulated clean/skip outputs.
- **Aggregator semantics:** `ci` (`if: always()`) requires the new job only when the event is not `schedule` and the change is not docs-only, matching the job's own `if`. Scheduled runs still rely on `tests-scheduled`, unchanged. Docs-only PRs stay green. The existing `tests`/`benchmark`/coverage regexes in `GithubWorkflowsTest`/`CoverageCiGateTest` are unaffected (verified by running both classes).
- **Security:** running `vendor/bin/phpunit` as root is confined to the CI runner and to a filtered test class; no repository or artifact is produced from it.
- **Test-suite behaviour:** the earlier test-class fail-on-skip switch was removed; `tests/ConfigLoaderTest.php` is back to its original content (only the workflow and workflow-test changed).

## Checks

- `tests/GithubWorkflowsTest.php` + `tests/CoverageCiGateTest.php` + `tests/Command/BuildPharCommandTest.php` — passed (the FAQ-032 sweep for this workflow diff).
- `tests/ConfigLoaderTest.php` — 40 tests, 3 expected local skips (non-root host); unchanged.
- `composer lint` — passed (existing knowledge-base line-budget warnings only).
- Workflow YAML parses; changelog check and `git diff --check` pass.
- Not verifiable locally: actual privileged execution (no passwordless root here). The new CI job performs it; if it regresses, the job fails.

## Findings

None. A candidate knowledge-base entry ("PHPUnit 10 clean vs skipped summary shapes") is not needed — the guard encodes it inline with a comment, and a KB entry would duplicate the workflow comment.
