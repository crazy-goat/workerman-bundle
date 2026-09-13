# Findings — coder (#594)

## Blockers / obstacles

- `composer lint` **already fails on `origin/master`**, before this change.
  `vendor/bin/rector process --dry-run` wants to rewrite five files
  (`NullToStrictStringFuncCallArgRector`):
  `bin/wait-for-ports.php:30`, `src/DTO/RequestConverter.php:349`,
  `tests/ExceptionUsageLintTest.php:269`, `tests/ProcessInspectorTest.php:1269`
  and `tests/Strategy/StreamedResponseStrategyTest.php:452`
  (only the last three are test files). Reproduced with the working tree
  stashed, so it is unrelated to this diff. This is the dependency-resolution
  drift that issue
  [#714](https://github.com/crazy-goat/workerman-bundle/issues/714)
  ("Commit composer.lock to prevent CI lint drift") exists to fix — already
  tracked, not filed again. `php-cs-fixer` and `phpstan` pass; `rector` on the
  two files touched here passes. The full test suite was also run with and
  without the change: identical results (2 errors, 4 failures, all pre-existing
  and environment-related).

## Discovered bugs / places to improve (outside this issue's scope)

- `src/WorkermanBundle.php:53-68` — the `reload_strategy` shape is five nested
  strategy shapes inline in one docblock; it reads well as a set of aliases
  (`ExceptionStrategyConfig` etc.). The issue names this as an optional part of
  the same pass; deliberately deferred because there is no duplication yet and
  it would roughly double the docblock churn of this PR.
- `src/Worker/ServerWorker.php:136-137` and `:185` — the three deprecated keys
  (`serve_files`, `root_dir`, `static_files`) are still read unconditionally by
  the `onWorkerStart` closure and `configureHandler()`. Now that the alias
  labels them legacy, a future change should either wire the deprecation
  warning into the configuration tree or delete the keys. No runtime change
  made here (docblock-only PR).
