# FAQ — Recurring Pitfalls and Solutions

Subagents: read this before starting a task, append new entries after
finishing (see [README.md](README.md) for rules).

## Test suite

### "Address already in use" when running `composer test`

`composer test` boots a real Workerman daemon that binds ports **8888**
and **9999** for E2E tests. If a stale daemon (or anything else) is holding
those ports, PHPUnit fails with connection errors.

```bash
# Stop the daemon (safe even if not running):
php tests/App/index.php stop
```

### How the test suite works

```bash
composer test            # unit + E2E (no coverage)
composer test:coverage   # unit + E2E with coverage → var/coverage.xml
composer coverage:check  # enforces the 80% floor (see decisions.md)
```

The `test` script restarts the daemon with `-d` (daemon mode), sleeps 1s,
runs PHPUnit, then stops the daemon. Phpunit itself is run with
`php -d phar.readonly=0`. If tests were interrupted, stop the daemon
manually as above.

### CI enforces an 80% line-coverage floor

Defined once in `composer.json` (`coverage:check` →
`bin/check-coverage.php var/coverage.xml 80.0`) and checked on the
PHP 8.2 / Symfony 6.4 matrix leg. If a PR adds meaningful logic, verify
the gate locally (`composer test:coverage && composer coverage:check`) so
CI doesn't tell you first. Requires PCOV or Xdebug locally.

## Git hooks

### Pre-push hook runs `composer lint` before every push

Installed by `php bin/install-git-hook.php` (post-install/post-update).
Every push runs php-cs-fixer (dry-run), phpstan and rector (dry-run).
To skip in an emergency: `git push --no-verify`.

## GitHub CLI

### `gh issue list` returns at most 30 issues by default

Always raise the limit explicitly (`--limit 100`, max 1000) or paginate
with `--page N` — otherwise issues beyond the first page are silently
missed during triage.

## Long-running worker gotchas

### Symfony container / service state survives requests

Workerman keeps the kernel and DI container alive across requests, so any
stateful service (Doctrine `EntityManager` identity map, buffering Monolog
handlers, caching repositories, static/global state) leaks data between
requests. See [docs/troubleshooting.md](../troubleshooting.md) for
detection and mitigation (`kernel.reset`, `EntityManager::clear()`,
reload strategies).
