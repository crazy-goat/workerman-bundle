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
