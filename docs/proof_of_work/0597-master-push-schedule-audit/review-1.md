# Review Round 1 — issue #597

**Branch:** `process/issue-597-workflow-runs-on-pull-request-only-maste`
**Reviewer:** review-critical (round 1)
**Date:** 2026-08-17

## Earlier findings

Round 1 — `findings-review.md` does not exist yet. No earlier findings to
revisit.

## Knowledge base entries consulted

From `docs/helpers/faq.md` (tag index → matching tags only):

- FAQ-011 (`ci`, `coverage`, `tests`) — 80% line-coverage floor, promoted
  to `composer.json` + `tests/CoverageCiGateTest.php`. Not lowered by this
  diff. ✓
- FAQ-015 (`git-hooks`, `lint`) — pre-push hook runs `composer lint`. Not
  touched by this diff. ✓
- FAQ-017 (`gh`, `triage`) — `gh issue list` returns ≤ 30 by default;
  the issue opener uses `--limit 1`, which is fine for a single-issue
  dedup search. ✓
- FAQ-031 (`lint`, `bin`) — `bin/` is inside linter scope. No `bin/`
  files changed. ✓

From `docs/helpers/decisions.md` (tag index → matching tags only):

- DEC-007 (`coverage`, `ci`, `policy`) — 80% floor is the single source
  of truth in `composer.json`. Not modified. ✓
- DEC-008 (`lint`, `git-hooks`, `policy`) — `composer lint` is the
  canonical entry point. No new check added to `lint`. ✓
- DEC-006 (`security`, `policy`) — security hardening must stay intact.
  No source code touched; the `issues: write` permission is a new
  CI-scope grant, not a relaxation of existing hardening. ✓
- DEC-011 (`gh`, `pr`, `process`) — PR opens after implementation. Not
  relevant to the diff content but the workflow-step context is
  consistent. ✓

No KB entry violations found.

## What the diff does

### `.github/workflows/tests.yaml`

1. **Trigger set** — `on:` gains `push: branches: [master]`, a weekly
   `schedule` (`cron: '23 5 * * 1'` = Monday 05:23 UTC), and
   `workflow_dispatch`. The existing `pull_request:` block is untouched
   (no `branches:` filter added), so all PRs trigger the workflow as
   before.

2. **Concurrency** — group changes from
   `${{ github.workflow }}-${{ github.event.pull_request.number }}` to
   `${{ github.workflow }}-${{ github.ref }}`; `cancel-in-progress`
   changes from `true` to `${{ github.event_name == 'pull_request' }}`.

3. **Scheduled-run trimming** — `tests` and `benchmark` jobs get
   `if: github.event_name != 'schedule'` (skipped on schedule, run
   otherwise). A new `tests-scheduled` job (single matrix leg: PHP 8.2 /
   Symfony 6.4.*) with `if: github.event_name == 'schedule'` runs only
   on schedule, including the coverage gate.

4. **`ci` aggregator** — `needs` adds `tests-scheduled`; `permissions:
   {contents: read, issues: write}` added; gate logic becomes "either
   tests job succeeding is enough"; new "Open issue on scheduled failure"
   step with `if: failure() && github.event_name == 'schedule'`.

### `tests/GithubWorkflowsTest.php`

- `testASupersededRunIsCancelledInsteadOfFinishing` regexes updated to
  match the new concurrency block.
- `testWorkflowRunsOnMasterPushScheduleAndDispatch` (new) — asserts
  `pull_request` + `push: branches: [master]` + schedule + dispatch.
- `testScheduledRunsTrimTheMatrixToASingleLeg` (new) — asserts `tests`
  has `if: github.event_name != 'schedule'` and `tests-scheduled` exists.

### `CONTRIBUTING.md`

- "CI Configuration" section rewritten to describe the new trigger set,
  scheduled leg, benchmark skip, and failure-signal issue opener.

### Proof-of-work files

