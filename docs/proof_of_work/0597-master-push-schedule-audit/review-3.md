# Review Round 3 — issue #597

**Branch:** `process/issue-597-workflow-runs-on-pull-request-only-maste`
**Reviewer:** review-critical (round 3)
**Date:** 2026-08-17
**Base:** `origin/master`
**HEAD:** `8a7d1e7`

## Scope

Round 3 re-verifies the two findings round 2 left open (F-5, F-6) against
the fix commit `8a7d1e7`, confirms the F-2 single-leg guard survived the
fix commit's "briefly dropped and restored" edit by reading the **current**
`tests/GithubWorkflowsTest.php` (not the commit message), and then hunts
for new issues across `origin/master...HEAD`.

## Earlier findings — disposition

Every finding recorded in `findings-review.md` is accounted for below.
Rounds 1–2 closed F-1, F-2, F-3, F-4 (F-4 accepted as documented). This
round dispositions the two that round 2 left open and re-confirms the
closed ones were not reopened by the restore.

### F-1 — docs/workflow.md:423 — stale step-10 CI description — **STILL FIXED**

Re-read `docs/workflow.md` lines 423–445. Step 10 still lists five jobs
(lint, tests matrix, tests-scheduled, benchmark, ci), the full trigger set
(pull requests, pushes to `master`, weekly schedule, `workflow_dispatch`),
the schedule-only `tests-scheduled` leg, the issue opener on scheduled
failures, and the no-cancel policy for `master` runs. Every claim
cross-referenced against the current YAML — all match. The fix commit
`8a7d1e7` did not touch `docs/workflow.md`, so the round-2 fix is intact.

### F-2 — tests/GithubWorkflowsTest.php:108–113 — single-leg guard — **STILL FIXED (restore verified)**

**This is the key integrity check for round 3.** The fix commit
`8a7d1e7` is reported to have "briefly dropped and restored" the F-2
guard. Git only stores a commit's final tree, so the intermediate
drop/restore is not observable in history — the only reliable check is
the **current** file. Reading `tests/GithubWorkflowsTest.php` at HEAD:

- Line 117: `if (preg_match('/^  tests-scheduled:.*?(?=^  \w[\w-]*:$)/ms', $this->workflowContent, $m) === 1) {`
- Line 120: `$this->assertNotSame('', $scheduled, 'The tests-scheduled job must be present');`
- Line 121–124: `$this->assertSame(1, preg_match_all('/^ {10}- php-version:/m', $scheduled), 'The scheduled run must execute exactly one matrix leg');`

All three lines are present and correct. The block-capture regex stops at
the next job heading (`  benchmark:`), excluding the nine-leg `tests` job.

**Regression-detection re-verified (round 3):** I synthesised a regressed
YAML with a second `- php-version: '8.3'` leg inserted into the
`tests-scheduled` block. The capture regex isolated the `tests-scheduled`
block; `preg_match_all('/^ {10}- php-version:/m', $scheduled)` returned
**2**, which would fail `assertSame(1, 2)`. The guard catches the
regression it is designed to catch. The current YAML yields count **1**.

**Verdict:** F-2 guard integrity after the restore — **verified intact**.

### F-3 — tests/GithubWorkflowsTest.php:95–99 — cron "not top of the hour" — **STILL FIXED**

The minute field is now `((0?[1-9])|([1-5][0-9]))` (the F-5 fix widened
it). Re-tested `0` and `00` against the current regex → both **reject**
(top of the hour still excluded). The F-3 contract is preserved by the
F-5 widening.

### F-4 — .github/workflows/tests.yaml:190–192 — `permissions: issues: write` job-level — **STILL ACCEPTED AS DOCUMENTED**

Unchanged by `8a7d1e7`. The `ci` job still holds
`permissions: {contents: read, issues: write}` at job level. Not a
loosening of DEC-006 hardening (no source code touched; new CI-scope
grant). The tradeoff is documented in `code-decision-1.md`. No KB
violation.

### F-5 — tests/GithubWorkflowsTest.php:91 — cron regex rejects leading-zero minutes — **FIXED**

**Evidence:** Line 91 now reads
`'/^  schedule:\n    - cron: \'((0?[1-9])|([1-5][0-9])) \d+ \* \* \d+/m'`.
The minute alternation is `((0?[1-9])|([1-5][0-9]))`.

**Programmatic verification (9 cases):**

