# Critical review — #596, round 2

## Scope, triggers and verdict

Reviewed PR #826 at `178a0c3d5bceb5958def04ebcb3fd03ac13b85be` against
`a5c03fb`, with particular attention to CI run 35267985724, attempt 1.
Critical-review triggers remain **security-relevant archive packaging policy**
(`.gitattributes`) and **more than 200 changed lines** (349 insertions,
5 deletions including proof records). No PHP implementation, public signature,
process-supervision implementation or workflow YAML is changed by this PR.

**Verdict: no PR regression found.** The post-reload failure is a pre-existing,
tracked test-readiness flake; defer it to **#810**, not an implementation change
for #596. One additional, pre-existing low-severity CI artifact failure needs
separate follow-up. Neither disposition waives the CI-before-merge requirement.
The main session already requested failed-job reruns; no additional rerun was
requested here. The run was in progress when inspected; this report does not
claim the rerun passed.

## Knowledge base and round-1 reconciliation

Read the existing findings ledger before investigating new issues, loaded both
helpers tag indexes, then consulted the applicable docs/bin/process/CI/daemon
entries (not the whole helpers files). In particular FAQ-007/008/009/030/039
cover daemon pitfalls, FAQ-031 covers bin lint scope, FAQ-032/033/034/037 cover
CI validation, FAQ-019 covers documentation accuracy, and DEC-007/008/009/011/
012/021 cover gates, canonical lint, helper ownership and documentation policy.
No applicable documented decision is violated by this diff. No helper was edited.

Round 1 explicitly contained **no findings**, hence there are no prior open
entries to classify as still present / fixed / not a real finding. Its clean
implementation verdict stands. `git diff 3ce2a11..HEAD --stat` shows only its
two review records; implementation content has not changed since review 1.
The new CI observation does not invalidate its scoped 656-test result or imply
that the full matrix was green. Round-1 archive/install evidence retains its
stated limitations; it was not independently rerun in this round. Its separate
branch-protection/KB-budget observations remain outside this PR's changes and
were not re-investigated here.

## R2-F1 — medium: pre-existing post-reload readiness race

**Location:** `tests/WorkermanCommandTest.php:89-91`.
**Status: still present; deferred to open #810. Not a regression from #596.**

Independently retrieved the job metadata and raw logs using the GitHub API:

