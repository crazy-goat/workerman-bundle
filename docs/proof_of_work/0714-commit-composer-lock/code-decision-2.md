# Code decision — issue #714, round 2

## Authorized implementation

The main session corrected the earlier narrowed endpoint and authorized the necessary workflow changes. Per planner/oracle decision, both matrix jobs now use **full** `composer update --no-interaction --prefer-dist` after the existing Symfony rewrite. This preserves their formerly unlocked full-resolution behavior; lint and benchmark retain deterministic `composer install` from the committed lock. A targeted Symfony update was rejected because it would change the previous matrix resolution policy.

Track the existing root lock without regenerating it: its content hash is valid and actual PHP 8.2 installation verifies compatibility. Remove only the root `composer.lock` ignore entry, leaving `/e2e/composer.lock` untouched. `.gitattributes` and `composer.json` need no changes. The original ignored lock backup from round 1 remains available; its SHA-256 still matches the committed candidate.

## Regression protection

`tests/GithubWorkflowsTest.php` now extracts individual job blocks and asserts locked installs precede tools in lint/benchmark, and that each tests job rewrites constraints, fully updates, then tests. Neither job can accidentally borrow a matching command from another job. Tests do not freeze package versions in matrix-mutated locks. The other workflow consumer, `CoverageCiGateTest`, is included in verification. These executable guards supersede the automatable portion of round 1's candidate KB prose; no KB addition is requested.

The workflow guide documents PHP 8.2 lock refresh and disposable matrix resolution. CHANGELOG Unreleased records #714. No gate, matrix version, or platform requirement was weakened.

## Verification strategy

Install the candidate lock locally and in an isolated actual PHP 8.2.33 Docker environment (Composer 2.10.2), then check manifest hashes, platform requirements, audit and canonical lint/tests. Local PHP is 8.5.10 with Composer 2.10.3. Docker requires pcntl and zip extensions, a Git checkout for install hooks and repository-scanning tests, and more than its default 128 MB PHPStan memory budget; these are verification-environment provisions, not repository gate changes.

Full results and initial environment setup failures are appended to `findings-coder.md`. CI's full nine-leg matrix remains the final cross-version verification; local matrix probes do not claim equivalent coverage. Main session handles review, PR and merge.
