# findings-review.md — issue #597

One entry per finding. Nothing is deleted; each finding is updated in
subsequent rounds.

---

## F-1

- **File:line:** `docs/workflow.md:423`
- **What is wrong:** Step 10 says "CI workflow has four jobs" and lists
  only lint/tests/benchmark/ci. The workflow now has five jobs (added
  `tests-scheduled`). The concurrency paragraph mentions only PR
  cancellation and says nothing about push/schedule/dispatch triggers or
  that master pushes are never cancelled.
- **Severity:** low
- **What happened to it:** round 1 → new. The coder flagged this as
  out-of-scope in `findings-coder.md` item 1, but it is a factual
  inaccuracy introduced by this diff. The `CONTRIBUTING.md` "CI
  Configuration" section was updated but `docs/workflow.md` step 10 was
  not.
- **Smallest safe fix direction:** Update step 10 to list five jobs, add
  one sentence about the weekly schedule + issue opener, and update the
  concurrency paragraph to note master runs are never cancelled.
- **Automated check that would have caught it:** A docs-lint test
  cross-referencing the job count in `docs/workflow.md` against the
  actual count in `tests.yaml`. No such check exists.
- **Round 2 outcome:** fixed — step 10 rewritten: five jobs, trigger set
  (PR / push-to-master / weekly schedule / `workflow_dispatch`), the
  schedule-only `tests-scheduled` leg, the issue opener on scheduled
  failures, and the no-cancel policy for `master` runs.

---

## F-2

- **File:line:** `tests/GithubWorkflowsTest.php:108–113`
- **What is wrong:** `testScheduledRunsTrimTheMatrixToASingleLeg`
  asserts the `tests-scheduled` job exists with the correct name but
  does not verify its matrix has exactly one entry. A regression adding
  more legs to `tests-scheduled` would pass the test.
- **Severity:** low
- **What happened to it:** round 1 → new.
- **Smallest safe fix direction:** Add an assertion counting
  `- php-version:` entries in the `tests-scheduled` block and asserting
  the count is 1.
- **Automated check that would have caught it:** An extended
  `GithubWorkflowsTest` assertion. PHPStan/lint cannot catch this.
- **Round 2 outcome:** fixed — `testScheduledRunsTrimTheMatrixToASingleLeg`
  now captures the `tests-scheduled` job block (regex up to the next
  `^  <job>:` heading), asserts it is non-empty, and counts matrix
  entries (`^ {10}- php-version:` == 1). The nine-leg `tests` job is out
  of scope of the capture, so its entries cannot satisfy the count.

---

## F-3

- **File:line:** `tests/GithubWorkflowsTest.php:95–99`
- **What is wrong:** The schedule regex matches any valid weekly cron.
  The assertion message says "at a quiet minute not on the top of the
  hour" but the regex would accept `0 0 * * 1` (midnight, top of the
  hour — exactly what the issue says to avoid).
- **Severity:** nit
- **What happened to it:** round 1 → new.
- **Smallest safe fix direction:** Add a negative assertion that the
  minute field is not `0`, or parse the minute and assert `> 0`.
- **Automated check that would have caught it:** An extended
  `GithubWorkflowsTest` assertion. No existing check covers this.
- **Round 2 outcome:** fixed — the cron regex now anchors the minute to
  `[1-9]|[1-5][0-9]` (1–59), so `0 0 * * 1` fails the assertion message's
  own "not on the top of the hour" contract.

---

## F-4

- **File:line:** `.github/workflows/tests.yaml:190–192`
- **What is wrong:** `permissions: {contents: read, issues: write}` is
  granted to the entire `ci` job, though only the issue-opener step uses
  the `issues: write` scope. GitHub Actions does not support step-level
  permissions, so job-level is the narrowest scope without a separate
  job.
- **Severity:** nit
- **What happened to it:** round 1 → new. Not a security vulnerability —
  the first step does not set `GH_TOKEN` in its `env` and only does
  string comparisons. A dedicated sixth job would be more isolated but
  adds complexity. Recorded as a design observation.
- **Smallest safe fix direction:** Extract the issue opener into a
  separate job with its own `permissions: {issues: write}` block, or
  leave as-is and document the tradeoff.
- **Automated check that would have caught it:** `actionlint` or a
  security scanner like `zimor` could flag broad job-level permissions.
  Neither is in the repo's CI.
- **Round 2 outcome:** deliberately not fixed — GitHub Actions has no
  per-step permission scope; job-level is the narrowest granularity
  without splitting the opener into its own job. A dedicated sixth job
  would trade ~a dozen duplicated step lines for isolation that the
  current guard already provides (opener runs only on `schedule`, sets no
  user-controlled values, and fails loud rather than silent). Tradeoff
  recorded in `code-decision-1.md` and `review-1.md`.

- **Round 2 reviewer verdict:** confirmed fixed. Step 10 now lists five
  jobs (lint, tests, tests-scheduled, benchmark, ci), describes the full
  trigger set (PR / push-to-master / weekly schedule / workflow_dispatch),
  and the concurrency paragraph states PR runs are cancelled while master
  runs are never cancelled. Every claim cross-referenced against the YAML
  and matches. The "ci aggregator on schedule" phrasing — "fails unless
  `lint` and `tests` succeeded (and, on schedule, `tests-scheduled`)" —
  accurately describes the OR-gate in natural language. No residual
  inaccuracy.

---

## F-2 (round 2 reviewer verdict)