- [PR run, job 105360235133](https://github.com/crazy-goat/workerman-bundle/actions/runs/35267985724/job/105360235133):
  attempt 1, `Tests (8.5, 6.4.*)`, PHP 8.5.10, head `178a0c3`.
  At 2026-09-17 20:01:50Z, exactly one PHPUnit error:
  `WorkermanCommandTest::testReloadDoesNotBreakServer`,
  `ConnectException: cURL error 52: Empty reply from server`,
  `http://127.0.0.1:8888/response_test`, test line 91.
  2703 tests, 18384 assertions, 1 error; exit 2. Reported line coverage 84.46%.
- [Earlier master job 103167567078](https://github.com/crazy-goat/workerman-bundle/actions/runs/34569155832/job/103167567078):
  push to `master`, head `38e7852b18ba8c0e3943ea34e9fb2600b65fa017`,
  `Tests (8.4, 7.4.*)`, PHP 8.4.25. At 2026-09-11 06:18:43Z,
  **the identical test, cURL 52 message, URL and line 91** failed.
  2658 tests, 18228 assertions, 1 error; exit 2. Coverage 84.65%.
- [Issue #810](https://github.com/crazy-goat/workerman-bundle/issues/810)
  is OPEN, created 2026-09-10. It identifies this exact test and its
  TCP-port-up/single-HTTP-request race, with earlier failures on PHP 8.2/6.4
  and PHP 8.4/8.0. Its examples are **cURL 56**, not 52; the exact 52 match is
  independently established by the master log above, not inferred from its title.
- `git merge-base --is-ancestor 38e7852 HEAD` succeeds. The test, ServerManager
  and WorkermanCommand are unchanged between that master commit and HEAD.
  There are unrelated intervening master changes; this comparison is not a
  claim that the entire tree or dependency resolution is identical.

Behavior trace: the command's `handleReload()` calls `ServerManager::reload()`
and reports that the signal was sent. `reload(false)` sends SIGUSR1 to the
verified master and returns without a completion/readiness handshake. The local
Workerman implementation forwards reload signals to worker processes, which
stop/restart asynchronously. The test's `waitForPortUp()` only opens and closes
a TCP socket; it cannot establish that the worker handling the next HTTP request
is ready or will survive that reload. The following HTTP request is single-shot.
This is consistent with #810 and both logs. Logs alone do not establish the
precise interleaving or prove that every possible cURL 52 has this cause.

The PR changes docs and export-ignore rules, not this path. The test workflow
uses actions/checkout and executes the suite from the source checkout; archive
exclusions do not remove runtime/test files there. No credible causal path from
this diff to a new reload behavior was found.

**CI-escaped defect / automated check:** the existing full PHPUnit integration
suite catches the race intermittently (as both logs demonstrate); repeated,
serialized reload-test runs can expose it more often, not guarantee detection.
PHPStan level 8, PSR-12 and Markdown/archive checks cannot validate asynchronous
readiness. A bounded HTTP-readiness assertion with a retained final failure is
appropriate work for #810. No test was skipped, retry added, or assertion relaxed
here. This is an existing tracked defect, not a repeated PR-introduced defect
requiring a new check in this documentation-only assessment.

## R2-F2 — low: failure-path coverage artifact name is missing

**Location:** `.github/workflows/tests.yaml:172-183` (unchanged).
**Status: still present; pre-existing, deferred to separate main-session follow-up.**

Both retrieved logs also show `Provided artifact name input during validation
is empty` after PHPUnit exits 2. The name-producing step has the default success
condition and is skipped after test failure, whereas upload runs with `always()`
and consumes that missing output. Thus the coverage file is generated but its
upload fails. This is a secondary diagnostics defect, not the reason the test
failed, and **not covered by #810's reload-test scope**. An open-issue title sweep
(limit 200) and all-state search for `artifact name` found no matching tracker;
this is not proof that no differently worded issue exists. Main should verify
and track separately rather than silently include a workflow fix in #596.

**Automated check:** workflow failure-path validation should assert that an
always-running upload has a name even when testing fails; the observed failing
CI jobs already exercise and expose this path. A future fix should cover the
producer/consumer conditions in workflow tests and sweep all YAML-pinning test
classes per FAQ-032. No workflow was edited here, so that change-triggered full
suite requirement does not apply to this round.

## Diff/checklist and limits

Re-read all seven non-proof changed files and the prior review/coder findings.
The docs audience grouping, absolute links to excluded material, bin README
exception and Unreleased changelog entry remain consistent with review 1.
`git diff a5c03fb..HEAD --check` passes. No PHP changes require new BC, PHPStan
or PSR-12 findings. No gate was lowered. No local PHPUnit/daemon run was started:
this assessment uses independent historical and current CI evidence, avoiding
shared-daemon interference described by FAQ-039. Only the two requested proof
files were written; no implementation changes, commits, pushes or helper edits.

## KB recommendations (proposal only)

Retain round 1's archive-validation candidate. Additional candidate:

- **Title:** TCP port-up is not post-reload HTTP readiness
- **Tags:** tests, daemon, process, ci
- **Trigger:** Assessing reload-test connection failures or adding readiness waits.
- **Entry:** A successful reload command confirms signal delivery, not worker
  readiness; a TCP connect can succeed during restart while the next HTTP request
  ends with an empty reply or reset. Compare the exact failing test/stack with
  pre-change CI logs and existing trackers before attributing a failure to a PR.
  For #810, cURL 52 on master and the documented cURL 56 cases identify the same
  readiness gap. Keep any eventual HTTP wait bounded with a final assertion;
  rerunning CI is evidence collection, not a fix or permission to waive gates.
