# FAQ — Recurring Pitfalls and Solutions

Subagents: read this before starting a task, append new entries after
finishing (see [README.md](README.md) for rules).

## Static files

### File-only rules must be gated on the last path component

`StaticFilesMiddleware` walks every relative-path component
(`isFilePathBlocked()`), so file-only rules — the `allowed_extensions`
allowlist and residue-extension blocking — must be applied only to the
final component (`array_key_last`, `$isFile` flag). Directory components
must not be evaluated by file-only rules even when their names contain dots
(`assets.dist/`, `backup.bak/`) — before the fix they were denied whenever
an allowlist was configured, making every subdirectory file 404 (issue
#637).

## Config cache

### Warm-as-root cache trips the ownership guard at boot

Since 0.25.0 the config cache file (`{cacheDir}/workerman/config.cache.php`)
must be owned by the process that loads it. Warming it as `root` in a
Docker build and starting the server as another user is a hard boot
`RuntimeException` (raised by the launcher process before any worker forks),
not a warning. Worked examples live in README (§ "Config cache and runtime
user") and security.md (§ "Containerised deployments (Docker)") — link to
them instead of restating the pattern in issues or PRs.

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

## Underscore header test fixtures need a literal `_` character

When building a raw HTTP header fixture to exercise the underscore-header
drop path, put a real `_` in the name (e.g. `X-Dropped_1`). Writing the
word `X-Underscore-1` produces hyphens only (`x-underscore-1`), which
parses as a normal header and sails past the drop filter — the test then
exercises nothing. See [decisions.md](decisions.md) for the bounded-logging
decision behind #638.

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

## Worker timer tests

When invoking `ServerWorker::onWorkerStart` directly in a unit test, initialize
`Workerman\Timer` with the test event loop first. Production initializes the
event loop before `onWorkerStart`; direct callback tests otherwise register
process-level alarm timers instead of timers on the test loop.

Timer-count assertions see an empty loop after `runEventLoopFor`:
`Select::stop()` calls `deleteAllTimer()`. And because the sweeper's
activity bookkeeping is second-granular (`time()`), "closed within X"
timeout tests must run the loop for more than two sweep intervals (e.g.
2.2 s with a 1 s interval) to avoid second-boundary phase flakes.

## Byte-oriented test helpers

PHPStan requires `chr()` arguments to be provably within `0..255`. When a test
helper accepts an integer byte, guard that range before calling `chr()` rather
than suppressing the diagnostic.

## Long-running worker gotchas

### Symfony container / service state survives requests

Workerman keeps the kernel and DI container alive across requests, so any
stateful service (Doctrine `EntityManager` identity map, buffering Monolog
handlers, caching repositories, static/global state) leaks data between
requests. See [docs/troubleshooting.md](../troubleshooting.md) for
detection and mitigation (`kernel.reset`, `EntityManager::clear()`,
reload strategies).

The `services_resetter` must run independently of `TerminableInterface` and
also on kernel/response exceptions. `terminateIfNeeded()` owns the reset and
must clear request references even when no kernel termination is available;
controller failure paths reset before rethrowing so the handler can still
send its error response (issue #572).

## Documentation claims vs. runtime support

### README used to list `tcp://` as a supported scheme, but `ListenScheme` rejects it

Older README versions and the `listen` node's `info()` text listed `tcp://` among the
supported URI schemes, but `ListenScheme::fromListen()` throws
`UnsupportedListenSchemeException` for it — `tests/Worker/ListenSchemeTest.php`
asserts `tcp://0.0.0.0:9090` is *invalid*. Only `http://`, `https://`,
`ws://` and `wss://` work. The README and the `info()` text now match the enum
(issue #590); keep them in sync with the
enum unless real `tcp://` support (a `Tcp` case with transport `tcp` and no
protocol) is added.
