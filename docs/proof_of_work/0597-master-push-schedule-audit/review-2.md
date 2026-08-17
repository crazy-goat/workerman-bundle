# Review Round 2 — issue #597

**Branch:** `process/issue-597-workflow-runs-on-pull-request-only-maste`
**Reviewer:** review-critical (round 2)
**Date:** 2026-08-17
**Base:** `origin/master`
**HEAD:** `6dc33df`

## Earlier findings — disposition

The findings-review.md created in commit `6dc33df` already carries a
"Round 2 outcome:" line under each finding — but that line was written by
the coder as a self-assessment of their own fix, not by an independent
reviewer. This section is the independent reviewer's verdict on each.

### F-1 — docs/workflow.md:423 — stale step-10 CI description — **FIXED**

**Evidence:** `docs/workflow.md` lines 423–447 now read:

> CI workflow (`.github/workflows/tests.yaml`) triggers on pull requests,
> pushes to `master`, a weekly schedule, and manual `workflow_dispatch`. It
> has five jobs:

The five jobs are listed: lint, tests matrix, tests-scheduled, benchmark,
ci. The concurrency paragraph now reads:

> Runs on a pull request are cancelled by a newer push to the same PR
> (`concurrency: cancel-in-progress` for `pull_request` events), so two
> full matrices never grind against the same PR at once. Runs on `master`
> are never cancelled, so a post-merge failure stays visible.

Cross-referenced against `.github/workflows/tests.yaml`:
- Five jobs: `lint`, `tests`, `tests-scheduled`, `benchmark`, `ci` — ✓
- Trigger set: `pull_request`, `push: branches: [master]`, `schedule`,
  `workflow_dispatch` — ✓
- `tests` has `if: github.event_name != 'schedule'` — matches "Runs on
  every trigger except `schedule`" — ✓
- `tests-scheduled` has `if: github.event_name == 'schedule'` — matches
  "scheduled runs only" — ✓
- `ci` gate: `if needs.lint != success → exit 1; if needs.tests !=
  success && needs.tests-scheduled != success → exit 1` — matches "fails
  unless `lint` and `tests` succeeded (and, on schedule,
  `tests-scheduled`)" — ✓
- Concurrency: `cancel-in-progress: ${{ github.event_name ==
  'pull_request' }}` — matches "Runs on `master` are never cancelled" — ✓

**Verdict:** Fixed. No residual inaccuracy found.

### F-2 — tests/GithubWorkflowsTest.php:108–113 — single-leg guard doesn't verify the matrix has one entry — **FIXED**

**Evidence:** `testScheduledRunsTrimTheMatrixToASingleLeg` now includes
(lines 117–127):

```php
$scheduled = '';
if (preg_match('/^  tests-scheduled:.*?(?=^  \w[\w-]*:$)/ms', $this->workflowContent, $m) === 1) {
    $scheduled = $m[0];
}
$this->assertNotSame('', $scheduled, 'The tests-scheduled job must be present');
$this->assertSame(
    1,
    preg_match_all('/^ {10}- php-version:/m', $scheduled),
    'The scheduled run must execute exactly one matrix leg',
);
```

**Regression-detection verified:** I simulated adding a second
`- php-version: '8.3'` entry to the `tests-scheduled` block (targeting
that block specifically, not the `tests` job) and the count became 2,
which would fail `assertSame(1, 2)`. The capture regex
`/^  tests-scheduled:.*?(?=^  \w[\w-]*:$)/ms` correctly stops at the
next job heading (`  benchmark:`), so the nine-leg `tests` job's entries
cannot inflate the count. All `- php-version:` lines in the file are at
exactly 10 spaces of indentation (verified programmatically), and the
count regex `^ {10}- php-version:` matches only those — step entries
(`- name:` at 6 spaces) are excluded.

**False-fail check:** The capture regex requires a subsequent
`^  \w[\w-]*:$` heading. In the current YAML, `benchmark:` follows
`tests-scheduled`, so the lookahead succeeds. If `tests-scheduled` were
moved to be the last job, the lookahead would fail, `$scheduled` would
stay empty, and `assertNotSame('', $scheduled)` would fail with "The
tests-scheduled job must be present" — a misleading message (the job
exists but the capture failed), but an extremely unlikely scenario.

**Verdict:** Fixed. The guard would both pass on the current YAML and
fail on the regression it guards.

### F-3 — tests/GithubWorkflowsTest.php:95–99 — cron assertion doesn't enforce "not on the top of the hour" — **FIXED**

**Evidence:** The cron regex is now
`'/^  schedule:\n    - cron: \'([1-9]|[1-5][0-9]) \d+ \* \* \d+/m'` (line
91). The minute field is constrained to `([1-9]|[1-5][0-9])` = 1–59,
excluding 0.

