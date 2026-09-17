# Coder findings — issue #714

## Round 1 — blocked, 2026-09-17

### Biggest obstacle: lock tracking conflicts with CI's matrix install flow

**Files:** `.github/workflows/tests.yaml:150-158` and `:210-219`.

Both regular and scheduled tests rewrite `composer.json` Symfony requirements, then invoke `composer install`. Without a tracked lock, that resolves dependencies; with one, Composer requires the locked versions to satisfy the rewritten constraints. The current lock contains Symfony 7.4. Reproducing the 6.4 rewrite in an isolated copy makes `composer install --dry-run --no-interaction --prefer-dist` exit **4**, naming eight incompatible root Symfony requirements. Choosing a 6.4 lock instead would break 7.4/8.0 legs. This is not a stale-lock-only problem. A verified fix requires changing the workflow, exceeding the requested lock-only endpoint/stop condition.

**Suggested fix:** preserve deterministic install in lint/benchmark, but explicitly resolve matrix Symfony constraints in both tests jobs; evaluate a targeted Symfony update with transitive dependencies and add workflow regression assertions. Do not weaken platform, audit, lint or coverage gates. Obtain authorization for that broader change before claiming #714 fixed.

### Other findings / improvements

1. **Installed dependencies differ substantially from the ignored lock.** `composer.lock:1` (whole dependency set), `.gitignore:2`. Current-platform dry-run plans 34 updates and one removal, including Symfony 8.1 → 7.4 and php-cs-fixer 3.95.8 → 3.95.21. Therefore the successful local lint run does **not** verify lint under the proposed locked tool versions. Suggested fix: after resolving the workflow blocker, actually install the selected lock and rerun canonical lint/tests before commit. No actual install was performed here to avoid disturbing the environment for a blocked change.

2. **Network-dependent audit test can fail on transient upstream service errors.** `tests/ComposerAuditE2ETest.php:104-136`. First run failed two tests because Packagist returned HTTP/2 502 (Composer exit 100); an unchanged rerun passed all three tests. Suggested improvement: distinguish external availability from assertions and consider a bounded retry for recognized transport failures only, preserving hard failure for actual advisories/invalid responses. Do not silently skip audit or use ignore-unreachable as a workaround.

3. **Abandoned transitive dev dependency.** `composer.lock:2033-2106` records abandoned `doctrine/annotations`; Composer prints this during dry-run. Suggested improvement: review the phpbench dependency path and upgrade/replace the upstream dependency when available. Existing `composer.json` intentionally uses audit abandoned=`report`; no gate change proposed.

4. **KB line-budget warnings remain outside scope.** `docs/helpers/faq.md:1` and `docs/helpers/decisions.md:1` (whole-file budgets). `composer lint` reports 404 and 310 budgeted lines respectively against a 300-line budget, but exits 0. Suggested fix: main-session retro should promote/drop redundant entries according to KB policy; coder made no KB edits.

### Verification performed

Environment: PHP 8.5.10, Composer 2.10.3; existing installed tools/dependencies were not changed.

| Command / check | Result |
| --- | --- |
| `composer validate --strict` | Exit 0; valid JSON and lock content hash |
| `composer install --dry-run --no-interaction --prefer-dist` | Exit 0; 34 updates, one removal planned, not executed |
| Copied project: `composer config platform.php 8.2.0`, then install dry-run | Exit 0; 78 installs planned; expected hash warning because only the copied manifest changed. Metadata simulation, not native PHP 8.2 execution |
| Copied project: restore original manifest, rewrite non-contract Symfony requirements to `6.4.*`, then install dry-run | **Exit 4**, eight root Symfony constraints incompatible with lock |
| `composer audit --locked --format=summary` | Exit 0; no security vulnerability advisories |
| `composer lint` | Exit 0 on existing vendor tree; two KB line-budget warnings |
| `vendor/bin/phpunit --no-coverage tests/ComposerAuditE2ETest.php` | Initial exit 1: two Packagist HTTP/2 502 failures; unchanged rerun exit 0, 3 tests / 7 assertions |

