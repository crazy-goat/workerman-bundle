# Critical review — issue #714, round 1

## Scope and trigger

Reviewed `37a646b4fa91a50d2f6799d88d70c3ac94da55ca` against
`origin/master` (`ac64d539c3813e7d34c3e6588e3ff79a9adb9a4f`). All nine changed
files were inspected, including generated dependency metadata and coder reports.
Mandatory critical-review trigger: **more than 200 changed lines** (5865
insertions / 5 deletions, primarily the 5658-line lock). The workflow change also
requires the FAQ-032 consumer sweep. No bundle public signature, HTTP handling,
process supervision implementation, or security policy is changed.

## Prior findings and helper decisions

Checked `findings-review.md` first: it did not exist; there are no earlier review
findings to reopen. Read helper tag indexes before relevant entries, including
FAQ-007, FAQ-010/011, FAQ-015, FAQ-028, FAQ-031/032/033/034/037 and
DEC-007/008/009/012/020/021. No documented-decision violation found:

- DEC-007: coverage remains 80.0, defined only in Composer, invoked once on the
  PHP 8.2 / Symfony 6.4 leg. No coverage or other gate was lowered.
- DEC-008/020: canonical `composer lint` and all its checks remain intact;
  PHPStan remains level 8. No source/style exceptions added.
- FAQ-032: `grep -rl tests.yaml tests/` finds `GithubWorkflowsTest` and
  `CoverageCiGateTest`; both were run, not just the changed class.
- FAQ-033/034: trigger/concurrency expressions are preserved. New job-local
  tests inspect raw YAML, not a YAML 1.1 boolean interpretation of `on`.
- FAQ-037: reviewed PHP syntax is valid on actual PHP 8.2.33; lock compatibility
  was checked on that runtime, not only simulated with Composer platform config.
- DEC-021/012: changelog addition is under Unreleased; refresh instructions are
  consistent with the implementation and renderable Markdown.

### Revisited coder observations

These are coder observations, not previously open review findings:

| Observation | Status and evidence |
| --- | --- |
| Lock breaks rewritten matrix manifests | **fixed** — both jobs now perform full update after the unchanged Symfony rewrite (`tests.yaml:150-163,213-226`); lint/benchmark still install. Coder's actual 8.2/6.4 update log confirms resolution and platform validation. |
| Installed vendor differs from proposed lock | **fixed** — reviewer host install dry-run reports nothing to change; native 8.2 image has identical manifest/lock hashes and also nothing to change. |
| Packagist transient transport failures | **still present** as an external availability possibility; no advisory suppression/retry change introduced. Reviewer locked audit passed. This is not a blocker for this diff. |
| Abandoned doctrine/annotations | **still present**, informational under unchanged abandoned=`report` policy; locked phpbench 1.7.0 requires it. Audit finds no vulnerability advisories. Not a new policy violation. |
| KB line budgets | **still present**, existing warnings (404/310 budgeted lines), canonical linter exits 0. No helper edits or gate changes in this review. |
| Native container full-suite failures | **not a real finding against this diff**; detailed failure classification and independent probes below. A full 8.2 suite is not claimed green. |

## Behavioral review

### Lock and platform

The lock contains 26 runtime and 52 dev packages, stable versions, no aliases,
no platform override, and no relaxed stability flags. PHP requirements across
all locked packages admit 8.2. Symfony components are 7.4; contracts stay on 3.x.
Tool versions include php-cs-fixer 3.95.21, phpbench 1.7.0, PHPStan 2.2.8,
PHPUnit 10.5.64 and Rector 2.6.2. All dist URLs are HTTPS GitHub URLs pinned to
references; the sole composer plugin is the already allowed Symfony runtime.
Root scripts/plugin permissions/audit policy are unchanged. Strict Composer
validation verifies the content hash. Lock SHA-256 is
`1b6bc7e971988fc2540fa1396e872e896bc96f197321a1f2cf84102f8cdf4db0`.

The root lock is tracked; `/e2e/composer.lock` remains ignored.
`.gitattributes` does not exclude the new lock and needs no change. A library's
lock does not constrain dependency resolution in consuming applications; no
consumer-facing constraint or signature BC change is introduced.

### Workflow and regression guards

Lint and benchmark install the committed lock on PHP 8.2. Audit still runs after
lint's install on every trigger. Regular and scheduled test jobs retain their
matrix/trigger policies, but explicitly perform full resolution after rewriting
root Symfony constraints. This preserves their previously unlocked resolution
semantics; a targeted Symfony-only update would instead freeze unrelated
packages. Neither job uses ignore-platform-requirements or bypasses security
blocking. The 6.4, 7.4 and 8.0 root constraints are disjoint, so install cannot
replace update in these jobs.

New tests extract each job up to the next two-space job heading and constrain
commands/order within that job, preventing cross-job false matches. They guard
both matrix jobs and both locked tool jobs without pinning matrix-mutated
package versions. Existing coverage consumer tests still pass. Documentation
correctly requires lock refresh on minimum PHP and describes matrix locks as
disposable rather than refresh candidates.

No new actionable implementation findings were identified.

## Independent checks

Host: PHP 8.5.10 / Composer 2.10.3. Native container: PHP 8.2.33 / Composer 2.10.2,
using the coder's existing installed image, with manifest and lock hashes
independently matched to this checkout. Containers were disposable (`--rm`).