| cron minute | expect | result | notes |
|---|---|---|---|
| `23 5 * * 1` (current) | match | match ✓ | `[1-5][0-9]` |
| `05 5 * * 1` | match | match ✓ | `0?[1-9]` — F-5 target |
| `09 5 * * 1` | match | match ✓ | leading-zero 9 |
| `5 5 * * 1` | match | match ✓ | single digit |
| `59 23 * * 5` | match | match ✓ | max minute |
| `1 0 * * 1` | match | match ✓ | quiet minute, hour 0 |
| `0 0 * * 1` | reject | reject ✓ | top of hour (F-3 preserved) |
| `00 5 * * 1` | reject | reject ✓ | minute 00 |
| `60 5 * * 1` | reject | reject ✓ | invalid minute |

The widening accepts `05`/`09` (resolving F-5) while still rejecting `0`/
`00` (preserving F-3) and `60` (sanity). Backtracking correctly handles
two-digit inputs: for `23`, the first alternative `0?[1-9]` captures only
`2` and fails the following ` \d+` (next char is `3`), so PCRE falls back
to `[1-5][0-9]` = `23`. Confirmed.

**Verdict:** Fixed. No residual.

### F-6 — tests/GithubWorkflowsTest.php — no guard for benchmark schedule-skip / issue opener — **FIXED**

**Evidence (benchmark guard):** `testScheduledRunsTrimTheMatrixToASingleLeg`
now ends (line 129) with:

```php
$this->assertMatchesRegularExpression(
    '/^  benchmark:\n    name: Benchmark\n    runs-on: ubuntu-latest\n    needs: lint\n    if: github\.event_name != \'schedule\'/m',
    $this->workflowContent,
    'The advisory benchmark must not run on the weekly schedule',
);
```

**Regression-detection verified:** synthesised a YAML with `benchmark`'s
`if: github.event_name != 'schedule'` removed → regex match **0**,
assertion fails. The guard catches the regression.

**Evidence (opener test):** new method `testScheduledRunFailureOpensAnIssue`
asserts three distinct aspects:

1. `/^      - name: Open issue on scheduled failure\n        if: failure\(\) && github\.event_name == \'schedule\'/m`
   — the opener step and its condition (6-space step indent, 8-space `if:`).
2. `/^    permissions:\n      contents: read\n      issues: write$/m`
   — the `ci` job's permission grant (4-space indent ties it to job level;
   `ci` is the only job with a `permissions:` block, so no false match).
3. `assertStringContainsString('marker="Scheduled CI run failed"', ...)`
   — the dedup marker line.

**Regression-detection verified:** synthesised a YAML with the entire
opener step removed → assertions (1) and (3) fail (match/contains becomes
false); assertion (2) is independent and guards the permission grant
separately, so it still passes — which is correct, since removing the
opener step does not remove the permissions block. The three assertions
together cover: the step exists with the right condition, the permission
exists, and the dedup marker exists. No single regression escapes all
three.

**Verdict:** Fixed. No residual.

## F-2 restore — whole-file verification

Beyond the F-2 guard lines, I diffed `tests/GithubWorkflowsTest.php`
between `6dc33df` (round-1 fixes) and `8a7d1e7` (round-2 fixes). The diff
is exactly: (a) the F-5 cron-minute widening, (b) the added benchmark
`if:` assertion, (c) the new `testScheduledRunFailureOpensAnIssue`
method. **No lines in the F-2 guard region (the block-capture, the
`assertNotSame`, the `assertSame(1, preg_match_all(...))`) were removed or
altered.** The "briefly dropped and restored" episode left no trace in
the committed tree — the guard is whole, and the rest of the file was not
reintroduced or regressed.

## Knowledge-base entries consulted

Loaded the tag indices of `docs/helpers/faq.md` and
`docs/helpers/decisions.md`; read only the entries matching the diff's
tags (`ci`, `gh`, `process`) plus the security/coverage/lint policy
entries cross-referenced by the prior rounds.

- **FAQ-011** (`ci`, `coverage`, `tests`) — 80% floor in
  `composer.json` + `tests/CoverageCiGateTest.php`. `composer.json` is
  unchanged by this diff (`git diff origin/master...HEAD -- composer.json`
  is empty); `coverage:check` still resolves to
  `php bin/check-coverage.php var/coverage.xml 80.0`. Floor not lowered. ✓