An attempted standalone PHP semver script using Composer's presumed PHAR autoload path failed (exit 255: path unavailable); it is not counted as verification. The successful platform-configured Composer install dry-run is the compatibility evidence instead.

No full daemon suite, actual install, or native PHP 8.2 test was performed because the matrix install blocker already rules out the requested lock-only commit. No gates were lowered. Original lock backup and SHA-256 are recorded in `code-decision-1.md`.

### Candidate helper entry (proposal only)

**Title:** Committing a lock requires separating lint installs from matrix dependency resolution.

**Tags:** ci, lint, php82, tests.

**Trigger:** Adding/updating composer.lock where CI rewrites dependency constraints.

**Paragraph:** Before tracking a lock, inspect every install job: a job that rewrites Symfony constraints then runs install only works without a lock. Once locked, disjoint matrix versions require explicit resolution in the test jobs while lint remains on install to prevent tool drift. Dry-run the rewritten manifests in isolated copies, check the minimum supported PHP platform, preserve the existing ignored lock before regeneration, and run tools after actual install rather than trusting a mismatched vendor tree.

### Files changed / commit status

Only `docs/proof_of_work/0714-commit-composer-lock/code-decision-1.md` and this file were created. No implementation diff, changelog claim, commit, push, PR or merge. Per the explicit stop condition, these evidence files are left uncommitted for the main session to inspect.

## Round 2 — authorized implementation and verification

The main session corrected the scope and authorized CI/docs changes. Round 1's blocker is resolved by full matrix-only updates, not by weakening any gate. Root lock is tracked unchanged; lint/benchmark still install it. Both matrix jobs fully update after the existing rewrite. Job-scoped tests enforce policy and ordering without freezing dependency versions. `/e2e/composer.lock` stays ignored; `.gitattributes` and `composer.json` are untouched.

### Biggest obstacle this round

Obtaining representative native PHP 8.2 verification while the host only has PHP 8.4/8.5. Docker provided actual PHP **8.2.33**, Composer **2.10.2**, pcntl/zip and the required existing extensions. Several harness assumptions needed diagnosis: the Docker daemon could not see the host bind mount (used `docker cp` instead); a copied tree needed `git init` for `bin/install-git-hook.php:21`; PHPStan exhausted the container's default 128 MB memory budget (reran with a container-only `memory_limit=1G`). No repository configuration/gate changed for these environment repairs.

### Exact verification results

- Host PHP 8.5.10 / Composer 2.10.3: `composer install --no-interaction --prefer-dist` **passed** (34 updates, one removal applied). SHA-256 before/after confirms **both composer.json and composer.lock unchanged**.
- Host: `composer validate --strict`, `composer check-platform-reqs`, `composer audit --locked`, `composer lint` all **exit 0**, now against installed locked dependencies. Audit reports no vulnerability advisories; abandoned doctrine/annotations remains informational under unchanged policy.
- Host: `vendor/bin/phpunit --no-coverage --filter 'GithubWorkflowsTest|CoverageCiGateTest'` **24 tests, 96 assertions**, exit 0; these are the only two workflow consumers from `grep -rl tests.yaml tests/`.
- Host: `COMPOSER_PROCESS_TIMEOUT=1800 composer test` **exit 0**, **2683 tests, 18225 assertions, 4 deprecations, 42 skips**. Real daemon started; the stop script ran. No address-in-use failure.
- Actual Linux PHP 8.2.33: `composer validate --strict`, install dry-run, actual `composer install --no-interaction --prefer-dist`, `composer check-platform-reqs`, `composer audit --locked` **passed**. The first actual install downloaded/extracted all 78 locked packages but its post-install hook failed because the copy lacked `.git`; after `git init`, install including hook passed. SHA-256 checks verified manifest and lock unchanged.
- Actual PHP 8.2.33: full `composer lint` **passed** with container-only 1G PHP memory limit; all canonical checks ran. The default 128M attempt failed from memory exhaustion, not analysis findings.
- Actual PHP 8.2.33: both workflow test classes **passed**, 24 tests / 96 assertions, after staging the copied tree to satisfy repository-scanning data providers.
- Actual PHP 8.2.33 matrix probe: original Symfony 6.4 rewrite followed by **full actual `composer update --no-interaction --prefer-dist`**, strict validation and platform checks **passed**. Explicit GithubWorkflowsTest run then passed 16 tests / 88 assertions. All matrix edits were inside the disposable container.
- PHP 8.5.10 isolated copied-manifest probes for Symfony **7.4** and **8.0**: full `composer update --dry-run --no-interaction --prefer-dist` **exit 0** for both. These are solver probes, not complete native matrix executions.
- `git diff --check` passed; `git check-ignore -v e2e/composer.lock` still resolves to the dedicated ignore entry; root lock is no longer ignored.