- `code-decision-1.md`, `findings-coder.md` — low priority for review;
  read for context. The coder's out-of-scope finding about
  `docs/workflow.md:423` staleness is valid (see finding F-1 below).

## PR-behaviour identity vs the old nine-leg behaviour

**Verified.** Evidence:

1. **Trigger:** `pull_request:` is byte-identical (no `branches:` filter
   added). All PRs trigger as before. ✓

2. **Concurrency for PRs:** old group = `Tests-<PR_number>`, new group =
   `Tests-refs/pull/<n>/merge`. Both are per-PR (one PR's run cannot
   cancel another's). Old `cancel-in-progress: true`, new =
   `${{ github.event_name == 'pull_request' }}` = `true` for PRs.
   Identical cancellation semantics. ✓

3. **`lint` job:** completely unchanged. ✓

4. **`tests` job:** only change is `if: github.event_name != 'schedule'`
   added. For PR events this evaluates to `true` → job runs. Matrix
   (9 legs), all steps, coverage gate, artifact upload — all unchanged. ✓

5. **`benchmark` job:** only change is `if: github.event_name !=
   'schedule'` added. For PR events → runs. All steps unchanged. ✓

6. **`ci` aggregator for PRs:** `needs.tests-scheduled.result` =
   `skipped` for PR events. New gate: `if tests != success &&
   tests-scheduled != success → exit 1`. Since `skipped != success` is
   always true, this reduces to `if tests != success → exit 1` —
   identical to the old gate. The `permissions` block and issue-opener
   step do not affect PR runs (issue opener has `if: ...
   github.event_name == 'schedule'` → skipped for PRs). ✓

7. **`tests-scheduled` for PRs:** `if: github.event_name == 'schedule'`
   → `false` → skipped. Invisible to PR runs. ✓

## `ci` aggregator consistency for every event type

| Event            | lint    | tests (9 legs) | benchmark | tests-scheduled | ci gate passes if…                                  |
|------------------|---------|-----------------|-----------|-----------------|-----------------------------------------------------|
| `pull_request`   | runs    | runs            | runs      | skipped         | lint=success AND tests=success                      |
| `push` (master)  | runs    | runs            | runs      | skipped         | lint=success AND tests=success                      |
| `schedule`       | runs    | skipped         | skipped   | runs (1 leg)    | lint=success AND tests-scheduled=success            |
| `workflow_dispatch` | runs | runs            | runs      | skipped         | lint=success AND tests=success                      |

The `if: always()` on the `ci` job ensures it runs even when needed jobs
are skipped. The gate `tests != success && tests-scheduled != success`
correctly reduces to a single-job check for each event type because
exactly one of the two tests jobs is `skipped` (not `success`) for any
given event. **Consistent for all four event types.** ✓

Edge case: if `lint` fails, `tests`, `benchmark`, and `tests-scheduled`
are all skipped (they `needs: lint`). The `ci` gate hits `needs.lint.result
!= success` → exit 1. On schedule, the issue opener then fires
(`failure() && schedule`). ✓

## Issue-opener analysis

**Least privilege:** `permissions: {contents: read, issues: write}` is
scoped to the `ci` job only — other jobs use the repo default. GitHub
Actions does not support step-level permissions, so job-level is the
narrowest scope without a separate job. The first step ("Check test
results") does not set `GH_TOKEN` in its `env`, so the `issues: write`
scope is not exploitable through it (it only does string comparisons on
injected context values). A dedicated sixth job for the opener would be
more isolated but adds workflow complexity. This is an acceptable
tradeoff. (See finding F-4 for the design observation.)

**Dedup marker:** `gh issue list --search "Scheduled CI run failed
in:title" --state open --limit 1 --json number --jq '.[0].number //
empty'`. The four words are AND-ed by GitHub's search API; `in:title`
restricts to title. If found → comment; if not → create. Closed issues
are excluded by `--state open`, so a new failure after resolution
creates a new issue. Dedup logic is sound. ✓

**Failure mode:** if `gh` is not installed or any `gh` command fails,
the step fails with a non-zero exit code, visible in the run logs. Loud,
not silent. ✓

**Fork PRs:** `GITHUB_TOKEN` is read-only for fork PRs, but the opener
only runs on `schedule` events, so fork PRs are unaffected. ✓

## Scheduled leg representativeness

The chosen leg (PHP 8.2 / Symfony 6.4.*) is the lowest supported
combination and the one that carries the coverage gate in the `tests`
job. It runs `composer test:coverage` + `composer coverage:check`,
matching the PR twin leg. The lint job (including `composer audit`)
runs on all events, so advisory monitoring is covered.

A dependency drift that affects only newer PHP/Symfony versions (8.4/8.5
× 7.4/8.0) would not be caught by the scheduled run — only by the next
PR. This is acknowledged in the issue ("the lint job plus a single
representative matrix leg is enough") and is a reasonable tradeoff for
weekly cost. Not a finding.

## YAML validity and cron sanity

- YAML parses successfully (verified with Python `yaml.safe_load`).
- 5 jobs confirmed: `lint`, `tests`, `tests-scheduled`, `benchmark`,
  `ci`.
- Cron `23 5 * * 1` = minute 23, hour 5, every Monday. Valid. Off the
  top of the hour (minute ≠ 0), as the issue requests. ✓
- `tests` matrix has 9 entries (lines 53–72); `tests-scheduled` has 1
  (line 132). ✓

## CONTRIBUTING.md accuracy

Every claim in the rewritten "CI Configuration" section matches the
YAML:

- "runs on every pull request, on every push to `master`, on a weekly
  schedule (Monday 05:23 UTC), and on demand via `workflow_dispatch`" —
  ✓ matches `on:` block.
- "Scheduled runs execute only the PHP 8.2 / Symfony 6.4 leg" — ✓
  matches `tests-scheduled` matrix.
- "Benchmark job … skipped on scheduled runs" — ✓ matches `if:
  github.event_name != 'schedule'`.
- "CI job … on a failing scheduled run it opens a 'Scheduled CI run
  failed' issue" — ✓ matches issue-opener step.
- "Superseded pull-request runs are cancelled, but a `master` run is
  never cancelled by a later one" — ✓ matches concurrency block.

## CHANGELOG.md

Not modified in this diff. The coder's findings explicitly note this is
delegated to the main session at workflow step 8. The issue's acceptance
criteria include "CHANGELOG.md receives an entry under [Unreleased]".
This is a process step, not a code review finding — but it must not be
forgotten before the issue is closed.

## docs/workflow.md step-10 staleness

The coder flagged `docs/workflow.md:423` as stale. Verified:
- Line 423 says "CI workflow has four jobs" — now five.
- The concurrency paragraph says only "A run superseded by a newer push
  on the same pull request is cancelled" — doesn't mention that master
  pushes are never cancelled, or that schedule/dispatch exist.
- No mention of `tests-scheduled`, the weekly schedule, or the issue
  opener.

This is a factual inaccuracy introduced by this change. The coder left
it out of scope (no `docs/workflow.md` edit). See finding F-1.

## Findings

### F-1 | docs/workflow.md:423 | stale step-10 CI description | low

**Evidence:** `docs/workflow.md:423` says "CI workflow
(`.github/workflows/tests.yaml`) has four jobs" and lists only
lint/tests/benchmark/ci. The workflow now has five jobs (added
`tests-scheduled`). The concurrency paragraph says "A run superseded by
a newer push on the same pull request is cancelled" but does not mention
that master pushes are never cancelled, or that schedule/dispatch
triggers exist.

**Impact:** A contributor following the workflow doc gets an inaccurate
picture of the CI layout. Not user-facing, but the doc is referenced by
`CONTRIBUTING.md` ("see `docs/workflow.md` for the full CI layout") and
by the workflow itself (step 10). The stale text is a direct consequence
of this diff adding a job and trigger set.

**Smallest safe fix direction:** Update step 10 to list five jobs, add
one sentence about the weekly schedule + issue opener, and update the
concurrency paragraph to note master runs are never cancelled. The coder
flagged this for a follow-up; recording it so it is not lost.

**Automated check:** A docs-lint test that cross-references the job
count in `docs/workflow.md` against the actual job count in
`tests.yaml`. No such check exists; a simple `GithubWorkflowsTest`
assertion on `docs/workflow.md` content could catch it.

### F-2 | tests/GithubWorkflowsTest.php:108–113 | single-leg guard doesn't verify the matrix has one entry | low

**Evidence:** `testScheduledRunsTrimTheMatrixToASingleLeg` asserts
`/^  tests-scheduled:\n    name: Tests \(scheduled\)/m` — this only
checks the job exists with the right name. It does not verify the matrix
has exactly one entry. A regression that adds more legs to
`tests-scheduled` (e.g., a second `- php-version: '8.4'` entry) would
pass the test. The assertion message says "A single-leg tests job must
run on the weekly schedule" but the regex doesn't enforce "single-leg".

**Impact:** The acceptance criterion "scheduled run does not execute all
nine matrix legs" is only verifiable on a real scheduled run (as the
issue itself notes). The test guards against removing the
`tests-scheduled` job but not against expanding it. If someone
accidentally copies the full 9-leg matrix into `tests-scheduled`, the
test passes but the scheduled run costs 9× as much.

**Smallest safe fix direction:** Add an assertion that counts
`- php-version:` entries within the `tests-scheduled` block and asserts
the count is 1. Alternatively, assert the `tests-scheduled` matrix
section contains exactly one `symfony-version:` line.

**Automated check:** An extended `GithubWorkflowsTest` assertion (count
matrix entries in the `tests-scheduled` block). PHPStan/lint cannot
catch this — it's a test-coverage gap.

### F-3 | tests/GithubWorkflowsTest.php:95–99 | cron assertion doesn't enforce "not on the top of the hour" | nit

**Evidence:** The schedule regex
`/^  schedule:\n    - cron: \'\d+ \d+ \* \* \d+/m` matches any valid
weekly cron with two numeric fields, two `*`, and one numeric field. The
assertion message says "at a quiet minute not on the top of the hour"
but the regex would accept `0 0 * * 1` (midnight Monday, top of the
hour — exactly what the issue says to avoid).

**Impact:** Minimal — the current YAML has `23 5 * * 1` which is
correct. The test would not catch a regression to `0 0 * * 1`. The
assertion message overstates what the regex checks.

**Smallest safe fix direction:** Add a negative assertion that the
minute field is not `0` (e.g., assert the cron string does not match
`'0 \d+ \* \* \d+`), or parse the minute and assert `> 0`.

**Automated check:** An extended `GithubWorkflowsTest` assertion. No
existing automated check covers this.

### F-4 | .github/workflows/tests.yaml:190–192 | `permissions: issues: write` at job level rather than a dedicated job | nit

**Evidence:** The `ci` job grants `issues: write` to the
`GITHUB_TOKEN` for the entire job, though only the "Open issue on
scheduled failure" step uses it. The "Check test results" step does not
set `GH_TOKEN` in its `env` and only does string comparisons, so the
scope is not exploitable through it. GitHub Actions does not support
step-level `permissions`, so job-level is the narrowest scope without a
separate job.

**Impact:** No security vulnerability. A dedicated sixth job (e.g.
`issue-opener: needs: ci, if: failure() && github.event_name ==
'schedule', permissions: {issues: write}`) would be more isolated but
adds workflow complexity and another `needs` edge. The current design
is an acceptable tradeoff; noted for the record.

**Smallest safe fix direction:** Extract the issue opener into a
separate job with its own `permissions: {issues: write}` block, so the
`ci` aggregator job retains only `contents: read`. Or leave as-is and
document the tradeoff.

**Automated check:** `actionlint` or a security scanner like `zizmor`
could flag broad job-level permissions. Neither is in the repo's CI.

## Candidate knowledge-base entries

### Entry 1

- **Title:** GitHub Actions concurrency group must use `github.ref`, not
  `github.event.pull_request.number`, when multiple trigger types exist
- **Tags:** `ci`, `gh`, `process`
- **Trigger:** "adding a second trigger type to a GitHub Actions workflow that already has a concurrency block"
- **Paragraph:** When a workflow triggers only on `pull_request`,
  `github.event.pull_request.number` is a safe per-PR concurrency
  discriminator. The moment a second trigger (`push`, `schedule`,
  `workflow_dispatch`) is added, non-PR events have no
  `pull_request.number` context — the group becomes `Tests-` (empty),
  and with `cancel-in-progress: true` every master push silently cancels
  the previous run. Use `${{ github.ref }}` as the discriminator (it is
  `refs/pull/<n>/merge` for PRs, `refs/heads/master` for pushes) and
  `${{ github.event_name == 'pull_request' }}` for `cancel-in-progress`
  so only PR runs are cancellable. Non-PR events sharing the same ref
  (e.g., schedule + push to master) queue instead of cancelling, which
  is the desired behaviour.

### Entry 2

- **Title:** GitHub Actions `if: always()` + `needs` with skipped jobs — gate with OR-logic
- **Tags:** `ci`, `tests`
- **Trigger:** "writing a `ci` aggregator job that needs jobs gated by mutually exclusive `if` conditions"
- **Paragraph:** When two jobs are mutually exclusive (one runs on
  `pull_request`/`push`/`workflow_dispatch`, the other on `schedule`),
  the aggregator job with `if: always()` sees the skipped one as
  `result: skipped` (not `success`). A gate like `if tests != success →
  exit 1` would fail on schedule because `tests` is skipped. Use OR-logic:
  `if tests != success && tests-scheduled != success → exit 1` — since
  exactly one is `skipped` (never `success`) for any given event, the
  condition reduces to checking the one job that actually ran.

## Remaining risk areas checked

- **Coverage gate (DEC-007):** Not modified. `tests-scheduled` runs
  `composer coverage:check` without a matrix gate (single leg). The
  threshold is defined in `composer.json`. ✓
- **Pre-push lint hook (FAQ-015, DEC-008):** Not touched. No new check
  added to `lint`. ✓
- **Security hardening (DEC-006):** No source code touched. The
  `issues: write` grant is CI-scoped and only used by the issue opener
  on scheduled failures. ✓
- **YAML validity:** Verified. ✓
- **Cron sanity:** `23 5 * * 1` is valid, weekly, off the top of the
  hour. ✓
- **README badge:** `?branch=master` badge becomes honest with the push
  trigger. No change needed. ✓
- **Token injection in shell scripts:** `${{ needs.*.result }}`
  expressions are injected by the Actions runner as string literals
  (always one of `success`/`failure`/`cancelled`/`skipped`). No
  user-controlled input reaches these expressions. ✓
- **Fork PR exploitability:** `GITHUB_TOKEN` is read-only for fork PRs;
  the issue opener only runs on `schedule`. ✓

## Open questions

1. **`docs/workflow.md` step-10 update:** F-1 is a real staleness. The
   coder left it out of scope. Should the main session update it in this
   cycle, or should a follow-up docs issue be filed? The issue's
   acceptance criteria only mention `CONTRIBUTING.md`, but the workflow
   doc is now factually wrong.

2. **CHANGELOG.md:** The issue requires an entry under `[Unreleased]`.
   Not in this diff (delegated to main session step 8). Must be
   verified before the issue is closed.

3. **Real scheduled run verification:** Acceptance criterion "verified
   by inspecting a real scheduled run, not by reading the YAML" cannot
   be satisfied from code review alone. The first Monday 05:23 UTC run
   post-merge will provide this evidence.