- **Round 2 reviewer verdict:** confirmed fixed. The block-capture regex
  `/^  tests-scheduled:.*?(?=^  \w[\w-]*:$)/ms` correctly stops at the
  next job heading (`  benchmark:`), excluding the nine-leg `tests` job.
  A simulated regression adding a second `- php-version: '8.3'` entry to
  the `tests-scheduled` block (targeting that block specifically) raised
  the count to 2, which would fail `assertSame(1, 2)`. All
  `- php-version:` lines in the file are at exactly 10 spaces; the count
  regex `^ {10}- php-version:` matches only those. Guard would both pass
  on the current YAML and fail on the regression it guards.

---

## F-3 (round 2 reviewer verdict)

- **Round 2 reviewer verdict:** confirmed fixed. The minute field regex
  `([1-9]|[1-5][0-9])` matches 1–59 and excludes 0. Verified
  programmatically: `0 0 * * 1` → NO match (correctly rejected), `60 5 *
  * 1` → NO match (correctly rejected), `23 5 * * 1` → MATCH. Minor
  edge: `05 5 * * 1` (valid cron, leading-zero minute 5) also fails —
  see F-5 below.

---

## F-4 (round 2 reviewer verdict)

- **Round 2 reviewer verdict:** accepted as documented. The `issues:
  write` permission remains at job level. Not a security vulnerability —
  the "Check test results" step does not set `GH_TOKEN` in its `env`, so
  the scope is not exploitable through it. The tradeoff is documented in
  `code-decision-1.md`. No KB violation (DEC-006 covers loosening
  existing hardening; this is a new CI-scope grant, not a loosening).

---

## F-5

- **File:line:** `tests/GithubWorkflowsTest.php:91`
- **What is wrong:** The cron minute regex `([1-9]|[1-5][0-9])` rejects
  valid leading-zero minutes (e.g., `05 5 * * 1` is valid cron for
  minute 5, not top of the hour, but the regex would not match it).
- **Severity:** nit
- **What happened to it:** round 2 → new. The F-3 fix introduced this by
  constraining the minute to 1–59 without allowing a leading zero. The
  current YAML uses `23` (no leading zero) so the test passes. A
  false-fail would only occur if someone changed the cron to use a
  leading-zero minute, which is unlikely given repo convention and
  GitHub's own examples.
- **Smallest safe fix direction:** Change `([1-9]|[1-5][0-9])` to
  `(0?[1-9]|[1-5][0-9])` to accept `5` and `05` while still rejecting
  `0` and `00`. Or leave as-is and treat the no-leading-zero convention
  as intentional enforcement.
- **Automated check that would have caught it:** A parameterized
  `GithubWorkflowsTest` with multiple cron-string inputs. No existing
  check covers this.
- **Round 3 outcome:** fixed — minute field is now `((0?[1-9])|([1-5][0-9]))`,
  accepting `5` and `05` while still rejecting `0`/`00`. The cron-guard
  test file passes (11 tests, 34 assertions).
- **Round 3 reviewer verdict:** confirmed fixed. The minute field is now
  `((0?[1-9])|([1-5][0-9]))`. Verified programmatically against nine cron
  strings: `23`, `05`, `09`, `5`, `59`, `1` (minutes) → match; `0`, `00`,
  `60` → reject. The F-3 contract (reject top of the hour) is preserved
  (`0`/`00` still rejected) and F-5's concern (accept leading-zero minutes)
  is resolved (`05`/`09` now match). The full test file passes: 11 tests,
  34 assertions.


---

## F-6

- **File:line:** `tests/GithubWorkflowsTest.php` (missing assertion)
- **What is wrong:** No test guard verifies that the `benchmark` job has
  `if: github.event_name != 'schedule'`. The `tests` job's schedule-skip
  is asserted (line 110), but `benchmark`'s is not. A regression removing
  this `if:` from `benchmark` would cause scheduled runs to execute the
  benchmark unnecessarily, but no test would fail. Similarly, no test
  guards the existence of the "Open issue on scheduled failure" step.
- **Severity:** low
- **What happened to it:** round 2 → new. The issue's acceptance
  criteria require benchmark to be skipped on schedule; CONTRIBUTING.md
  states it; the YAML implements it; but no test enforces it.
- **Smallest safe fix direction:** Add an assertion in
  `testScheduledRunsTrimTheMatrixToASingleLeg` matching the benchmark
  job's `if:` condition, and optionally an assertion that the issue
  opener step exists.
- **Automated check that would have caught it:** An extended
  `GithubWorkflowsTest` assertion. No PHPStan or lint rule covers YAML
  workflow structure.
- **Round 3 outcome:** fixed — `testScheduledRunsTrimTheMatrixToASingleLeg`
  now asserts the `benchmark` job's `if: github.event_name != 'schedule'`
  guard, and a new `testScheduledRunFailureOpensAnIssue` asserts the
  issue-opener step's `if: failure() && github.event_name == 'schedule'`
  condition, the `ci` job's `permissions: {contents: read, issues: write}`
  block, and the `marker="Scheduled CI run failed"` dedup line.
- **Round 3 reviewer verdict:** confirmed fixed.
  `testScheduledRunsTrimTheMatrixToASingleLeg` now ends with an
  `assertMatchesRegularExpression` for
  `/^  benchmark:\n    name: Benchmark\n    runs-on: ubuntu-latest\n    needs: lint\n    if: github\.event_name != 'schedule'/m`
  (line 129). Simulated regression removing `benchmark`'s `if:` → regex
  match 0, assertion fails. New `testScheduledRunFailureOpensAnIssue`
  asserts (a) the opener step's
  `if: failure() && github.event_name == 'schedule'`, (b) the `ci` job's
  `permissions: {contents: read, issues: write}` block (4-space anchored,
  ties to job level), and (c) the `marker="Scheduled CI run failed"` dedup
  line. Simulated regression removing the opener step → assertions (a)
  and (c) fail (catch it); (b) is independent and guards the permission
  grant separately. All three assertions pass on the current YAML.
