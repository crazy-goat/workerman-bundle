# FAQ — Recurring Pitfalls and Solutions

**How to read this file:** load the tag index below, pick the tags that match
the files in your diff, then read only those `###` entries. Do not read the
whole file.

**Who writes here:** only the retro step. Implementation and review subagents
*propose* candidate entries in their report — they never append (see
[README.md](README.md)).

## Tag index

<!-- kb-index:start -->
- `bc` — FAQ-002
- `benchmarks` — FAQ-026
- `binary-file` — FAQ-002
- `checksum` — FAQ-003
- `ci` — FAQ-011
- `closures` — FAQ-023
- `config` — FAQ-024
- `config-cache` — FAQ-005
- `content-length` — FAQ-001
- `control-plane` — FAQ-016
- `coverage` — FAQ-010, FAQ-011
- `daemon` — FAQ-007, FAQ-008, FAQ-009
- `date-time` — FAQ-021, FAQ-022
- `deprecation` — FAQ-024
- `docker` — FAQ-005
- `docs` — FAQ-019
- `download` — FAQ-003
- `gc` — FAQ-023
- `gh` — FAQ-017
- `git-hooks` — FAQ-015
- `grpc` — FAQ-007
- `head` — FAQ-001, FAQ-002
- `headers` — FAQ-012, FAQ-025
- `http` — FAQ-001, FAQ-002, FAQ-012, FAQ-025
- `inotify` — FAQ-006
- `jitter` — FAQ-020
- `lint` — FAQ-015
- `listen-scheme` — FAQ-019
- `logging` — FAQ-008
- `long-running` — FAQ-018
- `macos` — FAQ-007
- `master` — FAQ-016
- `memory` — FAQ-023
- `middleware` — FAQ-004
- `mocks` — FAQ-022
- `permissions` — FAQ-005
- `phpstan` — FAQ-014
- `ports` — FAQ-009
- `response-strategy` — FAQ-001, FAQ-002
- `scheduler` — FAQ-020, FAQ-021
- `sfx` — FAQ-003
- `state` — FAQ-018
- `static-files` — FAQ-004
- `streamed-response` — FAQ-002
- `tests` — FAQ-006, FAQ-007, FAQ-008, FAQ-009, FAQ-010, FAQ-011, FAQ-012, FAQ-013, FAQ-014, FAQ-022, FAQ-025, FAQ-026
- `timers` — FAQ-013
- `triage` — FAQ-017
- `upgrade` — FAQ-016
- `vendor` — FAQ-025
<!-- kb-index:end -->

## HTTP responses

### HEAD + app-set Content-Length: re-adding the header duplicates it; rewrite the serialized tail instead
<!-- kb: id=FAQ-001 date=2026-08-10 tags=http,head,content-length,response-strategy trigger="changing HEAD handling or Content-Length in src/Http" hits=0 status=active -->

Workerman's `Response::__toString()` **unconditionally appends** its computed `Content-Length: <strlen(body)>` (only `Transfer-Encoding` input suppresses it), so for a HEAD response (empty body) merely un-stripping the application's Content-Length in `ResponseConverter` emits *two* conflicting `Content-Length` headers — the exact #579 desync hazard. The fix (#643) keeps the strip for non-HEAD, preserves the app value for HEAD, and `DefaultResponseStrategy` hands it to `HeadResponse` (a `WorkermanResponse` subclass) which rewrites the trailing computed value at serialization time. Also: `BinaryFileResponseStrategy` must re-strip the preserved header or `Http::encode()`'s `array_merge_recursive` duplicates it again; Symfony's `prepare()` already removes Content-Length for 1xx/204/304 before the converter sees the response, so no extra guards were needed there.

### BinaryFileResponse HEAD: setContent(null) is a no-op, so withFile() streams the body — thread the method into the strategy
<!-- kb: id=FAQ-002 date=2026-08-10 tags=http,head,binary-file,streamed-response,response-strategy,bc trigger="touching a response strategy, BinaryFileResponse or StreamedResponse" hits=0 status=active -->