| Check | Result |
| --- | --- |
| Host `composer validate --strict` | Pass |
| Host install dry-run | Pass; nothing to install/update/remove |
| Host `composer check-platform-reqs` | Pass |
| Host `composer audit --locked` | Pass; no vulnerability advisories, one informational abandoned dev package |
| Host `composer lint` | Pass: style, PHPStan level 8, Rector, KB, changelog and exception-usage checks |
| Host workflow consumer sweep | Pass: 24 tests / 96 assertions |
| Native PHP 8.2 validate, install dry-run and platform checks | Pass; exact same lock, nothing to change |
| Native PHP 8.2 `php -l tests/GithubWorkflowsTest.php` | Pass |
| Native PHP 8.2 full `composer lint` | Pass with container-only memory_limit=1G; no gate edits |
| Native PHP 8.2 workflow consumer sweep | Pass: 24 tests / 96 assertions |
| Native PHP 8.2 `composer bench` | Pass: 24 subjects, zero failures/errors, aggregate report renders |
| Native PHP 8.2 five permission-fixture tests, unprivileged | Pass unchanged: 5 tests / 7 assertions |
| `git diff --check origin/master...HEAD` | Pass |

Full host suite result is recorded in the completion section below. Full
nine-leg CI and the actual coverage percentage remain CI verification, not
claimed by this review. Coder's 7.4/8.0 dry-run probes are solver evidence only.

## Assessment of native PHP 8.2 full-suite failures

Inspected the coder's `php82-full.log`: 2 provider errors, 24 failures, one
warning; not a green full-suite result. The causes account for all errors and
failures without attributing them to the lock:

1. Empty Git index: two Markdown providers and `LintScopeTest:71` cannot obtain
   tracked files. The installed Docker image also lacks `.git`; an initial
   reviewer `git add` attempt failed (exit 128), then `git init` plus staging
   inside the disposable container repaired the environment. The changed test
   classes pass with the populated index.
2. Eighteen process-helper tests assume static pcntl/shared posix
   (`RunnerTest:1107-1112`, `SchedulerWorkerSigchldTest:203-208`,
   `ProcessTerminatorTest:62-67`). Reviewer independently verified this image's
   `php -n` has posix=true, pcntl=false. The unchanged isolated runner test fails
   with undefined pcntl_fork; invoking that same fixture with `php -n -d
   extension=pcntl` passes. This is a pre-existing harness portability issue,
   not a PHP 8.2 parse/dependency failure.
3. Five permission fixtures run as root, defeating chmod-based unreadability
   and unlink failures (`ServerWorkerTest:101,125`,
   `BinaryFileResponseStrategyTest:546,1009`,
   `StreamedBinaryFileResponseTest:303`). Reviewer ran all five unchanged as
   `nobody` in a container: 5 tests / 7 assertions pass.

The suitable follow-up is a real indexed, unprivileged checkout with the
expected extension layout, or a separately reviewed portability improvement to
the helpers. Do not disable tests, weaken production permission checks, or
lower gates. These probes do not substitute for a green full CI run.

## Reviewer-run interference and candidate helper entry

The first reviewer host full suite failed (2689 tests, 28 errors / 7 failures)
because a parallel targeted PHPUnit invocation stopped its shared daemon.
`tests/App/bootstrap.php:14-15` starts the daemon and unconditionally registers
`workerman_stop`; lines 55-60 stop it and remove marker files. The daemon log
shows another start observing 'already running', followed by a normal stop
command/SIGINT while the full suite was still executing. This is reviewer
command interference, not evidence of a branch regression. The full suite was
then rerun alone with unchanged code/dependencies. No further host PHPUnit
commands were run concurrently.

Candidate only (no KB edit):

- **Title:** Even filtered PHPUnit runs own the shared daemon shutdown hook.
- **Tags:** tests, daemon, process.
- **Trigger:** Parallelizing targeted PHPUnit checks and `composer test` in the
  same checkout.
- **Paragraph:** The global PHPUnit bootstrap starts the test application and
  registers an unconditional shutdown callback. A workflow-only PHPUnit filter
  therefore attaches to an existing daemon and stops it when the short run
  finishes, causing an overlapping full suite to fail with connection-refused
  errors and stale/missing process markers. Serialize PHPUnit invocations in a
  checkout, or isolate both filesystem/PID state and network ports; parallel
  lint or containers with isolated networking do not share this teardown path.

## Completion and verdict

**Clean review: no new actionable findings or blockers in this diff.**

The controlled, non-overlapping host rerun of
`COMPOSER_PROCESS_TIMEOUT=1800 composer test` passed, exit 0:
**2689 tests / 18243 assertions / 4 deprecations / 42 skips**. This confirms the
first review run's daemon loss was invocation interference. Full output is in
review shell log `sh_0b0a98c8a001UP0Zy6VtMTyZZQ.out`; the invalidated first run is
`sh_0b0a7390a00241EDQVgHu7TaxK.out`. Native 8.2 lint/workflow/benchmark output is
`sh_0b0a8792d001nFUzEq8V9KFAWu.out` (session shell-log storage).

Main session may commit these proof files and proceed to PR/CI after its local
gate policy. The native PHP 8.2 full-suite container run remains explicitly
non-green; it is not represented as a full CI matrix or coverage pass. No source,
workflow, dependency, test assertion, helper entry, or gate was edited by the
reviewer. Only the requested review artifacts were written; no commit or push.