- **FAQ-017** (`gh`, `triage`) — `gh issue list` ≤ 30 default. The opener
  uses `--limit 1` for a single-issue dedup search; safe. ✓
- **FAQ-030** (`tests`, `process`) — fork-helper readiness markers. No
  fork helpers touched. ✓
- **DEC-006** (`security`, `policy`) — #582–#586 hardening must stay
  intact. No source code touched; `issues: write` is a new CI-scope
  grant, not a loosening. ✓
- **DEC-007** (`coverage`, `ci`, `policy`) — 80% floor, single source in
  `composer.json`. Unchanged. ✓
- **DEC-008** (`lint`, `git-hooks`, `policy`) — `composer lint` canonical
  entry point. No new check wired in; `bin/` unchanged. ✓
- **DEC-009** (`knowledge-base`, `process`, `policy`) — single writer.
  This review proposes entries; does not write to `docs/helpers/`. ✓
- **DEC-011** (`gh`, `pr`, `process`) — PR opens after implementation.
  Not relevant to the diff content. ✓

No KB entry violations found.

## Test execution

```
php -d phar.readonly=0 vendor/bin/phpunit --no-coverage --filter GithubWorkflowsTest
```

Result: **11 tests, 34 assertions, OK** (0 failures, 0 errors). Every
regex in the test file matches the current `tests.yaml` with no false
pass and no false fail.

## Regex-vs-YAML audit (every assertion in the test file)

| Test / assertion | Regex (essence) | YAML evidence | Match | False-pass risk |
|---|---|---|---|---|
| `testASupersededRunIsCancelledInsteadOfFinishing` #1 | `^concurrency:\n  group: .+\n  cancel-in-progress: ${{ github.event_name == 'pull_request' }}$` | lines 14–16 | ✓ | none — anchored |
| … #2 | `^  group: ${{ github.workflow }}-${{ github.ref }}$` | line 15 | ✓ | none — literal |
| `testWorkflowRunsOnMasterPushScheduleAndDispatch` #1 | `^on:\n  pull_request:\n  push:\n    branches: \[master\]` | lines 3–6 | ✓ | none — ordered literal |
| … #2 | `^  schedule:\n    - cron: '((0?[1-9])|([1-5][0-9])) \d+ \* \* \d+` | lines 7–8 | ✓ | none — see F-5 table |
| … #3 | contains `  workflow_dispatch:` | line 9 | ✓ | trivial only |
| `testScheduledRunsTrimTheMatrixToASingleLeg` #1 | `^  tests:\n    name: Tests\n … \n    if: github.event_name != 'schedule'` | lines 42–47 | ✓ | none — anchored |
| … #2 | `^  tests-scheduled:\n    name: Tests \(scheduled\)` | lines 124–125 | ✓ | none |
| … #3 (F-2 block capture) | `^  tests-scheduled:.*?(?=^  \w[\w-]*:$)` /ms | stops at `  benchmark:` | ✓ | none — verified by regression sim |
| … #4 (F-2 count) | `^ {10}- php-version:` == 1 in block | 1 entry at 10-space indent | ✓ | none — verified by regression sim |
| … #5 (F-6 benchmark) | `^  benchmark:\n … \n    if: github.event_name != 'schedule'` | lines 164–169 | ✓ | none — verified by regression sim |
| `testScheduledRunFailureOpensAnIssue` #1 | `^      - name: Open issue on scheduled failure\n        if: failure\(\) && …` | lines 197–198 | ✓ | none — 6/8-space anchored |
| … #2 | `^    permissions:\n      contents: read\n      issues: write$` | lines 190–192 | ✓ | none — 4-space ties to job level |
| … #3 | contains `marker="Scheduled CI run failed"` | line 202 | ✓ | trivial only |

No false passes. No false fails (all 11 tests green on the current YAML).

## Documentation accuracy

### docs/workflow.md step 10 vs YAML

| Doc claim | YAML evidence | Match |
|---|---|---|
| triggers on PR, push to master, weekly schedule, workflow_dispatch | `on:` block lines 3–9 | ✓ |
| five jobs | lint, tests, tests-scheduled, benchmark, ci | ✓ |
| tests "Runs on every trigger except schedule" | `if: github.event_name != 'schedule'` (line 47) | ✓ |
| tests-scheduled "single representative leg (PHP 8.2 × Symfony 6.4), scheduled runs only" | matrix include (lines 130–132) + `if:` (line 128) | ✓ |
| ci "fails unless lint and tests succeeded (and, on schedule, tests-scheduled)" | gate lines 194–195 | ✓ |
| ci "opens an issue if a scheduled run fails" | opener `if:` line 198 | ✓ |
| PR runs cancelled, master runs never cancelled | `cancel-in-progress: ${{ github.event_name == 'pull_request' }}` (line 16) | ✓ |

