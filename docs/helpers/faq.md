# FAQ — Recurring Pitfalls and Solutions

Subagents: read this before starting a task, append new entries after
finishing (see [README.md](README.md) for rules).

## SFX downloads

### A failed-checksum or unusable artifact must be unlinked, or every later build fails the same way

`SfxDownloader::fetch()` short-circuits on `is_file($destination)` and re-verifies existing bytes, so a downloaded artifact that fails SHA-256 verification (or a zip with no usable SFX entry) poisons every subsequent fetch until removed by hand — the download is never retried. `fetch()` now unlinks the failed artifact on both paths (#642). Only the checksum path appends an explicit 'the failed artifact was removed' note to the exception message — and only when the unlink actually succeeded; zip-extraction failures rethrow the original exception (type and message preserved) after unlinking. Same class of behavior as `writeStream()`, which unlinks the partial artifact on transfer abort (e6fa1b2, #585): never leave bytes behind that a later run will trust.

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

### Testing an `inotify_add_watch()` failure without exhausting watch limits

To exercise `InotifyMonitorWatcher::watchDir()`'s failure branch, queue a
`IN_CREATE|IN_ISDIR` event and delete the directory before the event is
processed: `inotify_add_watch()` then fails deterministically with ENOENT.
Do not try to exhaust `/proc/sys/fs/inotify/max_user_watches` — it is
host-specific and slow. (Inotify events are queued synchronously at syscall
time, so `mkdir` → `rmdir` → `invokeOnNotify` is race-free.)

### grpc extension on macOS: stop timeouts, zombie masters, no worker restarts

If the `grpc` extension is loaded (Homebrew PHP), its shutdown handler
(`grpc_shutdown()`) **can hang indefinitely in forked children** on
affected macOS/Homebrew setups. On such hosts (tracked in
[#651](https://github.com/crazy-goat/workerman-bundle/issues/651)):

- The daemonize intermediate can hang at `start -d`, so
  `workerman:server stop` can time out and leave a zombie master. Clean
  up with a **repo-scoped** command (never a bare `pkill -f WorkerMan` —
  it would kill unrelated Workerman applications on the host):
  ```bash
  pkill -9 -f 'tests/App/index.php'; rm -f var/run/workerman.pid*
  ```
- A self-called `exit()` inside a process service hangs the worker (no
  restarts). The bundle terminates worker/task children with SIGKILL when
  grpc is loaded (`Util\ProcessTerminator`, since #650); make sure your
  process services do not `exit()` themselves — return instead.
- Expect **exactly one** start-up warning in the Workerman log file
  (`var/log/workerman.log`): with `GRPC_ENABLE_FORK_SUPPORT` unset it
  says to set it; with it set (or `true`) it announces SIGKILL
  termination. They are mutually exclusive, not both emitted.

CI (Linux, no grpc) does not exercise any of this.

### Don't write start-up warnings to stderr before daemonize()

`Runner`'s grpc warning must go to the Workerman log file only. Writing to
`STDERR` before `daemonize()` killed launchers spawned with a closed stderr
pipe under `proc_open` (SIGPIPE) — the daemon never started and
stop/reload commands failed. `Worker::log()` is equally unusable there: its
`safeEcho()` path reads `Worker::$outputStream`, which is only initialized
inside `runAll()` (feof() on null).

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

Composer runs `test` scripts with a 300 s process timeout; on slow hosts
(notably grpc/macOS, see the grpc section above) PHPUnit can be killed
mid-run. Raise it with `COMPOSER_PROCESS_TIMEOUT=1800 composer test` or
set `config.process-timeout` in `composer.json`.

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

## Control plane / master identification

### `stop`/`reload`/`status` report "Workerman is not running." after a 0.25.0 upgrade

Master identification fails closed since 0.25.0 (issue #584): without the
`.fingerprint` sidecar next to the pid file, control commands refuse to
signal. This bites in three situations: (1) the server was started by an
older version and the code was upgraded without stopping it first — stop
*before* upgrading; (2) macOS/BSD, where the cmdline fallback does not
exist — the fingerprint (written on every 0.25.0+ start) is the only
identity check, so a single restart restores the control plane; (3) the
instant after `start -d` returns, before the master writes pid file and
fingerprint — wait for both files to appear, then retry. Recovery when
the old master cannot be verified: read the PID from the pid file, verify
with `ps -p <pid> -o pid,comm,args`, kill it by hand (never a bare
`pkill -f WorkerMan`), remove any stale pid file, and start once. Full
guidance: UPGRADE.md "Upgrading to 0.25" (issue #640).

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

## Scheduler / date-time

### JitterTrigger wraps the real trigger — unwrap it before type-checking the schedule

`TriggerFactory::create()` returns a `JitterTrigger($trigger, $jitter)` whenever `jitter > 0`, so
checks like `$trigger instanceof PeriodicalTrigger` silently miss jittered periodical schedules.
`schedulerCallback()` in `SchedulerWorker` unwraps via the `JitterTrigger::innerTrigger()`
accessor before applying the fixed-rate rebasing (issue #565), and `JitterTrigger` exposes
`innerTrigger()` for exactly this. Any future code branching on the trigger type must unwrap
`JitterTrigger` first — or jittered tasks silently take the wrong path.

### DateInterval has no fractional-second parser — set `f` yourself or use `'500 ms'`

`new \DateInterval('PT0.5S')` and `DateInterval::createFromDateString('0.5 seconds')` both throw
(`Unknown or bad format`). Fractional intervals only work via a unit name string
(`createFromDateString('500 ms')` → `s=0, f=0.5`) or by setting the property
manually (`$i = new \DateInterval('PT0S'); $i->f = 0.5;`). `DateTimeImmutable::add()`
honours `f` and preserves microseconds, so `format('U.u')` round-trips sub-second
precision. Used for the sub-second `PeriodicalTrigger` support (issue #565).

### `new \DateTimeImmutable('+1 second')` in a mock `willReturn` is evaluated once

A stub configured as `->willReturn(new \DateTimeImmutable('+1 second'))` returns the
same fixed absolute date on every call — the relative string is parsed once at
stub-configuration time. If the code under test calls `getNextRunDate()` repeatedly
(loop or rebound scheduling), stub relative to an injected argument instead, e.g.
`willReturnCallback(fn(\DateTimeImmutable $now) => $now->modify('+1 second'))`.

## Closures / garbage collection

### Mutual by-reference capture (`&$a` / `&$b`) between two closures is a reference cycle

Two closures that capture each other **by reference** can never be freed by
refcounting — only the cycle collector reclaims them. In a long-lived
Workerman worker that means ~2.4 KB of uncollectable garbage per download and
a forced full collection every ~3 300 events (or an unbounded leak under
`gc_disable()`). Solution: both closures capture one small shared state
object **by value** — capturing the same object handle is not a cycle — and
self-removal uses a flag on the state instead of identity comparison against
the closure itself. Corollary: never store a closure *inside* an object that
closure captures; that is a cycle again (store data, not handlers).
Reference: `BinaryFileResponseStrategy::scheduleFileCleanup()` +
`FileCleanupState` (issue #573).
