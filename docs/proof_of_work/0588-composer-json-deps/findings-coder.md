# Findings (coder) — Issue #588: composer.json dependency hygiene

## Obstacles and surprises

- **S-1 — The CI matrix `sed` would have broken on the new line.** The
  `Update Symfony constraints` step in `.github/workflows/tests.yaml:150-153`
  and `:209-212` rewrote every `"symfony/*"` constraint to the leg version.
  `symfony/deprecation-contracts` has no 6.x/7.x/8.x line (verified: versions
  are 2.x/3.x only), so adding it to `require` without touching the workflow
  fails `composer install` on all nine matrix legs. Fixed in this round with
  a `/deprecation-contracts/!` address + comment. Lesson: any future
  `symfony/*` require whose major line does not track the framework (any
  `*-contracts` package) needs the same treatment — the `sed` assumes
  framework-versioned packages.
- **S-2 — BSD `sed` cannot run the CI pattern locally.** macOS `sed` lacks
  `\s` (even with `-E`) and needs `-i ''`, so the workflow's exact command
  fails locally with `\1 not defined in the RE` (first attempt) or silently
  rewrites nothing (second attempt). Simulated with `[[:space:]]` in place
  of `\s` to prove the address logic; the committed pattern is unchanged GNU
  `sed` for the ubuntu runners. A `gsed` install or a PHP-based rewriter
  would make this locally testable.
- **S-3 — Local `composer validate --strict` initially failed on the
  gitignored lock.** `composer.lock` is not committed (`.gitignore:2`), but a
  stale local copy made `--strict` exit 2 after the `composer.json` edit.
  `composer update --lock` (hash refresh only, no re-resolve) fixed it.
  Nothing committed; noting it because any local `composer lint` after a
  manifest edit hits the same thing.

## Bugs / weak spots found (in scope)

- **F-1 — Issue's file counts are slightly stale (docs only, no fix
  needed).** `symfony/http-foundation` is imported by **10** files in
  `src/`, not 9 (`src/DTO/RequestConverter.php`,
  `src/Http/Response/RequestMethodAwareResponseConverterStrategyInterface.php`,
  `src/Http/Response/ResponseConverter.php`,
  `src/Http/Response/ResponseConverterStrategyInterface.php`,
  `src/Http/Response/Strategy/BinaryFileResponseReflector.php`,
  `src/Http/Response/Strategy/BinaryFileResponseStrategy.php`,
  `src/Http/Response/Strategy/DefaultResponseStrategy.php`,
  `src/Http/Response/Strategy/StreamedResponseStrategy.php`,
  `src/Middleware/SymfonyController.php`,
  `src/Protocol/Http/Response/StreamedBinaryFileResponse.php`). Newer
  response-path classes landed after the issue's audit. Conclusion unchanged.
- **F-2 — `Symfony\Contracts\*` imports belong to `-contracts` packages the
  manifest still does not name.** Only 2 files import
  `Symfony\Component\EventDispatcher` (`src/Scheduler/TaskErrorListener.php`,
  `src/Supervisor/ProcessErrorListener.php`); 7 more import
  `Symfony\Contracts\EventDispatcher\*` (`src/Supervisor/ProcessHandler.php`,
  `src/Scheduler/TaskHandler.php`, all four `src/Event/*Event.php`,
  `src/DependencyInjection/WorkermanCompilerPass.php`) and 1 imports
  `Symfony\Contracts\Service\ResetInterface`
  (`src/Middleware/SymfonyController.php:17`). Those classes ship in
  `symfony/event-dispatcher-contracts` and `symfony/service-contracts`,
  which remain transitive (via `symfony/event-dispatcher` and
  `symfony/dependency-injection` respectively). Suggested fix: let the
  planned `composer-require-checker` step (issue acceptance criteria) decide
  — if it flags unknown symbols, declare the two `-contracts` packages
  explicitly and extend the `tests.yaml` `sed` exclusion to cover them.

## Bugs / weak spots found (outside this issue's scope)

- **F-3 — `src/Utils.php:76` `trigger_deprecation()` is still unguarded.**
  Declaring `symfony/deprecation-contracts` removes the realistic failure
  mode, but a `function_exists()` guard would make `Utils::reboot()` robust
  by construction instead of by manifest. Suggested fix: wrap the call or —
  better, per the issue — add the test asserting the deprecation is emitted,
  which exercises the dependency in CI. File/line: `src/Utils.php:76`.
- **F-4 — `tests/StreamedBinaryFileResponseTest.php:59,74,89` references
  `symfony/mime` (`MimeTypes::class`) which is in neither `require` nor
  `require-dev`.** It is `class_exists()`-guarded so the suite passes either
  way, but the tests silently skip their assertions when the package is
  absent — coverage depends on whatever the environment happens to have.
  Suggested fix: add `symfony/mime` to `require-dev` so the guarded branches
  always execute in CI.
- **F-5 — No test pins the matrix `sed` against `composer.json`.** Both
  `Update Symfony constraints` steps embed a regex that must stay in sync
  with every `symfony/*` line's versioning scheme (see S-1); today nothing
  fails locally if they drift — the breakage appears only as red matrix legs
  after push. Suggested fix: extend `tests/GithubWorkflowsTest.php` with a
  test that applies the workflow's rewrite logic to `composer.json` and
  asserts each rewritten constraint matches a published version line (or at
  minimum that `deprecation-contracts`-style exclusions cover every
  `symfony/*` entry not on the `^6.4|^7.0|^8.0` scheme).
- **F-6 — `composer audit` reports 11 advisories on dev-only Guzzle
  packages (pre-existing, unrelated to this change).** `guzzlehttp/guzzle`
  (9 advisories, incl. CVE-2026-69245/69246 host/cookie-scope bypasses,
  fixed in 7.15.2/8.0.1) and `guzzlehttp/psr7` (2 advisories). Both are
  `require-dev` only (`composer.json` requires `guzzlehttp/guzzle ^7.8`),
  so shipped consumers are unaffected. Suggested fix: `composer update
  guzzlehttp/guzzle guzzlehttp/psr7` in a separate chore (verify the
  e2e/http tests still pass — Guzzle is used by the test client).
