# code-decision-1.md — issue #597 (workflow runs on pull_request only)

Round 1. Scope: strictly issue #597 — `.github/workflows/tests.yaml`,
`tests/GithubWorkflowsTest.php` (forced in scope, see below),
`CONTRIBUTING.md` ("CI Configuration" section). README badge checked: no
change needed. CHANGELOG.md deliberately **not** touched (workflow step 8,
main session). `docs/workflow.md` not touched (out of scope; finding).

## The approach

**Trigger set.** `on:` gains `push: branches: [master]`, a weekly
`schedule` (`23 5 * * 1` — Monday 05:23 UTC: a quiet hour, deliberately off
the top of the hour as the issue asks, since GitHub's scheduled-job queue is
most congested exactly on the hour), and `workflow_dispatch`. The existing
`pull_request:` block is untouched, so PR behaviour is byte-identical.

**Concurrency.** The group changes from `${{ github.workflow }}-${{
github.event.pull_request.number }}` to `${{ github.workflow }}-${{
github.ref }}` and `cancel-in-progress` becomes `${{ github.event_name ==
'pull_request' }}` exactly as the issue prescribes. This is not cosmetic:
with any second trigger added, the old group would have been `Tests-` (empty
PR number) for every push/schedule/dispatch run, and with
`cancel-in-progress: true` every master push would have cancelled the
previous one — silently dropping post-merge failures. Per-PR semantics for
pull requests are preserved (`github.ref` for a PR event is
`refs/pull/<n>/merge`, so the group is still per-PR), and master runs never
cancel a sibling (they queue instead).

**Scheduled-run trimming.** Two halves:
- `tests` job and `benchmark` job get `if: github.event_name != 'schedule'`
  — on PR/push/dispatch they behave exactly as before; on schedule they are
  skipped (zero runner cost).
- A new explicit `tests-scheduled` job (PHP 8.2 / Symfony 6.4.* — the
  lowest leg, the one that carries the coverage gate) runs only on
  `schedule` and contains the same steps as its PR twin, including the
  coverage gate, minus the artifact upload (no consumer on schedule).

So a scheduled run = lint (with `composer audit` — its main value) + one
matrix leg. The `ci` aggregator learns about the new job via
`needs: [lint, tests, benchmark, tests-scheduled]` and the gate becomes
"either tests job succeeding is enough", because on schedule `tests` (and
`benchmark`) report `skipped`.

**Scheduled-failure visibility: implemented an issue opener.** The `ci`
job's last step, gated `if: failure() && github.event_name == 'schedule'`,
uses the runner-preinstalled `gh` CLI (authenticated via `GH_TOKEN =
github.token`): if no open issue titled with the marker "Scheduled CI run
failed" exists, it creates one with a link to the failed run; if one exists
(repeat failure), it comments the new run link on it instead of stacking a
new issue. Both branching halves keep the dedup promise. The `ci` job gets
an explicit `permissions: {contents: read, issues: write}` block so the
opener works on repos whose default workflow token is read-only.
Chosen over the documented no-op alternative because the issue itself frames
"nobody watching" as a real loss and the opener is ~20 lines of shell with
no new third-party action to pin or maintain. Tradeoff recorded below.

**CONTRIBUTING.md.** The "CI Configuration" section now describes the real
trigger set (PR, push to master, weekly schedule with the leg trimming,
workflow_dispatch), the scheduled leg, and the failure-signal behaviour.

**tests/GithubWorkflowsTest.php — forced into scope.** Its two
`testASupersededRunIsCancelledInsteadOfFinishing` regexes asserted the old
concurrency block (`cancel-in-progress: true`,
`group: .*pull_request\.number`); both would have failed after this change.
Updated to assert the new semantics (per-ref group, PR-only cancellation)
and extended with `testWorkflowRunsOnMasterPushScheduleAndDispatch` and
`testScheduledRunsTrimTheMatrixToASingleLeg` so the issue's invariants are
guarded by the suite going forward. The historical "draft ready" provenance
in the docblock is preserved and extended.

## What I rejected, and why

- **Matrix-expression trimming** (e.g. a job-level `if` on `tests` reading
  `matrix.php-version` so only the 8.2/6.4 leg runs on schedule, or an
  `if` per include entry): one unverifiable semantic — whether the `matrix`
  context is available in a job-level `if`. I could not validate that
  locally, and its failure mode (all nine legs executing on schedule)
  violates the acceptance criterion that is only checkable on a real
  scheduled run — i.e. it would surface after merge, weeks later. The
  explicit `tests-scheduled` job has zero unverifiable semantics; its cost
  is ~25 duplicated step lines.
- **Step-level gating** of the nine legs (skip steps except on the 8.2/6.4
  leg): all nine jobs still spin up runners on schedule — cost not saved and
  "executes all nine matrix legs" still true in spirit.
- **Composite action** to DRY the two jobs' steps: a new file + input
  plumbing for exactly two consumers. Duplication was cheaper and more
  explicit; extract only if a third consumer appears.
- **Issue opener via `actions/github-script`**: a new third-party action
  whose SHA I would have had to source and pin, with a JS script instead of
  shell. `gh` is preinstalled on `ubuntu-latest` and needs no pinning. If
  the opener step itself fails, the run shows it — the failure stays
  visible, just without the issue.
- **No-op decision recorded in CONTRIBUTING.md only**: weaker signal; also
  the issue explicitly sanctions the opener and the acceptance criterion
  asks for "a visible signal".
- **#619 co-landing** (the issue body suggests landing the coverage-gate
  change together): rejected per task instruction — separate issue,
  separate cycle. Nothing in this diff touches coverage-gate semantics.
- **README badge change**: none needed — the `?branch=master` badge becomes
  honest the moment the push trigger exists.

## Anything I was unsure about

- **The `if: always()` + `needs` interplay on cancellation** (a superseded
  run where `ci` never starts, or a cancelled run still evaluating
  `always()`): pre-existing behaviour, unchanged by this diff; I did not
  relitigate it.
- **Cron time**: `23 5 * * 1` is a judgement call within the issue's
  constraints (quiet hour, not top of the hour). 05:23 UTC is 07:23 CEST
  Monday — early enough to be quiet, late enough that a European maintainer
  is likely to see the failure the same morning.
- **`gh issue list --search` phrasing** without explicit quotes around the
  marker: the four words are AND-ed against open issue titles, which is the
  intended dedup; order-insensitivity also makes the match robust.
- **The scheduled leg runs `test:coverage`** (needs pcov) rather than
  `test`, and keeps the coverage gate: parity with the PR twin leg
  outweighs the minor cost of pcov on a single weekly leg.