**Regression-detection verified (programmatic):**
- `0 0 * * 1` (midnight, top of the hour) → NO match ✓ (correctly rejected)
- `60 5 * * 1` (invalid minute) → NO match ✓ (correctly rejected)
- `23 5 * * 1` (current value) → MATCH ✓
- `1 0 * * 1` (minute 1) → MATCH ✓
- `59 23 * * 5` (minute 59) → MATCH ✓

**Minor edge case (not a regression risk):** `05 5 * * 1` (leading-zero
minute 5 — valid cron, not top of hour) → NO match. This is a false-fail
if someone writes a leading-zero minute, but: (a) the repo convention is
no leading zeros (`23`, not `23`-padded), (b) GitHub's own examples don't
use leading zeros, (c) the failure message points directly at the cron
line. See F-5 below.

**Verdict:** Fixed. The regex now enforces the minute is 1–59 as the
assertion message claims.

### F-4 — .github/workflows/tests.yaml:190–192 — `permissions: issues: write` at job level — **DELIBERATELY NOT FIXED (accepted)**

**Evidence:** The `ci` job still has `permissions: {contents: read,
issues: write}` at job level (lines 189–191). The "Open issue on
scheduled failure" step (lines 196–211) is the only consumer of the
`issues: write` scope. The "Check test results" step does not set
`GH_TOKEN` in its `env`, so the `issues: write` scope is not exploitable
through it.