`BinaryFileResponse::setContent(null)` (called by Symfony's `prepare()` for HEAD) returns `$this` without detaching the file, unlike `StreamedResponse` which sets a `streamed` flag. So the #643 Content-Length fix did not help the file path: `BinaryFileResponseStrategy::convert()` still called `withFile()`, and Workerman's `Http::encode()` — which receives only a `Response`, not the `Request` — read and sent the whole file (`$length = 0` means "whole file" in `withFile()`/`encode()`, not "zero bytes"). The strategy interface `convert()` did not receive the request method at all. The fix (#683) adds an opt-in `RequestMethodAwareResponseConverterStrategyInterface` (extends the base interface, redeclares `convert()` with a `$requestMethod` arg); `ResponseConverter` dispatches on `instanceof` so the base `ResponseConverterStrategyInterface` — and any external/custom strategy — stays backward-compatible (adding a param to the base interface is a hard BC break: PHP rejects implementors that omit it even when it has a default). For HEAD the file strategy emits a bodyless `HeadResponse` (Content-Length = the size `prepare()` set; `Range` is `GET`-only) and never calls `withFile()`; a `deleteFileAfterSend` file is unlinked synchronously because the `onBufferDrain` cleanup the GET path relies on does not fire for a bodyless response. `StreamedResponseStrategy` sends only the head for HEAD (no chunked terminator `0\r\n\r\n`, which is a 5-byte message body) and never runs the stream callback.

## SFX downloads

### A failed-checksum or unusable artifact must be unlinked, or every later build fails the same way
<!-- kb: id=FAQ-003 date=2026-08-10 tags=sfx,download,checksum trigger="editing SfxDownloader or any download/verify path" hits=0 status=active -->

`SfxDownloader::fetch()` short-circuits on `is_file($destination)` and re-verifies existing bytes, so a downloaded artifact that fails SHA-256 verification (or a zip with no usable SFX entry) poisons every subsequent fetch until removed by hand — the download is never retried. `fetch()` now unlinks the failed artifact on both paths (#642). Only the checksum path appends an explicit 'the failed artifact was removed' note to the exception message — and only when the unlink actually succeeded; zip-extraction failures rethrow the original exception (type and message preserved) after unlinking. Same class of behavior as `writeStream()`, which unlinks the partial artifact on transfer abort (e6fa1b2, #585): never leave bytes behind that a later run will trust.

## Static files

### File-only rules must be gated on the last path component
<!-- kb: id=FAQ-004 date=2026-08-09 tags=static-files,middleware trigger="changing StaticFilesMiddleware path or extension rules" hits=0 status=active -->

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
<!-- kb: id=FAQ-005 date=2026-08-09 tags=config-cache,permissions,docker trigger="config cache ownership, Docker/container deployment" hits=0 status=active -->

Since 0.25.0 the config cache file (`{cacheDir}/workerman/config.cache.php`)
must be owned by the process that loads it. Warming it as `root` in a
Docker build and starting the server as another user is a hard boot
`RuntimeException` (raised by the launcher process before any worker forks),
not a warning. Worked examples live in README (§ "Config cache and runtime
user") and security.md (§ "Containerised deployments (Docker)") — link to
them instead of restating the pattern in issues or PRs.

## Test suite

### Testing an `inotify_add_watch()` failure without exhausting watch limits
<!-- kb: id=FAQ-006 date=2026-08-10 tags=tests,inotify trigger="writing tests for InotifyMonitorWatcher" hits=0 status=active -->

To exercise `InotifyMonitorWatcher::watchDir()`'s failure branch, queue a
`IN_CREATE|IN_ISDIR` event and delete the directory before the event is
processed: `inotify_add_watch()` then fails deterministically with ENOENT.
Do not try to exhaust `/proc/sys/fs/inotify/max_user_watches` — it is
host-specific and slow. (Inotify events are queued synchronously at syscall
time, so `mkdir` → `rmdir` → `invokeOnNotify` is race-free.)

### grpc extension on macOS: stop timeouts, zombie masters, no worker restarts
<!-- kb: id=FAQ-007 date=2026-08-09 tags=tests,grpc,macos,daemon trigger="daemon hangs, zombie master or no worker restart on macOS" hits=0 status=active -->

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
<!-- kb: id=FAQ-008 date=2026-08-09 tags=tests,daemon,logging trigger="adding start-up output in Runner or anything before daemonize()" hits=0 status=active -->

`Runner`'s grpc warning must go to the Workerman log file only. Writing to
`STDERR` before `daemonize()` killed launchers spawned with a closed stderr
pipe under `proc_open` (SIGPIPE) — the daemon never started and
stop/reload commands failed. `Worker::log()` is equally unusable there: its
`safeEcho()` path reads `Worker::$outputStream`, which is only initialized
inside `runAll()` (feof() on null).

### "Address already in use" when running `composer test`
<!-- kb: id=FAQ-009 date=2026-08-08 tags=tests,ports,daemon trigger="composer test fails with connection errors on 8888/9999" hits=0 status=promoted gate="docs/workflow.md step 7 note + docs/troubleshooting.md document ports 8888/9999 and php tests/App/index.php stop" -->

Promoted — ports 8888/9999 and `php tests/App/index.php stop` are documented in `docs/workflow.md` step 7 and `docs/troubleshooting.md`.

### How the test suite works
<!-- kb: id=FAQ-010 date=2026-08-08 tags=tests,coverage trigger="running or debugging the test suite" hits=0 status=active -->

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
<!-- kb: id=FAQ-011 date=2026-08-08 tags=tests,coverage,ci trigger="adding logic that needs coverage" hits=0 status=promoted gate="composer.json coverage:check + tests/CoverageCiGateTest.php" -->

Promoted — the floor is defined once in `composer.json` (`coverage:check`) and asserted by `tests/CoverageCiGateTest.php`; rationale in [decisions.md](decisions.md) (DEC-007).

### Underscore header test fixtures need a literal `_` character
<!-- kb: id=FAQ-012 date=2026-08-09 tags=tests,http,headers trigger="writing a fixture for the underscore-header drop path" hits=0 status=active -->

When building a raw HTTP header fixture to exercise the underscore-header
drop path, put a real `_` in the name (e.g. `X-Dropped_1`). Writing the
word `X-Underscore-1` produces hyphens only (`x-underscore-1`), which
parses as a normal header and sails past the drop filter — the test then
exercises nothing. See [decisions.md](decisions.md) for the bounded-logging
decision behind #638.

### Initialize `Workerman\Timer` with the test event loop before calling `onWorkerStart`
<!-- kb: id=FAQ-013 date=2026-08-08 tags=tests,timers trigger="unit-testing ServerWorker timers or the timeout sweeper" hits=0 status=active -->

When invoking `ServerWorker::onWorkerStart` directly in a unit test, initialize
`Workerman\Timer` with the test event loop first. Production initializes the
event loop before `onWorkerStart`; direct callback tests otherwise register
process-level alarm timers instead of timers on the test loop.

Timer-count assertions see an empty loop after `runEventLoopFor`:
`Select::stop()` calls `deleteAllTimer()`. And because the sweeper's
activity bookkeeping is second-granular (`time()`), "closed within X"
timeout tests must run the loop for more than two sweep intervals (e.g.
2.2 s with a 1 s interval) to avoid second-boundary phase flakes.

### Byte-oriented test helpers must prove the `chr()` range to PHPStan
<!-- kb: id=FAQ-014 date=2026-08-08 tags=tests,phpstan trigger="writing a test helper that turns an int into a byte" hits=0 status=active -->

PHPStan requires `chr()` arguments to be provably within `0..255`. When a test
helper accepts an integer byte, guard that range before calling `chr()` rather
than suppressing the diagnostic.

## Git hooks

### Pre-push hook runs `composer lint` before every push
<!-- kb: id=FAQ-015 date=2026-08-08 tags=git-hooks,lint trigger="a push is rejected by the hook, or the hook needs changing" hits=0 status=promoted gate="bin/install-git-hook.php + tests/BinDirectoryTest.php" -->

Promoted — installed by `php bin/install-git-hook.php` and asserted by `tests/BinDirectoryTest.php`; usage and the `--no-verify` escape hatch are in [bin/README.md](../../bin/README.md).

## Control plane / master identification

### `stop`/`reload`/`status` report "Workerman is not running." after a 0.25.0 upgrade
<!-- kb: id=FAQ-016 date=2026-08-10 tags=control-plane,master,upgrade trigger="control commands refuse to signal a running master" hits=0 status=active -->

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
<!-- kb: id=FAQ-017 date=2026-08-08 tags=gh,triage trigger="listing or searching GitHub issues" hits=0 status=promoted gate="bin/pick-issue.php paginates; docs/workflow.md mandates --limit > 30" -->

Promoted — `bin/pick-issue.php` paginates and `docs/workflow.md` step 1/14 mandate `--limit > 30` in every triage command.

## Long-running worker gotchas

### Symfony container / service state survives requests
<!-- kb: id=FAQ-018 date=2026-08-08 tags=long-running,state trigger="stateful services, kernel.reset, request-to-request leakage" hits=0 status=active -->

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
<!-- kb: id=FAQ-019 date=2026-08-09 tags=docs,listen-scheme trigger="documenting or changing the supported listen schemes" hits=0 status=active -->

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
<!-- kb: id=FAQ-020 date=2026-08-10 tags=scheduler,jitter trigger="branching on a trigger type in the scheduler" hits=0 status=active -->

`TriggerFactory::create()` returns a `JitterTrigger($trigger, $jitter)` whenever `jitter > 0`, so
checks like `$trigger instanceof PeriodicalTrigger` silently miss jittered periodical schedules.
`schedulerCallback()` in `SchedulerWorker` unwraps via the `JitterTrigger::innerTrigger()`
accessor before applying the fixed-rate rebasing (issue #565), and `JitterTrigger` exposes
`innerTrigger()` for exactly this. Any future code branching on the trigger type must unwrap
`JitterTrigger` first — or jittered tasks silently take the wrong path.

### DateInterval has no fractional-second parser — set `f` yourself or use `'500 ms'`
<!-- kb: id=FAQ-021 date=2026-08-10 tags=scheduler,date-time trigger="sub-second intervals or periodical triggers" hits=0 status=active -->

`new \DateInterval('PT0.5S')` and `DateInterval::createFromDateString('0.5 seconds')` both throw
(`Unknown or bad format`). Fractional intervals only work via a unit name string
(`createFromDateString('500 ms')` → `s=0, f=0.5`) or by setting the property
manually (`$i = new \DateInterval('PT0S'); $i->f = 0.5;`). `DateTimeImmutable::add()`
honours `f` and preserves microseconds, so `format('U.u')` round-trips sub-second
precision. Used for the sub-second `PeriodicalTrigger` support (issue #565).

### `new \DateTimeImmutable('+1 second')` in a mock `willReturn` is evaluated once
<!-- kb: id=FAQ-022 date=2026-08-10 tags=tests,mocks,date-time trigger="stubbing a date-returning collaborator called more than once" hits=0 status=active -->

A stub configured as `->willReturn(new \DateTimeImmutable('+1 second'))` returns the
same fixed absolute date on every call — the relative string is parsed once at
stub-configuration time. If the code under test calls `getNextRunDate()` repeatedly
(loop or rebound scheduling), stub relative to an injected argument instead, e.g.
`willReturnCallback(fn(\DateTimeImmutable $now) => $now->modify('+1 second'))`.

## Closures / garbage collection

### Mutual by-reference capture (`&$a` / `&$b`) between two closures is a reference cycle
<!-- kb: id=FAQ-023 date=2026-08-10 tags=closures,gc,memory trigger="closures that reference each other in a long-lived worker" hits=0 status=active -->

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

## Symfony config tree

### `setDeprecated()` fires only when the key is actually present in config
<!-- kb: id=FAQ-024 date=2026-08-10 tags=config,deprecation trigger="deprecating a node in the Symfony config tree" hits=0 status=active -->

Symfony's `ArrayNode::finalizeValue()` (vendor/symfony/config/Definition/ArrayNode.php) triggers a child node's deprecation only in the `array_key_exists($name, $value)` branch — an absent key takes the node's default and `continue`s silently. So marking a node deprecated while it keeps `addDefaultsIfNotSet()` / a default value is safe: users who never set the key see no deprecation, users who do set it get the notice. This is how `servers[].static_files` was deprecated alongside `serve_files`/`root_dir` (issue #591) without spamming every config load. The `static_files` deprecation also doubles as the "visible signal" for the allowlist trap: setting `static_files.allowed_extensions` with a service-registered `StaticFilesMiddleware` is still a no-op for the middleware, but no longer silent.

## HTTP header parsing / Workerman internals

### Workerman `header()` joins duplicates with `,`, never trims names, and `rawHead()` excludes the trailing CRLF — count-based gates need three corrections
<!-- kb: id=FAQ-025 date=2026-08-15 tags=http,headers,tests,vendor trigger="gating or testing header parsing on a raw-head line count, or relying on Workerman header semantics" hits=0 status=active -->

The vendored Workerman (`vendor/workerman/workerman/src/Protocols/Http/Request.php`) header semantics differ from the naive assumption in three ways that all bit the `rawHeadMayHaveDuplicates()` gate in `RequestConverter` (issue #557):

1. **`rawHead()` excludes the trailing CRLF.** It is `strstr($buffer, "\r\n\r\n", true)`, so `substr_count($rawHead, "\r\n")` is exactly the header-line count after the request line. The `substr_count(...)- 1` formula from the issue body assumes the terminator is kept and would disable the optimization entirely (always-true → always slow path). Verify the offset against the vendored `rawHead()` before gating.
2. **`parseHeaders()` does not trim header names.** It does `strtolower($parts[0])` verbatim, so an obs-fold-style line (`" X-Fold: v"`) becomes a key with a leading space (`" x-fold"`), distinct from `"x-fold"`. The raw parser trims, so two such lines are a duplicate for the raw parser but two distinct keys for Workerman — counts match while a duplicate exists. The gate forces a re-parse whenever `name !== trim(name)`.
3. **`header()` joins duplicate values with a bare `,` (no space), not last-wins.** Only `count()` is used by the gate so this does not affect correctness, but any test asserting on the joined value must expect `,` not the RFC 7230 `, ` (the bundle's own join, applied on the slow path, is `, `). A fourth gotcha: middleware runs before `SymfonyController` converts, so `header()` may contain names absent from the raw head — an attacker could send exactly as many extra duplicate lines as middleware adds new names, making counts coincide and hiding a duplicate `Cookie` (the #217 class). The gate subtracts `Http\Request::addedHeaderCount()`. Any future count-based gate on headers must verify all four points against the vendored parser, not the issue brief.

### phpbench report name in this repo is `aggregate`, not `average`
<!-- kb: id=FAQ-026 date=2026-08-15 tags=tests,benchmarks trigger="running RequestConverterBench or any phpbench benchmark in this repo" hits=0 status=active -->

`phpbench.json` defines only the `aggregate` report. `vendor/bin/phpbench run ... --report=average` errors out with "Not found in known reports: average" and lists the known names. Use `--report=aggregate` (or omit `--report` and let `phpbench.json` pick the default). Discovered during #557; the task brief said `--report=average`, which does not exist in this repo's phpbench config.