**Additional full PHP 8.2 container suite did NOT pass:** 2085 tests / 16273 assertions, 2 errors, 24 failures, 1 warning, 4 deprecations, 35 skips. This is explicitly not claimed green. Failures were environmental/pre-existing harness assumptions: empty Git index omitted Markdown providers and failed tracked-path checking; running as root defeated unreadable/unlink permission fixtures; subprocess tests use `php -n` and assume statically compiled pcntl, but official Docker PHP builds pcntl as a shared extension. Native locked PHP 8.2 install/lint and targeted changed tests are green; host locked full suite is green. Full nine-leg CI including coverage remains for the main session. No coverage floor was modified or locally claimed verified.

Logs and Docker build recipe remain under the round-1 probe directory `issue-714.cIms2u` (`php82-verification.log`, `php82-gates.log`, `php82-full.log`, `php82-workflow-tests.log`, `php82-matrix64-final.log`, `matrix-7.4.log`, `matrix-8.0.log`). Original lock backup preserved.

### Newly observed out-of-scope weaknesses

- `tests/SchedulerWorkerSigchldTest.php:183-207`, `tests/RunnerTest.php:1100-1131`, `tests/ProcessTerminatorTest.php:45-82`: isolated processes assume pcntl is static and posix is shared. Official Docker PHP has the inverse arrangement here; `php -n` drops pcntl and `extension=posix` fails. Suggested fix: detect static/shared extension loading and pass required module paths explicitly while continuing to exclude grpc. Do not suppress these tests or weaken assertions to hide the portability issue.
- `tests/MarkdownLinkTest.php:40-50`, `tests/LintScopeTest.php:68` tracked-path check: repository scanning requires a populated Git index, not just files plus `git init`. Suggested verification fix: stage the isolated copied tree or use a real clone. No product change required.
- Permission fixtures in `tests/ServerWorkerTest.php:101,125` (unreadable certificate/key tests), `tests/Strategy/BinaryFileResponseStrategyTest.php:546,1009` (unlink warnings), and `tests/StreamedBinaryFileResponseTest.php:303` assume a non-root user. Suggested verification fix: run full container tests unprivileged; don't change production behavior or gates.
- Round 1 findings (abandoned annotations, transient Packagist availability, KB budgets) remain; local vendor drift is now resolved by actual install.

### Review handoff and KB policy

Attempted to request mandatory deep review for the large generated-lock diff, but nested subagent spawning is blocked by depth limit 1. **Main session must arrange review-critical** before PR/merge. No review result is claimed.

No new KB prose requested: executable job-scoped guards and the workflow documentation replace the automatable lesson proposed in round 1. No KB edits made.

### Changed files

`.gitignore`, `composer.lock`, `.github/workflows/tests.yaml`, `tests/GithubWorkflowsTest.php`, `CHANGELOG.md`, `docs/workflow.md`, and three proof files (`code-decision-1.md`, `code-decision-2.md`, `findings-coder.md`). Round 1 evidence retained as historical rather than overwritten. Commit/push follows verification; main session owns PR/merge.

Final staged-document/workflow sweep: `vendor/bin/phpunit --no-coverage --filter 'MarkdownLinkTest|GithubWorkflowsTest|CoverageCiGateTest'` passed **633 tests / 2055 assertions**, including all three new proof files in the Git-index-backed Markdown checks.