No inaccuracies.

### CONTRIBUTING.md vs YAML

| Doc claim | YAML evidence | Match |
|---|---|---|
| "every pull request, every push to master, weekly schedule (Monday 05:23 UTC), workflow_dispatch" | `on:` + cron `23 5 * * 1` (Mon 05:23 UTC) | ✓ |
| Lint job: validate + audit + code style | lint steps | ✓ |
| Tests job: matrix 8.2–8.5 × 6.4–8.0; 8.2/6.4 leg enforces 80% floor; scheduled runs only 8.2/6.4 | matrix + coverage `if:` + tests-scheduled | ✓ |
| Benchmark: advisory, skipped on scheduled runs | `if: github.event_name != 'schedule'` (line 169) | ✓ |
| CI job: aggregator, opens "Scheduled CI run failed" issue / comments; PR cancelled, master never | gate + opener + concurrency | ✓ |

No inaccuracies. (Note: "PHP 8.2–8.5 × Symfony 6.4–8.0" is a range
description, not a claim of a full cartesian product — the matrix is a
curated 9-entry include list, unchanged in shape from pre-diff.)

## New findings

**None.** Round 3 found no new issues. The fix commit `8a7d1e7` correctly
resolves F-5 and F-6, the F-2 guard is intact after the restore, and no
prior fix was reopened. The full diff is limited to the expected nine
files (`.github/workflows/tests.yaml`, `CONTRIBUTING.md`, `docs/workflow.md`,
`tests/GithubWorkflowsTest.php`, and five proof-of-work files);
`composer.json`, `bin/`, and `phpunit.xml` are unchanged, so no gate is
weakened.

## Candidate knowledge-base entries

None new. The two candidate entries proposed in round 1 (concurrency
group discriminator; `if: always()` + OR-logic gate) remain the only
candidates; the main session decides whether they land.

## Remaining risk areas checked clean

- **Coverage gate (DEC-007):** `composer.json` unchanged; floor still
  `80.0`. `tests-scheduled` runs `composer coverage:check` on the single
  leg with the same threshold. ✓
- **Pre-push lint hook (DEC-008):** `bin/` unchanged. ✓
- **Security hardening (DEC-006):** no source code touched; `issues:
  write` is CI-scoped, documented, not a loosening. ✓
- **YAML validity:** parses (PyYAML `safe_load`); 5 jobs, 4 triggers,
  cron `23 5 * * 1`. ✓
- **Cron sanity:** `23 5 * * 1` = Monday 05:23 UTC, valid, off top of
  hour; regex now also accepts leading-zero forms. ✓
- **Token/shell injection:** `${{ needs.*.result }}` are runner-injected
  string literals; `$marker`, `date`, `run_url`, `$existing` are
  controlled/parsed. No user input. ✓
- **Fork PR exploitability:** opener runs only on `schedule`; fork PRs
  unaffected. ✓
- **`if: failure()` semantics:** step-level `failure()` = a prior step in
  the same job failed. "Check test results" `exit 1` on gate failure →
  opener fires; gate success → `failure()` false → opener skipped. ✓
- **No source, `bin/`, `composer.json`, or `phpunit.xml` changed.** ✓

## Remaining risk areas not fully verifiable from code review

- **Real scheduled run:** the acceptance criterion "verified by
  inspecting a real scheduled run, not by reading the YAML" cannot be
  satisfied from review alone. The first Monday 05:23 UTC run post-merge
  provides it. Inherent to the change, not a finding.
- **CHANGELOG.md:** the issue requires an `[Unreleased]` entry. Not in
  this diff (delegated to the main session). Must be verified before the
  issue is closed. Process step, not a code review finding.

## Final verdict

**No open findings remain.** F-1, F-2, F-3, F-4, F-5, F-6 are all closed
(F-4 accepted as documented; the rest fixed and independently confirmed).
F-2 guard integrity after the restore is **verified**. No new findings.
The test suite passes (11 tests, 34 assertions). Documentation matches
the YAML. No gate is weakened.