The tradeoff is documented in `code-decision-1.md` ("Tradeoff recorded
below") and `review-1.md` (F-4 analysis). GitHub Actions does not support
step-level `permissions`, so job-level is the narrowest scope without a
separate sixth job. The opener runs only on `schedule`, sets no
user-controlled values, and fails loud (non-zero exit) rather than
silent.

**KB cross-reference:** DEC-006 (`security`, `policy`) says "Do not
loosen these without an explicit, documented reason." The `issues: write`
grant is a new CI-scope grant, not a loosening of existing hardening
(no source code touched, no existing permission relaxed). The documented
reason exists in `code-decision-1.md`. No KB violation.

**Verdict:** Accepted as documented. No security vulnerability. The
tradeoff is reasonable for ~20 lines of shell.

## Knowledge-base entries consulted

From `docs/helpers/faq.md` (tag index → `ci`, `gh`, `process`):

- **FAQ-011** (`ci`, `coverage`, `tests`) — 80% floor promoted to
  `composer.json` + `tests/CoverageCiGateTest.php`. Not lowered by this
  diff. `tests-scheduled` runs `composer coverage:check` on the single
  leg, using the same threshold. ✓
- **FAQ-017** (`gh`, `triage`) — `gh issue list` returns ≤ 30 by
  default; the issue opener uses `--limit 1` for a single-issue dedup
  search. ✓
- **FAQ-030** (`tests`, `process`) — fork-helper readiness markers. Not
  relevant to this diff (no fork helpers touched). ✓
- **FAQ-031** (`lint`, `bin`) — `bin/` is inside linter scope. No `bin/`
  files changed. ✓

From `docs/helpers/decisions.md` (tag index → `ci`, `gh`, `process`):

- **DEC-006** (`security`, `policy`) — security hardening must stay
  intact. No source code touched; `issues: write` is a new CI-scope
  grant, not a relaxation. ✓
- **DEC-007** (`coverage`, `ci`, `policy`) — 80% floor in `composer.json`
  only. Not modified. ✓
- **DEC-008** (`lint`, `git-hooks`, `policy`) — `composer lint` is the
  canonical entry point. No new check added. ✓
- **DEC-009** (`knowledge-base`, `process`, `policy`) — single writer
  (main session). This review proposes entries; does not write. ✓
- **DEC-011** (`gh`, `pr`, `process`) — PR opens after implementation.
  Not relevant to the diff content. ✓

No KB entry violations found.

## Test execution

```
php -d phar.readonly=0 vendor/bin/phpunit --no-coverage --filter GithubWorkflowsTest
```

Result: **10 tests, 28 assertions, OK** (0 failures, 0 errors).

## New findings

### F-5 | tests/GithubWorkflowsTest.php:91 | cron regex rejects valid leading-zero minutes | nit

**Evidence:** The minute field regex `([1-9]|[1-5][0-9])` matches 1–59
without leading zeros. A valid cron expression like `05 5 * * 1` (minute
5, not top of the hour) would fail the assertion even though it satisfies
the "quiet minute not on the top of the hour" contract. I verified this
programmatically: `05 5 * * 1` → NO match.

**Impact:** Negligible. The repo convention is no leading zeros (current
value `23`), GitHub Actions examples don't use leading zeros, and a
failure would point directly at the cron line. The probability of someone
introducing a leading-zero minute is very low. If it did happen, the
false-fail would be obvious and trivially fixable.

**Smallest safe fix direction:** Add `[0]?` before `[1-9]` in the
single-digit alternative: `([1-9]|[1-5][0-9])` → `(0?[1-9]|[1-5][0-9])`.
This accepts `5` and `05` but still rejects `0` and `00`. Or leave as-is
and accept the convention enforcement as intentional.

**Automated check:** The existing `GithubWorkflowsTest` itself — a
case-input unit test with multiple cron strings would catch it. No
PHPStan or lint rule covers this.

### F-6 | tests/GithubWorkflowsTest.php | no test guard for benchmark schedule-skip | low

**Evidence:** `testScheduledRunsTrimTheMatrixToASingleLeg` asserts that
the `tests` job has `if: github.event_name != 'schedule'` (line 110),
but there is no equivalent assertion for the `benchmark` job. The YAML
has `if: github.event_name != 'schedule'` on `benchmark` (line 164),
and `CONTRIBUTING.md` states "Benchmark job … skipped on scheduled
runs". A regression removing this `if:` from `benchmark` would cause
scheduled runs to execute the benchmark unnecessarily, but no test would
fail. There is also no test guard for the "Open issue on scheduled
failure" step's existence.

**Impact:** Low. A regression here wastes ~30 seconds of CI runner time
on weekly scheduled runs (benchmark spins up a runner, installs deps,
runs PHPBench) but does not affect correctness or merge gating. The
issue opener step is new in this diff; if removed, scheduled failures
would go unnoticed (the issue's "nobody watching" problem returns), but
no test catches that either.

**Smallest safe fix direction:** Add an assertion in
`testScheduledRunsTrimTheMatrixToASingleLeg`:
```php
$this->assertMatchesRegularExpression(
    '/^  benchmark:\n    name: Benchmark\n    runs-on: ubuntu-latest\n    needs: lint\n    if: github\.event_name != \'schedule\'/m',
    $this->workflowContent,
    'The benchmark job must be skipped on the weekly schedule',
);
```
Optionally add an assertion that the issue-opener step exists:
```php
$this->assertStringContainsString(
    "Open issue on scheduled failure",
    $this->workflowContent,
);
```

**Automated check:** An extended `GithubWorkflowsTest` assertion. No
PHPStan or lint rule covers YAML workflow structure.

## docs/workflow.md step-10 accuracy vs YAML

Verified every claim in the rewritten step 10 against the YAML:

| Doc claim | YAML evidence | Match |
|---|---|---|
| Triggers on PR, push to master, weekly schedule, workflow_dispatch | `on:` block lines 3–8 | ✓ |
| Five jobs | `lint`, `tests`, `tests-scheduled`, `benchmark`, `ci` | ✓ |
| tests "Runs on every trigger except `schedule`" | `if: github.event_name != 'schedule'` (line 48) | ✓ |
| tests-scheduled "scheduled runs only" | `if: github.event_name == 'schedule'` (line 126) | ✓ |
| ci "fails unless `lint` and `tests` succeeded (and, on schedule, `tests-scheduled`)" | Gate logic lines 191–193 | ✓ |
| ci "opens an issue if a scheduled run fails" | `if: failure() && github.event_name == 'schedule'` (line 196) | ✓ |
| PR runs cancelled, master runs never cancelled | `cancel-in-progress: ${{ github.event_name == 'pull_request' }}` (line 15) | ✓ |

No inaccuracies found.

## CONTRIBUTING.md accuracy

Every claim in the rewritten "CI Configuration" section matches the YAML.
Verified in round 1; unchanged by the fix commit. ✓

## Remaining risk areas checked clean

- **Coverage gate (DEC-007):** Not modified. `tests-scheduled` runs
  `composer coverage:check` with the same threshold. ✓
- **Pre-push lint hook (DEC-008):** Not touched. ✓
- **Security hardening (DEC-006):** No source code touched. `issues:
  write` is CI-scoped, documented, and not a loosening. ✓
- **YAML validity:** 5 jobs confirmed, parses correctly. ✓
- **Cron sanity:** `23 5 * * 1` = Monday 05:23 UTC, valid, off top of
  hour. ✓
- **Token injection:** `${{ needs.*.result }}` expressions are
  runner-injected string literals (success/failure/cancelled/skipped).
  No user-controlled input. ✓
- **Fork PR exploitability:** Issue opener only runs on `schedule`.
  Fork PRs are unaffected. ✓
- **No source code, `bin/`, or config files changed.** ✓

## Remaining risk areas not fully verified

- **Real scheduled run:** The acceptance criterion "verified by
  inspecting a real scheduled run, not by reading the YAML" cannot be
  satisfied from code review. The first Monday 05:23 UTC run post-merge
  will provide this evidence. This is inherent to the change, not a
  finding.
- **CHANGELOG.md:** The issue requires an entry under `[Unreleased]`.
  Not in this diff (delegated to main session step 8). Must be verified
  before the issue is closed. Process step, not a code review finding.
