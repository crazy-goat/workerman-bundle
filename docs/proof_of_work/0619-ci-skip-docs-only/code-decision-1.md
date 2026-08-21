# Code decision 1 — skip the full lint/test matrix for docs-only changes (#619)

## Goal

A pull request that touches only documentation (`docs/**`, `*.md`, `*.mdx`)
should not spin up the nine-leg PHP/Symfony `tests` matrix or the `benchmark`
job. `lint` must keep running on every change (it is the only job that catches
a broken workflow YAML under `.github/`). The `ci` aggregator job must still
report a green result so a required branch-protection `ci` check does not sit
pending forever.

## Approach taken

A new `detect-changes` job, placed after `lint`, classifies the diff and
exposes a `docs-only` output (`true`/`false`). `tests` and `benchmark` grew
the condition `&& needs.detect-changes.outputs.docs-only != 'true'` and now
`needs: [lint, detect-changes]`. The `ci` aggregator was extended to exit 0
early when `needs.detect-changes.outputs.docs-only == 'true'`, before the
"either tests job must succeed" check — so an intentional docs-only skip is a
green result, not a missing-tests failure.

The classifier runs `git diff --name-only "$base" "$head"` for pull requests
(`base` = `github.event.pull_request.base.sha`, with a merge-base fallback for
force-pushed branches) and walks the file list with a `case` matcher
(`docs/*|*.md|*.mdx` → docs; anything else → not docs). For non-pull-request
events it short-circuits to `docs-only=false`, so pushes to master, the weekly
schedule and `workflow_dispatch` keep running the full matrix.

`detect-changes` has **no `if` of its own**, so it runs on every trigger.
This is deliberate: GitHub Actions' default `success()` status check makes a
job that `needs` a skipped job skip too (actions/runner#491, #2205). If
`detect-changes` were gated to `pull_request` only, then on a push to master
it would be skipped, and `tests`/`benchmark` — whose `if` contains no status
function — would inherit the skip and never run, breaking master CI. Running
the job unconditionally and deciding docs-only inside the step avoids that
trap entirely.

## Approaches rejected

### `paths-ignore` on the `pull_request` trigger (the issue's option A)

Rejected for the reason the issue itself flags: if the workflow does not
trigger at all, the `ci` check produces no result. With a branch-protection
rule requiring `ci`, that check stays "expected / pending" indefinitely and
the PR cannot merge. This is a known GitHub limitation with no clean
workaround that doesn't involve a second workflow or an external bot. The
conditional-job approach keeps the workflow running, so `ci` always reports.

It would also have broken the regex pin
`/^on:\n  pull_request:\n  push:\n    branches: \[master\]/m` — putting
`paths-ignore` under `pull_request:` inserts `paths-ignore:` between
`pull_request:` and `push:`, so the test would need rewriting either way. The
conditional-job approach leaves the `on:` block untouched, so that pin still
matches as written.

### `dorny/paths-filter` or `gh api` to list changed files

Rejected as unnecessary third-party/network dependency. The repo already
checks out with `fetch-depth: 0` available; a local `git diff` is faster, has
no rate limit, and needs no extra action pin (the repo pins every third-party
action by SHA). One fewer moving part.

### Making `detect-changes` skip-propagation-safe with `if: !failure() && !cancelled()`

Considered but not needed. It would only matter if `detect-changes` itself
were conditional and could be skipped. By making it unconditional, no
dependent job ever inherits a skip, so the simpler `if:` on `tests`/`
`benchmark` (no status function) is correct.

## Test pinning

`tests/GithubWorkflowsTest.php` pins the workflow structure with regexes on
the raw YAML. The intentional structural change updated two pins and added one
test:

- `testScheduledRunsTrimTheMatrixToASingleLeg`: the `tests` and `benchmark`
  regexes now assert `needs: [lint, detect-changes]` and the new
  `if: github.event_name != 'schedule' && needs.detect-changes.outputs.docs-only != 'true'`.
- New `testDocsOnlyChangeSkipsHeavyJobsButKeepsLintAndCi`: asserts the
  `detect-changes` job exists with the `docs-only` output, the
  `docs/*|*.md|*.mdx` matcher, the non-PR short-circuit, and the ci
  aggregator's docs-only green path.

The `on:` trigger-block regex, the concurrency regex, the `tests-scheduled`
regex, the scheduled-failure-issue regex and the `permissions` block regex are
all unchanged — those parts of the workflow did not change. `tests/
CoverageCiGateTest.php` is unchanged: the coverage gate still runs once on the
lowest matrix leg (`substr_count('run: composer coverage:check') === 1`
holds).

## Uncertainties

- The `git merge-base "$head" "origin/${{ github.base_ref }}"` fallback relies
  on the base ref being fetched. `fetch-depth: 0` fetches all history for the
  PR head, and GitHub's PR checkout fetches the base ref as a remote-tracking
  ref, so this should resolve; if a future checkout config changes, the
  fallback could return empty and the job falls back to `docs-only=false`
  (runs the full matrix) — a safe failure mode.
- A docs-only PR that also edits `tests/GithubWorkflowsTest.php` is *not*
  docs-only (the test file is not under `docs/` and is not `*.md`), so the
  full matrix runs — correct.
