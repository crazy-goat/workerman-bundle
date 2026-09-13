# Findings — coder (#594)

## Blockers / obstacles

- `composer lint` **already fails on `origin/master`**, before this change.
  `vendor/bin/rector process --dry-run` wants to rewrite five test files
  (`NullToStrictStringFuncCallArgRector`):
  `tests/Strategy/StreamedResponseStrategyTest.php:452`,
  `tests/ProcessInspectorTest.php:1269`, and three more.
  Reproduced with the working tree stashed, so it is unrelated to this diff.
  This is the dependency-resolution drift that issue
  [#714](https://github.com/crazy-goat/workerman-bundle/issues/714)
  ("Commit composer.lock to prevent CI lint drift") exists to fix — already
  tracked, not filed again. `php-cs-fixer` and `phpstan` pass; `rector` on the
  two files touched here passes.

## Discovered bugs / places to improve (outside this issue's scope)

- `src/WorkermanBundle.php:62-77` — the `reload_strategy` shape is five nested
  strategy shapes inline in one docblock; it reads well as a set of aliases
  (`ExceptionStrategyConfig` etc.). The issue names this as an optional part of
  the same pass; deliberately deferred because there is no duplication yet and
  it would roughly double the docblock churn of this PR.
- `src/Worker/ServerWorker.php:47-53` — the three deprecated keys
  (`serve_files`, `root_dir`, `static_files`) are still read unconditionally by
  `configureHandler()` / the `onWorkerStart` closure. Now that the alias labels
  them legacy, a future change should either wire the deprecation warning into
  the configuration tree or delete the keys. No runtime change made here
  (docblock-only PR).
