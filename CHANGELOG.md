# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- New developer helper `bin/gh-branch`: creates or switches to the
  `<type>/issue-<N>-<slug>` branch for a GitHub issue in one command — the
  type (`fix`/`feat`/`docs`/…) is inferred from the issue's `[Type]` title
  prefix or labels, the branch is created from the fresh remote default
  branch, and the branch name is printed to stdout so it can be captured
  (`branch=$(bin/gh-branch 491)`). Optional `--push`, `--dry-run` and
  `--force` flags. Wired into `docs/workflow.md` (step 2) so that neither
  humans nor LLMs have to invent branch names.

### Fixed

- `SfxDownloader::fetch()` no longer leaves a failed-checksum artifact on disk: when SHA-256 verification of a downloaded artifact fails, the artifact is unlinked before the exception is rethrown, so the next `fetch()` re-downloads instead of re-verifying the same bad bytes forever. The same cleanup now applies to zip-extraction failures (corrupt archive, malicious entry, extraction error) — `extractZip()` failures unlink the archive and rethrow the original exception, and the checksum path mentions the removal when it succeeded so a retry is obviously safe ([#642](https://github.com/crazy-goat/workerman-bundle/issues/642))

- `PeriodicalTrigger` tasks no longer drift: the next run is computed from the previous run's **scheduled** time (fixed-rate grid anchored at the moment the task was first scheduled) instead of from the current time after the previous fork, so per-run overhead no longer accumulates. A late reschedule (slow or lock-blocked run) skips missed ticks by whole intervals and resumes on the next grid slot instead of firing catch-up executions, and the delay is computed in fractional seconds so sub-second intervals (e.g. a `DateInterval` with a fraction, `createFromDateString('500 ms')`) are honoured instead of being truncated to whole seconds. The fixed-rate grid also applies when a `jitter` is configured: jitter decorates each grid slot with its random offset instead of accumulating drift ([#565](https://github.com/crazy-goat/workerman-bundle/issues/565))

- `SfxDownloader` now implements the zip-slip **destination-containment** check that `docs/security.md` already documented: entries are extracted one at a time, and each entry's resolved target must stay inside the destination directory. The deepest already-existing ancestor of each target is resolved with `realpath()` and checked against the resolved destination, so an entry such as `sub/evil.bin` cannot escape through a pre-existing symlink (`sub` → outside directory) planted inside the destination tree — an escape the name-level rules alone cannot see. Every extracted entry now passes both the name rules and the containment check, closing the gap where `listEntryNames()` validated one entry set while `extractTo()` extracted another; the docs additionally record that `ZipArchive::extractTo()` materialises symlink entries as regular files rather than creating links ([#587](https://github.com/crazy-goat/workerman-bundle/issues/587))

- `SymfonyController` no longer calls `Request::setTrustedHosts()` on every
  request when `trusted_hosts` is configured. It now maintains a per-worker,
  bounded (64-entry) validated-host cache and re-applies the patterns only on a
  cache miss, so Symfony's internal `Request::$trustedHosts` memo benefits from
  cross-request reuse in a long-lived worker while staying bounded. Without the
  bound, a wildcard trusted-host pattern would let a remote client grow that
  static list by one entry per distinct matching host for the worker's lifetime
  (unbounded memory plus quadratic `in_array` lookup cost) — the very call this
  optimisation removes was the only thing resetting it
  ([#560](https://github.com/crazy-goat/workerman-bundle/issues/560))

- `InotifyMonitorWatcher` no longer discards `IN_IGNORED` bookkeeping (or directory-create
  events) while a reload is pending: the guard previously dropped the whole event batch
  after `inotify_read()`, so `pathByWd` / `watchedPaths` kept entries for directories the
  kernel had stopped watching, and a stale `watchedPaths` entry silently suppressed
  re-watching a recreated directory ([#575](https://github.com/crazy-goat/workerman-bundle/issues/575))
- `InotifyMonitorWatcher` now checks the `inotify_add_watch()` return value: on failure it
  writes to neither bookkeeping map and logs a warning naming the path and
  `/proc/sys/fs/inotify/max_user_watches` (once per path), instead of corrupting
  `pathByWd[0]` with a false descriptor and permanently marking the path as watched
  ([#575](https://github.com/crazy-goat/workerman-bundle/issues/575))
- `InotifyMonitorWatcher` now skips events carrying an unknown watch descriptor (no more
  `null` concatenation into a bogus watch path) and watches directories moved into the
  tree — including their pre-existing children — which previously arrived as
  `IN_MOVED_TO|IN_ISDIR` and were missed entirely ([#575](https://github.com/crazy-goat/workerman-bundle/issues/575))
- `BinaryFileResponseStrategy::scheduleFileCleanup()` no longer creates a closure reference
  cycle on every `deleteFileAfterSend` download: the `onBufferDrain` / `onClose` cleanup
  handlers previously captured each other **by reference**, so every download left ~2.4 KB
  that reference counting could never reclaim — garbage that only the cycle collector
  could free (a forced full collection every ~3 300 downloads under default settings, an
  unbounded leak under `gc_disable()`). The handlers now share a per-connection state
  object (`FileCleanupState`, stored in `$connection->context`), which also bounds the
  callback chain: any number of pending downloads on one keep-alive connection share a
  single handler pair and their temp files are deleted together on the first drain or
  close ([#573](https://github.com/crazy-goat/workerman-bundle/issues/573))
- The `servers[].static_files` config node is now marked deprecated alongside `serve_files`/`root_dir`: it exists solely to configure that deprecated static file serving path, so `config:dump-reference` no longer presents it as current, and actually setting the key now surfaces a deprecation notice naming `StaticFilesMiddleware`'s `$allowedExtensions` constructor argument as the replacement. A `StaticFilesMiddleware` registered as a service reads its allowlist exclusively from that constructor argument — the YAML `static_files.allowed_extensions` key has no effect there. `docs/security.md` now uses the real argument names (`$allowedExtensions`, `$followSymlinks`) throughout and states explicitly that the YAML key does not configure a service-registered middleware ([#591](https://github.com/crazy-goat/workerman-bundle/issues/591))

## [0.25.0] - 2026-08-10

### Added

- `bin/pick-issue.php` — dev tool that ranks the open issues of the lowest open milestone and prints the top candidates with an explainable score, so the next issue can be picked without pulling full issue bodies into the session; exits with code 3 (release needed) when the current milestone has no open issues left, stopping the workflow until a release is cut ([#633](https://github.com/crazy-goat/workerman-bundle/issues/633))

### Changed

- On hosts where the `grpc` extension is loaded, supervised-process workers and forked task children are now terminated with **SIGKILL** instead of `exit()`: `grpc_shutdown()` can hang indefinitely in forked children, which silently stopped the supervisor from respawning processes and leaked live task children. SIGKILL bypasses PHP module shutdown, so destructors and shutdown functions are skipped for those children on grpc hosts; hosts without grpc keep the previous `exit()` behavior unchanged. A start-up warning is emitted when grpc is loaded (including when `GRPC_ENABLE_FORK_SUPPORT=1` is missing) — see `docs/troubleshooting.md` "gRPC Extension and Fork Safety" ([#645](https://github.com/crazy-goat/workerman-bundle/issues/645)); same mechanism as the cache-warmup child ([#141](https://github.com/crazy-goat/workerman-bundle/issues/141))

- Complete the README "Configuration reference": every top-level `workerman` key is now covered — `servers`, `reload_strategy` and `build` were missing, and `build` delegates to `docs/build-packaging.md`. New per-server and per-strategy option tables document `reuse_port`, `local_cert`, `local_pk`, `dispersion`, `allowed_exceptions`, `source_dir`, `file_pattern` and the deprecated `serve_files`/`root_dir` keys; the memory strategy section now documents all four options including `gc_cooldown` (default 60 s), which is also added to its YAML example. A working `https://` configuration example (with the `wss://` note and the symlinked-path rejection) was added to `docs/security.md`, and the `static_files.allowed_extensions` trap — silently ignored by a service-registered `StaticFilesMiddleware` — is called out in the reference and the middleware section ([#590](https://github.com/crazy-goat/workerman-bundle/issues/590))

- The `response_chunk_size` option now configures only streamed responses; it no longer affects regular buffered responses, and the (final, DI-registered) `DefaultResponseStrategy` no longer accepts a constructor chunk-size argument ([#556](https://github.com/crazy-goat/workerman-bundle/issues/556))

- **BC break for custom response strategies**: `ResponseConverterStrategyInterface::convert()` gained a fourth parameter and the `$headers` argument shape changed. The signature went from `convert(SymfonyResponse $response, array $headers, TcpConnection $connection): WorkermanResponse` to `convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse`; the new parameter carries the request's HTTP protocol version (e.g. `1.1` or `1.0`) so strategies that build their own status line can derive it. The `$headers` shape also widened from `array<string, list<string|null>>` to `array<string, string|list<string|null>>` — single-valued headers arrive flattened to `string` (nulls filtered), while multi-valued headers and `Set-Cookie` keep a list. Any custom strategy registered with `workerman.response_converter.strategy` must be updated before upgrading: an unmodified strategy stops satisfying the interface and fatals at runtime ([#556](https://github.com/crazy-goat/workerman-bundle/issues/556), [#579](https://github.com/crazy-goat/workerman-bundle/issues/579))

### Fixed

- `ProcessTest` event-marker assertions no longer fail spuriously when the daemon stalls or restarts: the start- and error-marker files are reset before each test and the helper now waits for a **freshly appended** entry (15 s budget) instead of returning on the first non-empty file, so stale entries from previous runs or a >4 s daemon gap can no longer satisfy the recency check ([#645](https://github.com/crazy-goat/workerman-bundle/issues/645))

- Bound the dropped-underscore-header diagnostics in `RequestConverter` to 64 distinct names per worker process: the log-once-per-worker bookkeeping was an unbounded static map keyed by client-supplied header names, so a single unauthenticated peer could drive unbounded memory growth and sustained log-write amplification. Once the cap is reached, one suppression notice is logged and recording stops ([#638](https://github.com/crazy-goat/workerman-bundle/issues/638))

- `StaticFilesMiddleware` no longer 404s every file in a subdirectory when `static_files.allowed_extensions` is configured: the allowlist and the residue-extension checks are file-only rules and are now applied exclusively to the last path component, while every component still gets the dotfile, leak-extension and blocked-name checks ([#637](https://github.com/crazy-goat/workerman-bundle/issues/637))

- Make the CI coverage gate effective: the threshold was `0.0` (passing
  trivially at ~82% actual coverage) and the check ran twice per matrix leg.
  The threshold (80%) is now defined once in `composer.json`
  (`coverage:check`), the duplicate invocation in
  `.github/workflows/tests.yaml` is removed, and the gate runs only on the
  lowest supported matrix leg (PHP 8.2 / Symfony 6.4) — per-leg coverage
  reports are still uploaded as artifacts
  ([#589](https://github.com/crazy-goat/workerman-bundle/issues/589))

- Run the `services_resetter` on every request path — including kernel boot/handle exceptions, trusted-host rejections, and kernels that do not implement `TerminableInterface` — so request-scoped Symfony service state cannot leak between requests in a long-running Workerman process ([#572](https://github.com/crazy-goat/workerman-bundle/issues/572))

- `MemoryRebootStrategy` now bases its reload verdict on the memory reading taken **after** the `gc_collect_cycles()` it triggers, so a worker whose memory drops back under `limit` as a result of the collection is no longer reloaded unnecessarily. When the worker is already above `limit`, the collection runs synchronously instead of being scheduled on a later event-loop tick; the deferred `Timer::add(0, ...)` path is kept for the preventive "above `gc_limit`, below `limit`" case, and a collection blocked by `gc_cooldown` leaves the verdict on the current reading. Memory is measured with `memory_get_usage()` (emalloc accounting) — stated explicitly in the config docs, since the allocator arena behind `memory_get_usage(true)` would mask the effect of the collection ([#561](https://github.com/crazy-goat/workerman-bundle/issues/561))

- Stop manually splitting buffered responses into 8 KiB sends. `DefaultResponseStrategy` now returns the complete body to Workerman's transport, avoiding redundant `substr()` copies and write syscalls; preserve the request's HTTP protocol version on converted responses ([#556](https://github.com/crazy-goat/workerman-bundle/issues/556))

- Replace per-request connection timers with one worker-level sweeper and activity timestamps, preventing cancelled `Select` timer entries from retaining memory under sustained keep-alive traffic ([#555](https://github.com/crazy-goat/workerman-bundle/issues/555)). The earlier per-connection timer-cleanup approach ([#571](https://github.com/crazy-goat/workerman-bundle/issues/571)) is superseded: `onClose` no longer cancels per-connection timers — it clears the connection context so the sweeper skips closed connections — and `BinaryFileResponseStrategy` temp-file cleanup still chains with the worker-level `onClose` base callback. Note: when `ServerWorker` is constructed directly with `connectionTimeout: 0`, the timeout is now disabled (previously it armed an immediate close); the YAML configuration still enforces a minimum of 1 second.

### Security

- Reject bare CR and LF, along with the rest of the non-TAB control-byte range, in incoming header values. `RequestConverter` now covers `\x00-\x08` and `\x0A-\x1F` (plus `\x7F`) while retaining legal TAB values, preventing malformed header data from reaching the Symfony server bag ([#581](https://github.com/crazy-goat/workerman-bundle/issues/581))

- URL-decode cookie values in `parseCookiesFromServerBag()` with `rawurldecode()` semantics, so values written by Symfony's `Cookie` (which `rawurlencode()`s in `__toString()`) round-trip exactly as they do under PHP-FPM. Decoding matches PHP's SAPI (`php_raw_url_decode`: `%XX` decoded, literal `+` preserved) and runs strictly after splitting the header on `;` and `=`, so an encoded `%3B` in a value is never reinterpreted as a cookie separator and the duplicate-`Cookie`-header smuggling fix ([#217](https://github.com/crazy-goat/workerman-bundle/issues/217)) stays intact
  ([#583](https://github.com/crazy-goat/workerman-bundle/issues/583))

- Fix the config-cache permission guard so it checks the object that
  actually governs file replacement: the **containing directory**. The
  previous check only examined the cache file's world-writable bit, but on
  POSIX replacing a file requires write permission on the directory, not on
  the file — so an attacker with a writable cache directory (e.g. under
  `umask 0000`) could `unlink` the cache file, write their own, and have it
  `require`d at boot. Loading is now refused when the cache directory is
  world-writable, when it is group-writable by a group the process does not
  belong to, or when the cache file is not owned by the process's effective
  user ID (a replaced file would be owned by the attacker). The original
  world-writable-file check is kept as a secondary signal, and metadata that
  cannot be read (ACLs, non-POSIX filesystems) now logs a warning naming the
  path instead of silently proceeding
  ([#586](https://github.com/crazy-goat/workerman-bundle/issues/586))

- **Behaviour change**: because the cache file must now be owned by the
  process that loads it (see previous entry), a config cache warmed up by
  a different user than the runtime user (e.g. deploy user vs `www-data`,
  or a Docker build that runs `cache:warmup` as `root` before switching to
  the runtime user) is **refused at boot** instead of being loaded
  silently: the launcher process aborts with a `RuntimeException` naming
  both UIDs before any worker forks. Warm up with the runtime user or
  `chown` the cache file to that user after warm-up — see [Config cache
  and runtime user](README.md#config-cache-and-runtime-user) and the
  [security docs](docs/security.md#config-cache-file-protection)
  ([#586](https://github.com/crazy-goat/workerman-bundle/issues/586))

- Widen `StaticFilesMiddleware`'s built-in denylist to cover editor
  backups, deploy residue and credential files, and make the check
  compound-extension aware. In the default configuration the middleware
  previously served any existing file whose final extension was not one of
  `.php`, `.phar`, `.phtml`, so a single editor backup or interrupted save
  left in `public/` (`index.php~`, `index.php.bak`, `config.php.save`,
  `config.inc`, `backup.sql`, …) disclosed full PHP source or credentials
  over HTTP. The denylist now also rejects names ending in `~` (vim/emacs
  backups), extends the blocked extension list with `phps`, `inc`, `sql`,
  `log`, `pem`, `key`, `crt`, `sqlite`, `sqlite3`, `db` — checked in every
  dot-separated suffix segment so they are caught wherever they appear
  (`x.phar.gz`, `x.php.txt`) — and with `bak`, `orig`, `rej`, `save`, `swp`,
  `swo`, `tmp`, `old`, `dist` — blocked as the final extension of a file
  only (`config.dist` is denied, an `assets.dist/` directory or an interior
  `app.dist.js` segment is not)
  ([#582](https://github.com/crazy-goat/workerman-bundle/issues/582))

- Harden master-process identification before sending signals. The legacy
  `/proc/$pid/cmdline` fallback accepted any process whose command line
  contained the substring `php` (and returned `true` unconditionally when
  the cmdline was unreadable or on non-Linux hosts), so a stale or
  attacker-supplied pid file could make `stop()`/`reload()`/`status` signal
  an unrelated PHP process. The fallback now matches the process title
  Workerman assigns to its master process (`WorkerMan: master process ...`)
  and fails closed — an unverifiable PID is refused with a warning instead
  of being signalled. A new `MasterWorker` records the master fingerprint
  from inside the real master process, closing the daemon-mode gap where
  `start -d` deployments never had a fingerprint
  ([#584](https://github.com/crazy-goat/workerman-bundle/issues/584))

- Document the operator impact of the hardened master identification
  (previous entry): upgrading while a server started by a pre-0.25
  version is still running can make `stop` / `reload` / `status` report
  "Workerman is not running." (no fingerprint sidecar exists yet — and on
  non-Linux hosts there is no command-line fallback at all), and the same
  report can appear in the short window after `start -d` until the master
  writes its pid file and fingerprint. `UPGRADE.md` gains an "Upgrading
  to 0.25" section and `docs/security.md` an "Operator impact" block
  with the recovery steps
  ([#640](https://github.com/crazy-goat/workerman-bundle/issues/640))

- Fix unbounded memory growth in `StaticFilesMiddleware`'s realpath cache.
  The symlink-rejection path inserted into the shared worker cache without
  enforcing `CACHE_MAX_SIZE`, and negative entries only expired on a repeat
  lookup of the same URL — unauthenticated requests to symlink-traversing
  paths (`/assets/<anything>` when `assets` is a symlink) grew the cache
  monotonically (~556 B per distinct URL; 106 MB after 200k requests),
  forcing continuous `MemoryRebootStrategy` reloads. All cache writes now go
  through a single helper that enforces the cap and evicts the oldest entry
  via `unset(array_key_first())` instead of `array_shift()`; fixed-TTL
  semantics on cache hits are preserved
  ([#570](https://github.com/crazy-goat/workerman-bundle/issues/570),
  [#558](https://github.com/crazy-goat/workerman-bundle/issues/558))

- Unify SFX download redirect policing across modes: HTTPS → HTTP downgrade redirects and
  redirects to non-HTTP(S) schemes (`file://`, `php://`, `ftp://`) are now blocked in **all**
  modes, not only with `--insecure`. Automatic redirect following is disabled in the stream
  context unconditionally, so PHP's `http` wrapper never follows a redirect on the bundle's
  behalf; every hop is followed and scheme-checked manually, `resolveRedirectUrl()` rejects
  unresolvable targets instead of passing them through, and downloads are capped at a maximum
  size (256 MiB by default) with the partial file removed on abort. The SHA-256 checksum is
  now verified immediately after download, before any zip extraction, so the extractor never
  runs over unverified bytes — for `.zip` URLs the checksum now covers the downloaded zip
  artifact rather than the extracted SFX binary. `--insecure` now differs from the default in
  exactly one respect: TLS peer verification
  ([#585](https://github.com/crazy-goat/workerman-bundle/issues/585))

- Drop HTTP header names containing underscores before converting requests to
  Symfony's `$_SERVER` bag. Without this, `X-Forwarded_For` collided with
  `X-Forwarded-For` as `HTTP_X_FORWARDED_FOR`, allowing trusted-proxy client-IP
  spoofing and bypassing proxy header stripping. Dropped names are logged once
  per worker; dash-spelled headers and the CGI `CONTENT_TYPE`, `CONTENT_LENGTH`,
  and `CONTENT_MD5` conventions are preserved
  ([#578](https://github.com/crazy-goat/workerman-bundle/issues/578))

- Fix `StaticFilesMiddleware` extension allowlists failing open for extensionless
  files: `Dockerfile`, `id_rsa`, `dump`, and names ending in a dot now return 404
  when an allowlist is configured. Blocked path components are also checked
  correctly when filesystem paths use backslashes. This is a behavior change for
  deployments that intentionally served extensionless files with an allowlist
  ([#580](https://github.com/crazy-goat/workerman-bundle/issues/580))

- Fix duplicate `Content-Length` on every file download and on responses where
  the application sets its own `Content-Length`. Workerman's
  `Response::withHeaders()` merges recursively (`array_merge_recursive`), so
  application-supplied framing headers were combined with — not replaced by —
  the values the transport layer computes, emitting the header twice. When the
  two values disagreed (app declares 5 bytes, body is 11), the conflict is a
  response-desync primitive that can poison caches and leak responses across
  keep-alive connections. `ResponseConverter::extractHeaders()` now strips
  transport-owned headers (`Content-Length`, `Accept-Ranges`,
  `Transfer-Encoding`) centrally so the transport is the sole authority on
  message framing, and single-valued headers are flattened from
  `list<string|null>` (nulls filtered) to `string` (except `Set-Cookie`, which
  legitimately needs multiple values) to prevent `array_merge_recursive` from
  ever producing arrays of conflicting values. Headers whose values are all
  null/empty are dropped so they are not emitted as empty lines on the wire.
  `Content-Range` and the `206` status on ranged responses are preserved. The
  existing `strcasecmp` guards in `DefaultResponseStrategy::buildHeaderString()`
  and `StreamedResponseStrategy::buildHeaderString()` are kept as
  belt-and-braces
  ([#579](https://github.com/crazy-goat/workerman-bundle/issues/579))

## [0.24.1] - 2026-07-28

### Security

- Prevent middleware header mutations from persisting in Workerman's request cache and being replayed to later requests with the same raw buffer. Headers are restored after each request dispatch, preventing cross-request identity, proxy, and tenant-state leakage ([#576](https://github.com/crazy-goat/workerman-bundle/issues/576))

- Fix remote unauthenticated denial-of-service: a single control byte in
  any request header value killed the worker process. The request
  lifecycle in `HttpRequestHandler::__invoke()` is now wrapped in a
  try/catch that converts throwables into 400/500 responses, and
  `ServerWorker::onConnect` installs a `TcpConnection::$errorHandler`
  backstop that closes the connection instead of letting Workerman call
  `Worker::stopAll(250)`. Client errors (`MalformedRequestException`,
  `FileUploadValidationException`) are logged at debug level to prevent
  log flooding; server faults are logged at error level. A nested
  try/catch around the error-response send ensures `doTerminate()` and
  the reboot check still run when even the send fails
  ([#577](https://github.com/crazy-goat/workerman-bundle/issues/577))

### Changed

- `RequestConverter` now throws `MalformedRequestException` (extends
  `\InvalidArgumentException`, implements `ClientInputExceptionInterface`)
  instead of bare `\InvalidArgumentException` for malformed client input
  (control bytes in headers, invalid URI/method). This lets
  `HttpRequestHandler` distinguish client errors (400) from server faults
  (500) — a middleware throwing `\InvalidArgumentException` is now
  correctly a 500, not a 400
  ([#577](https://github.com/crazy-goat/workerman-bundle/issues/577))

### Added

- `CrazyGoat\WorkermanBundle\Exception\ClientInputExceptionInterface` —
  marker interface for exceptions caused by malformed client input,
  implemented by `MalformedRequestException` and
  `FileUploadValidationException`
  ([#577](https://github.com/crazy-goat/workerman-bundle/issues/577))

- `CrazyGoat\WorkermanBundle\Exception\MalformedRequestException` —
  thrown by `RequestConverter` for malformed client input (control
  bytes, invalid URI/method)
  ([#577](https://github.com/crazy-goat/workerman-bundle/issues/577))

## [0.24.0] - 2026-07-26

### Performance

- Optimize `InotifyMonitorWatcher` startup by deferring the recursive directory
  walk to after the event loop starts. At boot only the top-level source
  directories are watched; remaining subdirectories are watched lazily via a
  single deferred pass. This eliminates a synchronous full-directory walk that
  could delay worker readiness on very large source trees
  ([#324](https://github.com/crazy-goat/workerman-bundle/issues/324))

- Optimize `FileMonitorWatcher::checkPattern()` by compiling glob patterns to a single PCRE regex at construction time, reducing per-tick matching from O(files × patterns) to O(files) ([#339](https://github.com/crazy-goat/workerman-bundle/issues/339))

### Tests

- Harden PHAR-build tests against silent `phar.readonly` skips: add
  `phar.readonly=0` to `composer test` / `composer test:coverage` scripts and
  CI workflow ini-values, introduce `PharReadOnlyGuardTest` that fails under CI
  when `phar.readonly` is set without explicit opt-out
  (`WORKERMAN_ALLOW_PHAR_READONLY_SKIP=1`), and refactor
  `testCommandFailsWhenPharReadonlyIsSet` to use injected `PharCapabilities`
  instead of depending on the runtime INI setting
  ([#340](https://github.com/crazy-goat/workerman-bundle/issues/340))

- Harden `UtilsTest` signal-logic tests: add `pcntl` and `posix` extensions to
  CI runner, introduce guard test that fails when extensions are missing without
  explicit opt-out (`WORKERMAN_ALLOW_PCNTL_SKIP=1`). macOS contributors can skip
  these tests locally by setting the env var
  ([#346](https://github.com/crazy-goat/workerman-bundle/issues/346))

- Populate `<coverage/>` in `phpunit.xml` with Clover and text report output,
  add `composer test:coverage` and `composer coverage:check` scripts, and
  enforce a line-coverage threshold in CI using `bin/check-coverage.php`. CI
  enables PCOV, generates `var/coverage.xml`, and uploads it as an artifact per
  matrix job. A regression test asserts the coverage gate remains present in
  `.github/workflows/tests.yaml` ([#357](https://github.com/crazy-goat/workerman-bundle/issues/357))

- Expand `ProcessTest` from a single PID-file recency check to full process lifecycle coverage: `ProcessStartEvent` dispatch regression check, `ProcessErrorEvent` dispatch on throwable (via new `TestErrorProcess`), no-error-event assertion during normal operation, and SIGTERM-to-worker restart verification (locates the worker PID via `/proc` on Linux or `ps` on macOS). Introduces `ProcessEventRecorder` test listener and `ProcessMarkerPaths` constants class as the shared source of truth for marker file paths ([#348](https://github.com/crazy-goat/workerman-bundle/issues/348))

### Added

- Add PHPBench benchmark suite covering the five documented hot paths: `RequestConverter::toSymfonyRequest`, `ResponseConverter::convert`, `MemoryRebootStrategy::shouldReboot`, `PeriodicalTrigger::getNextRunDate`, and `HttpRequestHandler::__invoke` (composed middleware chain). Run via `composer bench`. CI executes the suite on every PR in advisory mode (results are logged but do not block merge). Documented measurement protocol in `CONTRIBUTING.md` ([#328](https://github.com/crazy-goat/workerman-bundle/issues/328))

- Add a cross-platform middleware dispatch contract test (`MiddlewareDispatchContractTest`). A dedicated test server on port 9991 runs a counting middleware that increments a shared counter file under `flock()` and tags every response with `X-Dispatch-Count`. The contract asserts that exactly one dispatch is observed per incoming HTTP request (single + sequential request cases), so any regression of the issue #533 dispatch-count class — including the macOS-specific triple-dispatch — fails CI immediately and on every supported OS ([#542](https://github.com/crazy-goat/workerman-bundle/issues/542))

### Changed

- Extract the side-effectful `phar.readonly` INI probe and `\Phar` extension presence check out of `PharBuilder::build()` into a new `PharCapabilities` collaborator. `PharBuilder` now accepts the capability checker via constructor (defaults to a live `PharCapabilities::probe()`), making the runtime checks individually testable and stubbable. The DI container registers `PharCapabilities` and injects it into the `workerman.phar_builder` service. No behavioural change observable for the `build:phar` flow ([#372](https://github.com/crazy-goat/workerman-bundle/issues/372))

### Fixed

- Reset the test middleware execution-order accumulator on the first middleware invocation so `MiddlewareTest::testHeaders` no longer sees a stale `X-Test-Middleware-request-order` value on subsequent keep-alive requests under macOS / Workerman. Added a regression test that performs two consecutive requests through the same HTTP client and asserts identical middleware order on both responses ([#533](https://github.com/crazy-goat/workerman-bundle/issues/533))

- Make `ProcessInspector::isProcessAlive()` portable across POSIX systems — on macOS and other non-Linux platforms where `/proc` is unavailable, the function now uses `posix_kill($pid, 0)` for the primary liveness check and falls back to a non-blocking `pcntl_waitpid()` to distinguish running processes from zombies. The Linux `/proc/{pid}/status` zombie check is preserved as a Linux-only refinement. `getParentPid()`, `isMasterRunning()`, and `killOrphanedIntermediateFork()` are likewise gated on `PHP_OS_FAMILY === 'Linux'` so they no longer crash on macOS. Fixes `ServerManager::stop()` returning `false` on macOS because `waitForProcessToStop()` never observed the process dying ([#530](https://github.com/crazy-goat/workerman-bundle/issues/530))

- `ProcessTest::testProcessIsLive` failed on macOS because `TestProcess` wrote the status timestamp only once per `__invoke()` invocation, then exited. Once Workerman's boot + shutdown + supervisor respawn cycle exceeded 4 seconds (common on macOS), the persisted timestamp always looked stale. `TestProcess` now refreshes the status file on a 1-second heartbeat inside a loop so the timestamp always stays within the test's recency window; the test's secondary budget is widened from 4 to 10 seconds as a safety net ([#534](https://github.com/crazy-goat/workerman-bundle/issues/534))

- Fix race condition in `ServerManager::getStatus()` / `getConnections()` where PHP's stat cache and stale status files from interrupted runs could cause `waitForFile()` to read an empty or incomplete file written by Workerman's SIGIOT/SIGIO handler. `StatusFileReader::waitForFile()` now calls `clearstatcache()` before each poll and rejects 0-byte files. `ServerManager` deletes the stale status/connections file before signaling, ensuring `waitForFile()` always waits for fresh output from the current signal. Fixes `WorkermanCommandTest` failures on macOS where slower filesystem operations widened the race window ([#535](https://github.com/crazy-goat/workerman-bundle/issues/535))

### Security

- Harden `PharBuilder`'s user-supplied `exclude_patterns` against accidental ReDoS at build time. `ExcludePattern` now performs defense-in-depth: (1) a structural lint at construction time that rejects patterns containing nested unbounded quantifiers (`(a+)+`, `(.+)*`, `(a+){2,}`, ...) before the build ever traverses the source tree, (2) a PCRE compile-check against a probe string that surfaces patterns PHP itself rejects with a clear error message, (3) a per-call `pcre.backtrack_limit`/`pcre.recursion_limit` guard inside `matches()` that returns `false` rather than hanging if the limit trips. The temporary ini values are restored on every exit path. Negative regression test (`testBuildRefusesNestedUnboundedQuantifierPattern`) and a behavioural test for the per-call guard prevent future regressions. Documentation in `docs/build-packaging.md` recommends atomic groups (`(?>...)`) as the PCRE-native alternative for matching power that would otherwise require nested quantifiers ([#334](https://github.com/crazy-goat/workerman-bundle/issues/334))
- Add explicit PHPDoc security warnings on `Request::setHeader()` and `Request::withHeader()` flagging that re-injecting `X-Forwarded-*` or `Forwarded` headers from untrusted input re-creates the trusted-proxy bypass class of bugs ([#344](https://github.com/crazy-goat/workerman-bundle/issues/344))
- Document the middleware header re-injection trust model in a new `docs/security.md` section — covers the risk, recommended ordering (run trusted-proxy filtering after middleware that mutates headers), scope-limiting forwarding-header writes, and the canonical Symfony `setTrustedProxies()` / `setTrustedHosts()` alternative ([#344](https://github.com/crazy-goat/workerman-bundle/issues/344))
- Replace the loose `str_contains('/proc/$pid/cmdline', 'WorkerMan')` check in `ProcessInspector` with a fingerprint-based verification. `ServerManager` now writes a sidecar fingerprint file (`<pid_file>.fingerprint`) at start time, recording the master PID, start time (clock ticks since boot, Linux only), and UID. `ProcessInspector` verifies all three fields before signaling, preventing misidentification of unrelated co-located processes whose command line happens to contain "WorkerMan". The legacy cmdline-based check is retained as a fallback when no fingerprint file is present (backward compatibility and daemon mode). The fingerprint file is created with `0600` permissions and removed on `stop()` ([#327](https://github.com/crazy-goat/workerman-bundle/issues/327))
- Document the Composer audit advisory suppression policy in `docs/security.md`. The `audit.ignore` list is kept empty — no Composer security advisory is suppressed globally. Dev-only advisories are handled via `composer audit --no-dev` (the CI/production audit mode), so production dependencies are never shielded by a global suppression. Add `testAuditIgnoreListIsEmpty` and `testComposerAuditNoDevIsClean` tests to enforce the policy and prevent accidental re-introduction of suppressed advisories ([#337](https://github.com/crazy-goat/workerman-bundle/issues/337))

### Code Quality

- Replace `$_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']` superglobal mutation with a typed `CacheWarmupTimeoutConfig` static holder that bridges the bundle extension loader (runs during kernel boot) and `Runner` construction (runs later, outside the DI container via `Runtime::getRunner()` or `ServerManager::start()`/`restart()`). The env-var override path is preserved — `WorkermanBundle::loadExtension()` still reads `WORKERMAN_CACHE_WARMUP_TIMEOUT` from `$_SERVER`/`$_ENV` and applies it before storing the resolved value in the holder. `Runner` now accepts the timeout as a constructor argument with a default of 30 seconds, and the validation rule (`>= 1`) lives in one place on the holder ([#368](https://github.com/crazy-goat/workerman-bundle/issues/368), [#367](https://github.com/crazy-goat/workerman-bundle/issues/367))

### Docs

- Update `docs/security.md` Static Files Protection examples to use the `StaticFilesMiddleware` service approach instead of the deprecated `serve_files` and `root_dir` server options. All YAML examples now show the recommended service registration pattern ([#345](https://github.com/crazy-goat/workerman-bundle/issues/345))

- Add explicit `@api` annotation to `Utils::reload()` to clarify that it is the canonical public API for programmatic worker reload, resolving the remaining ambiguity from the `@internal` removal in 0.21.0 ([#352](https://github.com/crazy-goat/workerman-bundle/issues/352))

- Delegate issue triage and code review steps in `docs/workflow.md` to subagents with their own context, protecting the main session's token budget for implementation and fixes ([#531](https://github.com/crazy-goat/workerman-bundle/pull/531))

## [0.23.0] - 2026-06-25

### Tests

- Replace `testRunnerUsesCorrectForkErrorHandling` (which read `Runner.php` as a string) with `testForkFailureThrowsRuntimeException` — a behavioral test that stubs the `fork()` method via a readonly subclass and asserts `RuntimeException` is thrown when `pcntl_fork()` returns `-1`. Removes the dead `fork_failure` case from the isolated test fixture ([#313](https://github.com/crazy-goat/workerman-bundle/issues/313))
- Replace `testBootstrapClosesProcOpenPipes` and `testWorkermanCommandClosesProcOpenPipes` (which read source-code files as strings and asserted on substrings) with behavioral tests that exercise the `proc_open` pipe cleanup pattern on actual subprocesses and assert all pipe resources are closed after `fclose()` ([#319](https://github.com/crazy-goat/workerman-bundle/issues/319), [#326](https://github.com/crazy-goat/workerman-bundle/issues/326))
- Replace `testSourceFileNoLongerContainsGetFileInfo` (which read `PollingMonitorWatcher.php` as a string and asserted on a substring) with `testPollUsesSingleStatPerFile` — a behavioral test that instruments the iterator with `CountingSplFileInfo` and asserts exactly one `stat()` call per file. The new test catches any redundant stat-touching call (`getFileInfo()`, `getSize()`, `isFile()`, duplicate `getMTime()`, etc.) under any name, not just `getFileInfo()` ([#330](https://github.com/crazy-goat/workerman-bundle/issues/330))
- Expand `StreamedBinaryFileResponseTest` with comprehensive test coverage: content type detection, Content-Length verification, Content-Disposition, offset/maxlen behavior, `deleteFileAfterSend` cleanup, output correctness for small and large files, chunk size validation, auto ETag/Last-Modified headers, and edge cases (empty file, non-readable file, private responses) ([#353](https://github.com/crazy-goat/workerman-bundle/issues/353))
- Replace `testSchedulerWorkerLogsExceptionsInChildProcess` (which read `SchedulerWorker.php` as a string and asserted on substrings) with a behavioral test that forks a child, invokes `SchedulerWorker::handleChild` via reflection with a `TaskHandler` that throws, and asserts the child exits with code 1 and the exception is logged via `Worker::log()` ([#306](https://github.com/crazy-goat/workerman-bundle/issues/306))

### Security

- Add world-writable permission check to `ConfigLoader::loadFromCache()` before requiring the generated PHP cache file. Cache files with world-writable permissions are now rejected with a clear error message, preventing arbitrary code execution if the cache directory is misconfigured ([#323](https://github.com/crazy-goat/workerman-bundle/issues/323))
- Force `umask(0077)` while writing the config cache file in `ConfigLoader::warmUp()` so the generated PHP file is always created with restrictive `0600` permissions, regardless of the surrounding umask ([#323](https://github.com/crazy-goat/workerman-bundle/issues/323))
- Document the trust requirement for the config cache directory in `docs/security.md` — the cache directory must not be writable by untrusted users ([#323](https://github.com/crazy-goat/workerman-bundle/issues/323))
- Remove `@unlink` error suppression in `BinaryFileResponseStrategy` cleanup callback; unlink failures are now checked and logged through the injected PSR-3 logger ([#314](https://github.com/crazy-goat/workerman-bundle/issues/314))
- Use `onBufferDrain` as the primary cleanup hook in `BinaryFileResponseStrategy` instead of `onClose`, so file deletion runs at the correct lifecycle point (after the send buffer is flushed) and does not persist across keep-alive requests; `onClose` is retained as a fallback for early disconnects; both callbacks self-remove after firing and chain to any previously-set handlers ([#308](https://github.com/crazy-goat/workerman-bundle/issues/308))
- Use atomic rename-before-read (TOCTOU fix) in `ServerManager::consumeFile()` for status and connections files to prevent symlink-swap redirection of the unlink. A failure to unlink the renamed temp file is now logged through the PSR-3 logger instead of being silently suppressed ([#304](https://github.com/crazy-goat/workerman-bundle/issues/304))

### Performance

- Make `Connection: close` header check case-insensitive in `HttpRequestHandler` — RFC 7230 treats the token case-insensitively, so `Close`, `CLOSE`, etc. now correctly trigger connection close, preventing wasted file descriptors and unexpected request reuse in long-running workers ([#336](https://github.com/crazy-goat/workerman-bundle/issues/336))
- Gate `memory_reset_peak_usage()` behind a boot-time flag so the per-request syscall is skipped when no reboot strategy needs `memory_get_peak_usage()` — currently no bundled strategy uses peak memory, so the call is eliminated entirely on the hot path ([#317](https://github.com/crazy-goat/workerman-bundle/issues/317))
- Replace `ExceptionRebootStrategy`'s full `Throwable` storage with a boolean flag to eliminate a memory leak in long-running workers — the previous implementation retained the exception's entire stack trace (including referenced `Request`, controller, and service object graphs) until `shouldReboot()` was consumed ([#307](https://github.com/crazy-goat/workerman-bundle/issues/307))
- Cache `method_exists()` results per (class, method) pair in `ServiceHandlerTrait` to avoid redundant reflection lookups on every tick/invocation in `TaskHandler` and `ProcessHandler` ([#315](https://github.com/crazy-goat/workerman-bundle/issues/315))

### Code Quality

- Extract `Util\Wait::until()` to unify the polling strategy in `StatusFileReader::waitForFile()` (previously a fixed 50ms cadence) and `ProcessInspector::waitForProcessToStop()` (previously an inline exponential-backoff loop with `time()`-based deadlines). The shared helper polls a condition with exponential backoff from 10ms up to 250ms and uses `microtime(true)` deadlines, so the total wall time stays at or below the configured upper bound (the old `time()`-based path could overshoot by up to one second) ([#362](https://github.com/crazy-goat/workerman-bundle/issues/362))
- `PollingMonitorWatcher`: relax `final` on the class and on `FileMonitorWatcher::createRecursiveIterator()` so a test-only subclass can inject a counting `RecursiveDirectoryIterator`. The behavioral test in `PollingMonitorWatcherTest` requires this extension point to verify the watcher makes exactly one `stat()` call per file; without it, the test would be limited to flaky wall-time heuristics ([#330](https://github.com/crazy-goat/workerman-bundle/issues/330))
- `CronExpressionTrigger`: remove redundant `class_exists(Cron\CronExpression::class)` gate from the constructor — the check is already performed by `TriggerFactory::create()` before instantiation, making the duplicate guard unreachable and misleading ([#355](https://github.com/crazy-goat/workerman-bundle/issues/355))
- `TriggerFactory`: replace falsy object check (`if ($dateTime)`) with explicit `instanceof \DateTimeImmutable` check to clarify that the branch is taken only when ISO-8601 datetime parsing succeeds, and to avoid relying on object truthiness ([#361](https://github.com/crazy-goat/workerman-bundle/issues/361))
- `WorkermanCommand`: rename `$allowedActions` local variable to `$invalidActionMessage` so the name accurately reflects that it holds an error message, not a list of allowed actions ([#373](https://github.com/crazy-goat/workerman-bundle/issues/373))
- `StaticFilesMiddleware`: replace repeated `DIRECTORY_SEPARATOR . ltrim($path, '/')` with a named `joinPaths()` helper that normalises both root and request path separators explicitly, eliminating implicit coupling that would silently produce wrong paths if a future change stripped the leading slash ([#365](https://github.com/crazy-goat/workerman-bundle/issues/365))
- `WorkermanCompilerPass`: standardise tag set ordering — `$responseConverterStrategies` remain sorted by priority (descending) for correct dispatch order in `ResponseConverter::convert()`, while `$tasks`, `$processes`, and `$rebootStrategies` are now sorted by service ID via `ksort` for deterministic ServiceLocator registration and reproducible container builds ([#371](https://github.com/crazy-goat/workerman-bundle/issues/371))
- `BinaryComposer`: reduce `MAGIC_BYTES` visibility from `public` to `private` — the constant is only used internally and was accidentally exposed as part of the public API ([#363](https://github.com/crazy-goat/workerman-bundle/issues/363))
- `PeriodicalTrigger`: remove fragile `(array)` cast on `\DateInterval` to read the private `from_string` property; replace with a flat `'DateInterval'` description for directly-passed `DateInterval` objects ([#360](https://github.com/crazy-goat/workerman-bundle/issues/360))
- `ServicesConfigurator`: use `=== true` consistently for all boolean `active` config flags in `configureRebootStrategies()` — previously only `memory.active` used strict comparison, while `always`, `max_requests`, and `exception` used a truthy check ([#370](https://github.com/crazy-goat/workerman-bundle/issues/370))
- `DateTimeTrigger`: move assignment out of `if` condition to eliminate assignment-in-condition smell and avoid potential `=`/`==` confusion ([#359](https://github.com/crazy-goat/workerman-bundle/issues/359))
- `Http\Request`: add runtime deprecation notice to `withHeader()` warning that the PSR-7-named alias is misleading — it mutates the request in place rather than returning a new instance; users should migrate to `setHeader()` ([#364](https://github.com/crazy-goat/workerman-bundle/issues/364))
- `Resolver`: rename inner closure variadic parameter to `...$kernelArgs` so it no longer shadows the outer `$args` variable destructured from `resolver->resolve()` ([#366](https://github.com/crazy-goat/workerman-bundle/issues/366))

### Docs

- Add comprehensive interface-level and per-method PHPDoc to `MiddlewareInterface`, `RebootStrategyInterface`, and `TriggerInterface` — every interface now documents its purpose, lifecycle, consumption site, and parameter/return semantics so third-party implementers have a complete contract reference ([#322](https://github.com/crazy-goat/workerman-bundle/issues/322))
- Update "What's new in this fork" section in `README.md` with a comprehensive comparison against upstream `luzrain/workerman-bundle`, covering 20+ feature additions, dependency differences, and architectural changes ([#491](https://github.com/crazy-goat/workerman-bundle/issues/491))
- Document `composer test` port binding, troubleshooting steps, and workarounds in `CONTRIBUTING.md` to help contributors avoid "Address already in use" errors ([#358](https://github.com/crazy-goat/workerman-bundle/issues/358))

- Update README main configuration example to demonstrate `StaticFilesMiddleware` instead of relying on the deprecated `serve_files` option; the replacement was previously only shown in a dedicated subsection ([#342](https://github.com/crazy-goat/workerman-bundle/issues/342))
- Document `--include-tests` and `--kernel-class` CLI options in `docs/build-packaging.md` — these options were already supported by `workerman:build:phar` but omitted from the documentation ([#331](https://github.com/crazy-goat/workerman-bundle/issues/331))
- Resolve contradiction between CONTRIBUTING.md and CHANGELOG.md on approval policy: CONTRIBUTING.md now accurately reflects the current "no approval count required (solo dev project)" policy, matching the historical CHANGELOG 0.15.0 entry ([#333](https://github.com/crazy-goat/workerman-bundle/issues/333))
- Document `runtime_dir` in the `README.md` configuration reference, with full semantics (writable, must live outside the PHAR in PHAR/BIN mode, restrictive 0700 permissions on subdirectories) and a cross-link to `docs/build-packaging.md`; align the `ConfigurationTreeBuilder` info string with the README so `config:dump-reference` matches ([#343](https://github.com/crazy-goat/workerman-bundle/issues/343))
- Replace `@param mixed[]` with typed `array{...}` shapes on `ServerWorker::__construct()`/`configureHandler()`/`createSslContext()`, `PharBuilder::build()`/`buildExcludePatterns()`/`buildExcludeFiles()`/`generateStub()`, `BuildPathResolver::resolveBuildDir()`/`resolvePharPath()`/`resolveBinPath()`/`resolveFilename()`, and `WorkermanBundle::loadExtension()` — the shapes mirror the `ConfigurationTreeBuilder` definitions so PHPStan can verify config access and IDEs can autocomplete keys ([#332](https://github.com/crazy-goat/workerman-bundle/issues/332))

## [0.22.0] - 2026-05-30

### Security

- Route exception logging in `HttpRequestHandler` through the injected PSR-3 logger instead of `error_log()` to prevent sensitive data leaking to stderr; `error_log()` retained as fallback when no logger is available ([#296](https://github.com/crazy-goat/workerman-bundle/issues/296))
- StaticFilesMiddleware: add `follow_symlinks` option (default: `false`) to prevent symlink following under static root ([#292](https://github.com/crazy-goat/workerman-bundle/issues/292))
- ServerWorker: validate SSL cert/key paths are regular files and not symlinks ([#286](https://github.com/crazy-goat/workerman-bundle/issues/286))
- Add `connection_timeout`, `keepalive_timeout` and per-server `body_size_cap` for slowloris protection ([#279](https://github.com/crazy-goat/workerman-bundle/issues/279))

### Performance

- Cache PID file handles in `SchedulerWorker` to avoid `fopen`/`fclose` blocking syscalls in the event loop on every scheduled task fire — handles are opened once per PID file and reused across the worker's lifetime ([#297](https://github.com/crazy-goat/workerman-bundle/issues/297))
- Replace per-tick closure allocation in `SchedulerWorker` with first-class callable (`$this->onTickTimer(...)`) and pass task parameters via the timer args array ([#293](https://github.com/crazy-goat/workerman-bundle/issues/293))
- Cache `normalizeHeaderName` results and fix irregular header acronyms (ETag, Content-MD5) ([#287](https://github.com/crazy-goat/workerman-bundle/issues/287))
- Add early return in `FileUploadValidator::validate` when no uploaded files are present ([#281](https://github.com/crazy-goat/workerman-bundle/issues/281))

### Code Quality

- `ConfigLoader::getConfig`: split into named methods, replace silent empty-fallback with exception ([#325](https://github.com/crazy-goat/workerman-bundle/issues/325))
- `ConfigLoader`: move `setBuildConfig` into setters block ([#329](https://github.com/crazy-goat/workerman-bundle/issues/329))
- Make `TaskErrorEvent` immutable by removing unused `setError` mutator ([#338](https://github.com/crazy-goat/workerman-bundle/issues/338))
- Remove redundant `function_exists` checks in `InotifyMonitorWatcher` ([#341](https://github.com/crazy-goat/workerman-bundle/issues/341))
- Fix `InotifyMonitorWatcher::$pathByWd` PHPDoc type from `string[]` to `array<int, string>` ([#347](https://github.com/crazy-goat/workerman-bundle/issues/347))

### Deprecated

- `Utils::reboot()` is deprecated since 0.17.0 and remains deprecated; `Utils::reload()` is the replacement. `reboot()` is scheduled for removal in the next major release. No internal call sites remain in the bundle ([#318](https://github.com/crazy-goat/workerman-bundle/issues/318))

### Tests

- Add event ordering and `__invoke` fallback tests to `TaskHandlerTest` and `ProcessHandlerTest` ([#276](https://github.com/crazy-goat/workerman-bundle/issues/276))
- Add `onWorkerStart` invocation tests to `ServerWorkerTest` ([#284](https://github.com/crazy-goat/workerman-bundle/issues/284))
- Add in-process pipeline coverage and gate live-server test in `MiddlewareTest` ([#288](https://github.com/crazy-goat/workerman-bundle/issues/288))
- Add coverage for `processFiles` non-array drop branch in `RequestConverterTest` ([#294](https://github.com/crazy-goat/workerman-bundle/issues/294))
- Replace source-grep test in `SchedulerWorkerSigchldTest` with behavioral test using reflection ([#302](https://github.com/crazy-goat/workerman-bundle/issues/302))

### Docs

- Add class-level and constructor PHPDoc to `AsTask` and `AsProcess` attributes ([#309](https://github.com/crazy-goat/workerman-bundle/issues/309))
- Add class-level PHPDoc to `HttpRequestHandler` explaining the request lifecycle ([#320](https://github.com/crazy-goat/workerman-bundle/issues/320))
- Add class-level and method PHPDoc to `Request` class ([#321](https://github.com/crazy-goat/workerman-bundle/issues/321))
- Add class-level PHPDoc to Start/Error events marking them as extension points ([#335](https://github.com/crazy-goat/workerman-bundle/issues/335))
- Fix orphaned footnote notation for php-event extension note in README ([#311](https://github.com/crazy-goat/workerman-bundle/issues/311))
- Add License section and MIT badge to README so users can see the project's license at a glance ([#300](https://github.com/crazy-goat/workerman-bundle/issues/300))
- Normalise `**` list/emphasis markers to `*` / blockquote format across the README for consistent rendering ([#310](https://github.com/crazy-goat/workerman-bundle/issues/310))

## [0.21.0] - 2026-05-29

### Security

- Validate `kernel_class` in PHAR stub generation — reject invalid PHP class names to prevent code injection ([#263](https://github.com/crazy-goat/workerman-bundle/issues/263))
- Validate PHAR alias before stub generation — reject filenames with dangerous characters that could alter generated stub code ([#259](https://github.com/crazy-goat/workerman-bundle/issues/259))
- Restrict runtime directory creation to explicit `0700` mode — prevents other users on multi-user systems from reading PID/status files ([#270](https://github.com/crazy-goat/workerman-bundle/issues/270), [#274](https://github.com/crazy-goat/workerman-bundle/issues/274), [#453](https://github.com/crazy-goat/workerman-bundle/issues/453))

### Performance

- Pre-compose middleware pipeline once at startup instead of rebuilding on every request ([#266](https://github.com/crazy-goat/workerman-bundle/issues/266))
- Remove per-request `Timer::add(0, ...)` for terminate scheduling — reduces event-loop timer churn ([#273](https://github.com/crazy-goat/workerman-bundle/issues/273))
- Skip file processing in `RequestConverter` when no files are present in the request ([#277](https://github.com/crazy-goat/workerman-bundle/issues/277))

### Changed

- Remove `PharHelper::getProjectDir` — thin wrapper that duplicates `rtrim()` with no added value ([#316](https://github.com/crazy-goat/workerman-bundle/issues/316))
- Make `WorkermanCompilerPass` final — leaf class with no subclasses ([#312](https://github.com/crazy-goat/workerman-bundle/issues/312))
- Extract `buildServerBag()` and `detectFormData()` from `RequestConverter::toSymfonyRequest()` — reduces a 180-line method to coordinated delegates ([#301](https://github.com/crazy-goat/workerman-bundle/issues/301))
- Extract helper methods from `HttpRequestHandler::__invoke()` — eliminates duplicate terminate try/catch ([#291](https://github.com/crazy-goat/workerman-bundle/issues/291))
- Extract magic timeout numbers into named constants in `ServerManager` — replaces opaque formula comment ([#295](https://github.com/crazy-goat/workerman-bundle/issues/295))
- Extract shared `RecursiveDirectoryIterator` setup into a single method — removes duplicated boilerplate in Polling/Inotify watchers ([#285](https://github.com/crazy-goat/workerman-bundle/issues/285))
- Extract shared `AbstractErrorListener` and `AbstractHandler` base classes — eliminates near-identical code in Task/Process error listeners and handlers ([#278](https://github.com/crazy-goat/workerman-bundle/issues/278), [#275](https://github.com/crazy-goat/workerman-bundle/issues/275))
- Extract `configureHandler()` from `ServerWorker::onWorkerStart()` — reduces closure complexity

### Fixed

- Fix `StaticFilesMiddleware` to work with `phar://` stream wrappers — `realpath()` returns `false` for `phar://` paths, making the middleware unusable when running as PHAR/standalone binary ([#447](https://github.com/crazy-goat/workerman-bundle/issues/447))
- Fix `README.md` RebootStrategyInterface example — wrong FQCN caused copy-paste to fail ([#289](https://github.com/crazy-goat/workerman-bundle/issues/289))
- Add `ext-zip` and `ext-inotify` to CI, fix test assertions for missing extensions
- Fix PHPStan type annotations in test helpers

### Tests

- Add end-to-end tests for `Runner::run()` covering all decomposed entry points and process lifecycle ([#260](https://github.com/crazy-goat/workerman-bundle/issues/260))
- Cover full `ServerManager` public surface with integration tests ([#264](https://github.com/crazy-goat/workerman-bundle/issues/264))
- Invoke `HttpRequestHandler` in test instead of only testing construction and inheritance ([#253](https://github.com/crazy-goat/workerman-bundle/issues/253))
- Add tests for `AsProcess` and `AsTask` attributes covering all configuration options ([#247](https://github.com/crazy-goat/workerman-bundle/issues/247))
- Verify `gc_collect_cycles()` is actually invoked in `MemoryRebootStrategy` ([#271](https://github.com/crazy-goat/workerman-bundle/issues/271))

### Docs

- Add troubleshooting guide for long-running worker semantics — covers common pitfalls with stateful services, memory leaks, connection reuse ([#283](https://github.com/crazy-goat/workerman-bundle/issues/283))
- Resolve `@internal` vs public-API contradiction in `Utils` class — `Utils::reload()` is now explicitly documented as a public API for programmatic graceful worker reload. Removed `@internal` annotation, added PHPDoc, and documented usage in README ([#290](https://github.com/crazy-goat/workerman-bundle/issues/290))
- Disambiguate `bin/console` in README — clarify it refers to the application's console, not the bundle's `bin/` directory; add `bin/README.md` documenting the bundle's development scripts ([#282](https://github.com/crazy-goat/workerman-bundle/issues/282))
- Exclude `docs/superpowers/` planning artifacts from Composer package export ([#298](https://github.com/crazy-goat/workerman-bundle/issues/298))
- Expand `composer.json` keywords and description for Packagist discoverability ([#299](https://github.com/crazy-goat/workerman-bundle/issues/299))

## [0.20.0] - 2026-05-26

### Security

- Add extension denylist + allowlist filtering for static file serving in `StaticFilesMiddleware` ([#235](https://github.com/crazy-goat/workerman-bundle/issues/235))
- Fix TOCTOU race in `SchedulerWorker` PID file handling — uses exclusive flock with strict permissions ([#240](https://github.com/crazy-goat/workerman-bundle/issues/240))
- Add zip-slip protection to `SfxDownloader::extractZip` — validates entry paths against destination ([#252](https://github.com/crazy-goat/workerman-bundle/issues/252))
- Block cross-scheme redirects and require SHA-256 checksum for SFX downloads ([#433](https://github.com/crazy-goat/workerman-bundle/issues/433))

### Performance

- Add LRU cache and conditional `If-Modified-Since` / `If-None-Match` support to `StaticFilesMiddleware` — reduces redundant file reads and 304 responses ([#254](https://github.com/crazy-goat/workerman-bundle/issues/254))
- Shard `PollingMonitorWatcher` directory scan across ticks with `MAX_FILES_PER_TICK` — prevents event-loop starvation on large source trees ([#246](https://github.com/crazy-goat/workerman-bundle/issues/246))
- Defer `gc_collect_cycles` and call `memory_get_usage()` once instead of twice in `MemoryRebootStrategy` ([#250](https://github.com/crazy-goat/workerman-bundle/issues/250), [#272](https://github.com/crazy-goat/workerman-bundle/issues/272))
- Replace 10-year `DatePeriod` construction with O(1) `DateTime::add()` in `PeriodicalTrigger::getNextRunDate` ([#239](https://github.com/crazy-goat/workerman-bundle/issues/239))

### Added

- New `ListenScheme` enum for type-safe listen scheme configuration, replacing stringly-typed switch ([#305](https://github.com/crazy-goat/workerman-bundle/issues/305))
- New `BuildPathResolver` class consolidating duplicated `resolveXxxPath` helpers across build commands ([#242](https://github.com/crazy-goat/workerman-bundle/issues/242))
- New `ServiceMethod` value object replacing stringly-typed `"service::method"` concatenation ([#258](https://github.com/crazy-goat/workerman-bundle/issues/258))
- New `SfxSourceResolver` class extracted from `BuildBinCommand::resolveSfx` ([#238](https://github.com/crazy-goat/workerman-bundle/issues/238))
- New `e2e/README.md` explaining e2e directory purpose and contributor guidance

### Changed

- Moved PHAR stub from inline HEREDOC to `resources/phar-stub.tpl` template file ([#234](https://github.com/crazy-goat/workerman-bundle/issues/234))
- Split `ServicesConfigurator::configure()` into per-domain private methods ([#249](https://github.com/crazy-goat/workerman-bundle/issues/249))
- Split `SfxDownloader::extractZip` into staged methods with typed exception ([#251](https://github.com/crazy-goat/workerman-bundle/issues/251))
- Replaced stringly-typed listen-scheme switch with `ListenScheme` enum ([#305](https://github.com/crazy-goat/workerman-bundle/issues/305))
- `SchedulerWorker::$handler` is now readonly on a final class — prevents latent read-before-init bug ([#262](https://github.com/crazy-goat/workerman-bundle/issues/262))
- `SupervisorWorker` is now `final` (consistent with other workers) ([#265](https://github.com/crazy-goat/workerman-bundle/issues/265))

### Fixed

- Symfony version matrix badge in README now includes `^8.0` to match `composer.json` constraint ([#257](https://github.com/crazy-goat/workerman-bundle/issues/257))

### Tests

- Add `KernelFactoryTest` covering factory creation, boot, and shutdown ([#224](https://github.com/crazy-goat/workerman-bundle/issues/224))
- Add `RuntimeTest` covering runtime path resolution and environment handling ([#228](https://github.com/crazy-goat/workerman-bundle/issues/228))
- Add `ResolverTest` covering `resolve()` tuple shape, closure invocation, and error propagation ([#230](https://github.com/crazy-goat/workerman-bundle/issues/230))
- Add `ByteFormatterTest` covering all unit boundaries and fractional values ([#241](https://github.com/crazy-goat/workerman-bundle/issues/241))
- Add `TaskErrorListenerTest` and `ProcessErrorListenerTest` covering error dispatch and logging ([#237](https://github.com/crazy-goat/workerman-bundle/issues/237))

### Docs

- Add `UPGRADE.md` covering breaking changes from 0.12 through 0.17 ([#256](https://github.com/crazy-goat/workerman-bundle/issues/256))
- Document `build.sfx.sha256` and `build.sfx.allow_insecure` configuration options ([#267](https://github.com/crazy-goat/workerman-bundle/issues/267))
- Document `workerman:server` connections output columns ([#269](https://github.com/crazy-goat/workerman-bundle/issues/269))
- Clean up stale "What's new in this fork" section in README — no longer advertises deprecated `serve_files` ([#268](https://github.com/crazy-goat/workerman-bundle/issues/268))
- Add `e2e/README.md` explaining e2e directory purpose and contributor guidance

## [0.19.0] - 2026-05-25

### Security

- Validate URI and HTTP method in `RequestConverter` before propagation to Symfony — prevents header injection via crafted requests ([#220](https://github.com/crazy-goat/workerman-bundle/issues/220))
- Cookie header merged with comma allows cookie smuggling — added strict cookie parsing ([#217](https://github.com/crazy-goat/workerman-bundle/issues/217))
- `StaticFilesMiddleware` path traversal — replaced naive concatenation with explicit path-join helper ([#226](https://github.com/crazy-goat/workerman-bundle/issues/226))
- Add `trusted_hosts` configuration option for Host header enforcement — non-matching hosts return 400 before kernel boot ([#213](https://github.com/crazy-goat/workerman-bundle/issues/213))
- Nullify request/response references in `SymfonyController` on exception path — prevents request-scope memory leak across requests ([#303](https://github.com/crazy-goat/workerman-bundle/issues/303))

### Performance

- `DefaultResponseStrategy` sends large responses in chunks with configurable chunk size, eliminating full-body buffering ([#236](https://github.com/crazy-goat/workerman-bundle/issues/236))
- `StreamedResponseStrategy` streams body in chunks via `ob_start` callback instead of buffering entire body — reduces peak RSS from response size to `chunk_size * 2` ([#229](https://github.com/crazy-goat/workerman-bundle/issues/229))
- `BinaryFileResponseStrategy` chains `onClose` callbacks instead of overwriting, enabling multiple cleanup handlers ([#225](https://github.com/crazy-goat/workerman-bundle/issues/225))
- Batch-load all `ReflectionProperty` instances in a single `ReflectionClass` call on the hot path ([#222](https://github.com/crazy-goat/workerman-bundle/issues/222))
- Extract `BinaryFileResponseReflector` — consolidates reflection helpers and caches them by file class ([#223](https://github.com/crazy-goat/workerman-bundle/issues/223))

### Added

- New `ProcessInspector` and `StatusFileReader` classes extracted from `ServerManager` god class ([#211](https://github.com/crazy-goat/workerman-bundle/issues/211))
- New `PharFileFilter` and `ExcludePattern` named classes extracted from `PharBuilder` inline filter ([#227](https://github.com/crazy-goat/workerman-bundle/issues/227))
- New `BinaryFileResponseReflector` helper class for cached reflection access ([#223](https://github.com/crazy-goat/workerman-bundle/issues/223))
- New `FileUploadValidator` focused validation methods replacing monolithic `validateFileEntry` ([#208](https://github.com/crazy-goat/workerman-bundle/issues/208))
- `PharHelper::resolveRuntimePath()` as the single shared method for PHAR path resolution ([#211](https://github.com/crazy-goat/workerman-bundle/issues/211))

### Changed

- `ConfigLoader` is now injected directly into `ServerManager` instead of probing the DI container via service locator ([#214](https://github.com/crazy-goat/workerman-bundle/issues/214))
- `Runner::run()` refactored into focused helper methods with clear single responsibilities ([#210](https://github.com/crazy-goat/workerman-bundle/issues/210))
- `SchedulerWorker::runCallback()` refactored into extracted parent/child/error branches with shared cleanup ([#219](https://github.com/crazy-goat/workerman-bundle/issues/219))
- `ConfigurationTreeBuilder::configure()` split into per-section builders reducing a 260-line method to coordinated delegates ([#216](https://github.com/crazy-goat/workerman-bundle/issues/216))
- `RequestConverter::processFiles()` refactored to handle non-array file entries with proper logging ([#207](https://github.com/crazy-goat/workerman-bundle/issues/207))

### Tests

- Add `SchedulerWorker` behavioral tests covering fork, flock, and PID lifecycle ([#209](https://github.com/crazy-goat/workerman-bundle/issues/209))
- Add `InotifyMonitorWatcherTest` covering `isFlagSet`, `start`, `watchDir`, `onNotify` ([#212](https://github.com/crazy-goat/workerman-bundle/issues/212))
- Add `FileMonitorWorkerTest` covering file monitor worker lifecycle ([#218](https://github.com/crazy-goat/workerman-bundle/issues/218))
- Add `SupervisorWorkerTest` covering process lifecycle, signal handling, and error dispatch ([#215](https://github.com/crazy-goat/workerman-bundle/issues/215))
- Add `FileMonitorWatcherTest` for `create()` factory and `checkPattern()` ([#221](https://github.com/crazy-goat/workerman-bundle/issues/221))
- Add `PharHelper` unit tests for `resolveRuntimePath()` ([#211](https://github.com/crazy-goat/workerman-bundle/issues/211))

### Docs

- Add Configuration reference section covering all top-level config options ([#243](https://github.com/crazy-goat/workerman-bundle/issues/243))
- Add `docs/README.md` index page for user-facing documentation ([#244](https://github.com/crazy-goat/workerman-bundle/issues/244))
- Document `reload_strategy.memory` in README ([#233](https://github.com/crazy-goat/workerman-bundle/issues/233))
- Add Middlewares section to README with `StaticFilesMiddleware` example ([#231](https://github.com/crazy-goat/workerman-bundle/issues/231))
- Document that `servers.listen` is effectively required ([#232](https://github.com/crazy-goat/workerman-bundle/issues/232))
- Use unprivileged port (8080) in README quick-start example ([#245](https://github.com/crazy-goat/workerman-bundle/issues/245))

### Fixed

- Reorder `CHANGELOG.md` 0.16.0 to correct reverse-chronological position ([#255](https://github.com/crazy-goat/workerman-bundle/issues/255))

## [0.18.0] - 2026-05-20

### Added

- PHAR and standalone binary packaging support ([#191](https://github.com/crazy-goat/workerman-bundle/issues/191))
  - New `workerman:build:phar` command to build PHAR archives with dynamic stub
  - New `workerman:build:bin` command to create standalone binaries (SFX + custom php.ini + PHAR)
  - New `PharHelper` utility for detecting PHAR mode and resolving runtime paths outside the archive
  - New `build` configuration section for PHAR exclusions, custom php.ini, and SFX sources
  - `--kernel-class` CLI option for overriding kernel class in PHAR stub
  - File monitor automatically disabled in PHAR mode
  - `ConfigLoader` fallback when cache is missing for PHAR scenarios
  - See [docs/build-packaging.md](docs/build-packaging.md) for full documentation and examples

### Changed

- `Runner` source path is now configurable instead of hardcoded to `tests/App` ([#130](https://github.com/crazy-goat/workerman-bundle/issues/130))

### Fixed

- Improved cache warmup error messages to include exit codes, signal numbers, and status details ([#129](https://github.com/crazy-goat/workerman-bundle/issues/129))
- Closed `proc_open` pipes in test bootstrap to prevent file descriptor leaks ([#170](https://github.com/crazy-goat/workerman-bundle/issues/170))
- Replaced `boolval()` with `(bool)` cast for consistent style across the codebase ([#159](https://github.com/crazy-goat/workerman-bundle/issues/159))
- Added missing `final` keyword to `MiddlewareTest` and `StaticFilesMiddlewareTest` ([#168](https://github.com/crazy-goat/workerman-bundle/issues/168))
- Removed redundant `getFileInfo()` call on `SplFileInfo` object in `PollingMonitorWatcher` ([#166](https://github.com/crazy-goat/workerman-bundle/issues/166))
- Replaced deprecated `LevelSetList::UP_TO_PHP_82` with `withPhpSets()` in `rector.php` ([#164](https://github.com/crazy-goat/workerman-bundle/issues/164))
- Enabled `composer audit block-insecure` to report insecure packages instead of silently ignoring them ([#43](https://github.com/crazy-goat/workerman-bundle/issues/43))
- Aligned test namespace with PSR-4 `autoload-dev` mapping ([#167](https://github.com/crazy-goat/workerman-bundle/issues/167))
- Updated `phpunit.xml` schema version to match installed PHPUnit 10.5 ([#162](https://github.com/crazy-goat/workerman-bundle/issues/162))
- Pinned PHP version to 8.2 in CI lint job for deterministic behavior ([#169](https://github.com/crazy-goat/workerman-bundle/issues/169))
- Replaced flaky composer audit shell-based test with resilient JSON-based E2E tests ([#188](https://github.com/crazy-goat/workerman-bundle/issues/188))

## [0.17.0] - 2026-05-19

### Added

- Added `Utils::reload()` method as the canonical name for graceful worker restart ([#32](https://github.com/crazy-goat/workerman-bundle/issues/32))
  - `Utils::reboot()` is preserved as a deprecated alias with deprecation notice
  - Updated all internal callers and watcher classes
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-017) for details

- Added `SymfonyController` injection via DI into `HttpRequestHandler` ([#158](https://github.com/crazy-goat/workerman-bundle/issues/158))
  - `HttpRequestHandler` now accepts `SymfonyController $controller` via constructor injection
  - `WorkermanCompilerPass` registers `workerman.symfony_controller` service with autowiring alias
  - Removed unused `KernelInterface` and `ResponseConverter` dependencies
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-017) for details

### Changed

- Replaced `require` pattern in `WorkermanBundle` with proper injectable classes ([#145](https://github.com/crazy-goat/workerman-bundle/issues/145))
  - Extracted configuration tree building into `ConfigurationTreeBuilder`
  - Extracted service registration into `ServicesConfigurator`
  - Removed `src/config/configuration.php` and `src/config/services.php`

- Removed unnecessary `array_map` calls and simplified data flow in `WorkermanCompilerPass` ([#24](https://github.com/crazy-goat/workerman-bundle/issues/24))

- composer.json `audit.abandoned` config from `"ignore"` to `"report"` so abandoned package warnings are no longer silently suppressed ([#163](https://github.com/crazy-goat/workerman-bundle/issues/163))

### Fixed

- Removed FPM-specific no-op calls (`ignore_user_abort()`, `connection_aborted()`) from `StreamedBinaryFileResponse` — these have no effect in Workerman's event-driven architecture ([#160](https://github.com/crazy-goat/workerman-bundle/issues/160))
  - Added 14 unit tests and 1 E2E test for streamed binary file response

- Extracted magic string `'+10 year'` to class constant `MAX_SCHEDULE_HORIZON` in `PeriodicalTrigger` ([#156](https://github.com/crazy-goat/workerman-bundle/issues/156))

### Removed

- Removed dead `StreamResponseInterface` and `streamContent()` method from `StreamedBinaryFileResponse` — the generator-based streaming was never called by the response pipeline; `BinaryFileResponseStrategy` handles it via `withFile()` ([#165](https://github.com/crazy-goat/workerman-bundle/issues/165))
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-017) for details

- Removed 8 permanently skipped tests from `HttpRequestHandlerTest` that were never executed ([#154](https://github.com/crazy-goat/workerman-bundle/issues/154))

## [0.16.0] - 2026-05-18

### Added

- Added configurable cache warmup timeout ([#142](https://github.com/crazy-goat/workerman-bundle/issues/142), [#180](https://github.com/crazy-goat/workerman-bundle/pull/180))
  - New `cache_warmup_timeout` configuration node with `min(1)` validation
  - New `WORKERMAN_CACHE_WARMUP_TIMEOUT` environment variable support
  - Removed hardcoded `CACHE_WARMUP_TIMEOUT` from Runner

### Security

- RequestConverter no longer trusts `X-Forwarded-Proto` header
  unconditionally. HTTPS is now detected only from the actual SSL transport
  layer. Users behind reverse proxies must configure Symfony's trusted
  proxies. ([#152](https://github.com/crazy-goat/workerman-bundle/issues/152))
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-016) for details

### Fixed

- Fixed `Runner::run()` — `mkdir()` return value now checked to prevent silent failures ([#151](https://github.com/crazy-goat/workerman-bundle/issues/151))
  - Throws `\RuntimeException` with clear message when directory creation fails
  - Double `is_dir()` check handles race condition between check and `mkdir()` call

- Fixed `Runner::run()` — added timeout for cache warmup, use `posix_kill` instead of `exit` ([#141](https://github.com/crazy-goat/workerman-bundle/issues/141))
  - Added `CACHE_WARMUP_TIMEOUT` constant (30 seconds)
  - Use `posix_kill()` to avoid deadlock with extensions that register shutdown handlers
  - Handle SIGKILL as success, SIGTERM as error

- Fixed `ProcessHandler` and `TaskHandler` — validate dynamic method calls to prevent worker crashes ([#153](https://github.com/crazy-goat/workerman-bundle/issues/153), [#174](https://github.com/crazy-goat/workerman-bundle/pull/174))
  - Extracted shared validation into `ServiceMethodHelper`
  - Moved method existence check inside try-catch for graceful error handling

- Fixed `Utils::cpuCount()` — handle null from `shell_exec('nproc')` ([#150](https://github.com/crazy-goat/workerman-bundle/issues/150))
  - Added `is_string()` check for nproc output
  - Return 1 as safe fallback when nproc is unavailable (e.g., minimal containers)

- Fixed `PeriodicalTrigger` — removed useless `assert()` ([#178](https://github.com/crazy-goat/workerman-bundle/issues/178))

- Fixed `SchedulerWorker` — log exceptions in child process instead of swallowing ([#178](https://github.com/crazy-goat/workerman-bundle/issues/178))
  - When a scheduled task throws an exception in a forked child process,
    the exception is now logged with diagnostic information

- Fixed `ServerManager` — replaced hardcoded `sleep(1)` with polling loop ([#155](https://github.com/crazy-goat/workerman-bundle/issues/155), [#179](https://github.com/crazy-goat/workerman-bundle/pull/179))
  - Applied polling pattern to `getServerStatus()` and `getConnections()`

- Fixed `WorkermanCompilerPass::referenceMap()` — improved PHPDoc ([#171](https://github.com/crazy-goat/workerman-bundle/issues/171))
  - Described what the method does
  - Tightened `@param` type to match `findTaggedServiceIds()` return shape

- Fixed CI: upgraded `actions/checkout` from v2 to v6.0.2 with SHA pinning ([#172](https://github.com/crazy-goat/workerman-bundle/pull/172))
- Fixed CI: pinned `shivammathur/setup-php` to commit SHA in tests workflow ([#149](https://github.com/crazy-goat/workerman-bundle/issues/149), [#175](https://github.com/crazy-goat/workerman-bundle/pull/175))

## [0.15.0] - 2026-04-15

### Security

- Enabled branch protection on `master` branch ([#132](https://github.com/crazy-goat/workerman-bundle/issues/132))
  - Required status checks: `Tests/lint` and `Tests/tests`
  - Require branches to be up to date before merging
  - Require conversation resolution before merging
  - No approval count required (solo dev project)

### Added

- Added `ServerAction` enum for type-safe command actions ([#135](https://github.com/crazy-goat/workerman-bundle/issues/135))
  - Replaces string-based action constants with strongly-typed enum
  - Cases: START, STOP, RESTART, RELOAD, STATUS
  - Used by `WorkermanCommand` and `ServerManager` for improved type safety

- Added validation in `ConfigLoader::warmUp()` to ensure all config sections are set before caching ([#35](https://github.com/crazy-goat/workerman-bundle/issues/35))
  - New `ConfigSection` enum with cases: WORKERMAN, PROCESS, SCHEDULER
  - Throws `LogicException` with descriptive message when any section is missing
  - Prevents incomplete configuration from being cached

- Added pre-push git hook to run `composer lint` before pushing ([#137](https://github.com/crazy-goat/workerman-bundle/issues/137))
  - Hook runs static analysis and code style checks before push
  - Prevents pushing code that doesn't pass linting

### Fixed

- Fixed `TriggerFactory` fragile cron expression detection heuristic ([#34](https://github.com/crazy-goat/workerman-bundle/issues/34), [#138](https://github.com/crazy-goat/workerman-bundle/issues/138))
  - Uses `CronExpression::isValidExpression()` for robust detection instead of exception-based heuristic
  - Added `class_exists` check for graceful handling when package is not installed

- Fixed `SupervisorWorker` — removed `sleep(1)` hack and added proper logging ([#36](https://github.com/crazy-goat/workerman-bundle/issues/36), [#143](https://github.com/crazy-goat/workerman-bundle/issues/143))
  - Removed arbitrary 1-second sleep that was causing race conditions
  - Added proper logging for state transitions and errors
  - Improved reliability of worker supervision

- Fixed `WorkermanCompilerPass` — replaced anonymous class with proper named class ([#37](https://github.com/crazy-goat/workerman-bundle/issues/37), [#144](https://github.com/crazy-goat/workerman-bundle/issues/144))
  - Extracted anonymous class from `config/compilerpass.php` to `src/DependencyInjection/WorkermanCompilerPass.php`
  - Added comprehensive unit tests for compiler pass functionality

### Changed

- **Breaking**: Config cache format changed from numeric indices to string keys ([#35](https://github.com/crazy-goat/workerman-bundle/issues/35))
  - **Old format:** `[0 => ..., 1 => ..., 2 => ...]`
  - **New format:** `['workerman' => ..., 'process' => ..., 'scheduler' => ...]`
  - Uses `ConfigSection` enum values as keys for clarity and type safety
  - **Migration**: Clear cache after upgrade: `rm -rf var/cache/*`
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-015) for details

## [0.14.0] - 2026-04-14

### Deprecated

- `Request::withHeader()` is deprecated ([#38](https://github.com/crazy-goat/workerman-bundle/issues/38))
  - Use `setHeader()` instead
  - `withHeader()` is kept as alias for backward compatibility

### Added

- Added `ServerWorker` SSL certificate validation for HTTPS/WSS servers ([#18](https://github.com/crazy-goat/workerman-bundle/issues/18))
  - Validates that `local_cert` and `local_pk` are provided for SSL transport
  - Checks that certificate and key files are readable
  - Throws clear `\InvalidArgumentException` messages instead of cryptic SSL errors

### Fixed

- **Critical**: Fixed `Middleware Pipeline` — closure capturing wrong request in middleware chain ([#21](https://github.com/crazy-goat/workerman-bundle/issues/21))
  - Changed closure to use `$input` parameter instead of outer scope `$request`
  - Request-modifying middleware now correctly affects subsequent middleware in the chain

- Fixed `KernelFactory` — singleton kernel state reset between requests ([#22](https://github.com/crazy-goat/workerman-bundle/issues/22))
  - Kernel now properly resets services between requests to prevent memory leaks
  - Uses Symfony's `services_resetter` to reset services tagged with `kernel.reset`

- Fixed `RequestConverter` — missing nested file handling ([#26](https://github.com/crazy-goat/workerman-bundle/issues/26))
  - Forms with `files[0]`, `files[avatar]`, or `<input name="documents[]" multiple>` now work correctly
  - Added recursive file processing for nested file arrays

- Fixed `ResponseConverter` — generic HTTP header normalization ([#25](https://github.com/crazy-goat/workerman-bundle/issues/25))
  - All headers are now properly normalized from lowercase to PascalCase
  - Previously only 6 headers were normalized; now uses generic transformation

- Fixed `Runner` — proper error handling for fork and cache warmup ([#23](https://github.com/crazy-goat/workerman-bundle/issues/23))
  - `pcntl_fork()` error (`-1`) now throws exception instead of falling into indefinite wait
  - Child process exit code properly reflects boot success/failure
  - Parent process now detects cache warmup failures in forked child

### Changed

- **Breaking**: `ResponseConverterStrategyInterface::convert()` now requires a `TcpConnection` parameter
  - All strategy implementations must be updated to accept the new parameter
  - Enables connection-aware features like immediate temp file cleanup on connection close
  - **Migration**: Add `TcpConnection $connection` parameter to your custom strategy's `convert()` method
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-014) for details

- `BinaryFileResponseStrategy` now deletes temp files immediately after connection closes
  - Uses Workerman's `onClose` callback instead of loading file into memory
  - Works correctly for both small and large files (no timing issues with `Timer::add`)
  - Cleaner lifecycle management — cleanup tied to actual connection state
  - More memory efficient: files are streamed directly from disk instead of being loaded into memory

- `RequestConverter::toSymfonyRequest()` now returns empty content for multipart/form-data requests
  - Matches PHP-FPM behavior where `php://input` is not available for multipart
  - Previously `getContent()` returned full raw body including file contents
  - Files remain accessible via `$request->files` as before
  - **Migration**: If your code relies on reading raw multipart body via `getContent()`, you'll need to adapt it
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-014) for details

- `StreamedBinaryFileResponse::streamContent()` simplified chunking logic
  - Removed redundant inner while loop that was effectively a no-op
  - Fixed length calculation to use actual data length (`strlen($data)`) instead of requested bytes (`$read`)
  - Fixes incorrect chunk count for files where fread() returns fewer bytes than requested
  - Fixes (#27)

## [0.13.0] - 2026-04-05

### Added

- Added test helper methods in `RequestConverterTest` for temp file cleanup and request creation ([#88](https://github.com/crazy-goat/workerman-bundle/issues/88))
  - Added `tearDown()` for automatic temp file cleanup
  - Added `createTempFile()` helper method
  - Added `createRequestWithFiles()` helper method
  - Reduces test boilerplate and improves readability

- Added `QUERY_STRING` to server bag in `RequestConverter` ([#66](https://github.com/crazy-goat/workerman-bundle/issues/66))
  - Enables `$request->server->get('QUERY_STRING')` to return query string
  - Enables Symfony's `getQueryString()` to work correctly

- Added `REQUEST_TIME` and `REQUEST_TIME_FLOAT` to server bag in `RequestConverter` ([#67](https://github.com/crazy-goat/workerman-bundle/issues/67))
  - Enables Symfony profiler and debug toolbar to show request duration
  - Sets values using `time()` and `microtime(true)` at request conversion time

- Added `SERVER_PORT` and `SERVER_NAME` to server bag in `RequestConverter` ([#65](https://github.com/crazy-goat/workerman-bundle/issues/65))
  - Enables `$request->getPort()` for non-standard ports (8080, 8443, etc.)
  - Required for Symfony's `getPort()` to return correct value when Host header has no port
  - Falls back to port 80 and localhost when connection is not available
  - Detects HTTPS from port 443 or `X-Forwarded-Proto: https` header
  - Sets `HTTPS=on` for HTTPS requests, enabling proper `getScheme()` behavior

- Added E2E tests for `StreamedResponse` in `SymfonyControllerTest` ([#69](https://github.com/crazy-goat/workerman-bundle/issues/69))

- Added E2E tests for HTTPS detection in `SymfonyControllerTest` ([#64](https://github.com/crazy-goat/workerman-bundle/issues/64))
  - Tests verify HTTPS detection from port 443 and X-Forwarded-Proto header
  - Tests validate `isSecure()`, `getScheme()` behavior

### Fixed

- Added E2E test verifying `SERVER_PROTOCOL` includes HTTP/ prefix ([#60](https://github.com/crazy-goat/workerman-bundle/issues/60))
  - Test validates fix from PR #101: `'HTTP/' . $rawRequest->protocolVersion()`

### Changed

- **Critical**: Priority-based strategy ordering is now enforced in compiler pass
  - Strategies are sorted by priority tag value (descending) before registration
  - Makes strategy ordering resilient to service registration order changes
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-013) for details

## [0.12.0] - 2026-04-04

### Added

- Extracted `ResponseConverter` from `SymfonyController` using Strategy Pattern ([#72](https://github.com/crazy-goat/workerman-bundle/issues/72))
  - New `ResponseConverterStrategyInterface` for pluggable response conversion strategies
  - New `ResponseConverter` orchestrator that selects and executes appropriate strategy based on response type
  - New `DefaultResponseStrategy` for handling standard Symfony responses
  - New `NoResponseStrategyException` for when no matching strategy is found
  - Priority-based strategy registration via DI container tags (`workerman.response_converter.strategy`)
  - Foundation for implementing BinaryFileResponse, StreamedResponse, and EventStreamResponse support (#69, #70, #71)

- Added `BinaryFileResponseStrategy` for proper file download support ([#70](https://github.com/crazy-goat/workerman-bundle/issues/70))
  - Handles `BinaryFileResponse` using Workerman's native `withFile()` for efficient streaming
  - Supports `SplTempFileObject` (in-memory temp files) by reading content directly to body
  - Supports `deleteFileAfterSend` by reading file into memory and deleting immediately
  - Supports range requests (offset/maxlen) for partial content delivery
  - Uses reflection with graceful fallback for accessing Symfony's private properties

- Added `StreamedResponseStrategy` for `StreamedResponse` and `EventStreamResponse` (SSE) support ([#71](https://github.com/crazy-goat/workerman-bundle/issues/71))
  - Uses output buffering to capture streamed content and convert to Workerman Response
  - Registered with priority 50 (between BinaryFileResponse=100 and Default=0)
  - Phase 1 implementation: finite streams only; infinite SSE streams (generators with yield) will block until completion

- Typed exception hierarchy for better error handling and monitoring ([#93](https://github.com/crazy-goat/workerman-bundle/issues/93))
  - New `WorkermanExceptionInterface` marker interface to catch all bundle exceptions
  - New `WorkermanException` abstract base class extending `\RuntimeException`
  - Domain-specific exception hierarchies:
    - `ServerException` with `ServerAlreadyRunningException`, `ServerNotRunningException`, `ServerStopFailedException`
    - `ValidationException` (extends `\InvalidArgumentException`) with `FileUploadValidationException`, `ConfigurationValidationException`
    - `SchedulerException` (extends `\InvalidArgumentException`) with `InvalidTriggerException`, `InvalidCronExpressionException`
    - `MiddlewareException` (extends `\InvalidArgumentException`) with `InvalidMiddlewareException`, `StaticFileMiddlewareException`
    - `KernelException` with `KernelCreationException`, `InvalidCacheDirectoryException`
  - All new exception classes are `final` with single-responsibility names

### Changed

- **Breaking**: Replaced generic PHP exceptions with typed exceptions throughout the codebase
  - 9 `\InvalidArgumentException` throw sites replaced with domain-specific validation/scheduler/middleware exceptions
  - 2 `\RuntimeException` throw sites replaced with `KernelCreationException` and `InvalidCacheDirectoryException`
  - 1 `\LogicException` throw site replaced with `InvalidCronExpressionException`
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-012) for details

### Removed

- **Breaking**: Removed root-level exception classes (moved to `Exception` namespace)
  - `CrazyGoat\WorkermanBundle\ServerAlreadyRunningException` → `CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException`
  - `CrazyGoat\WorkermanBundle\ServerNotRunningException` → `CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException`
  - `CrazyGoat\WorkermanBundle\ServerStopFailedException` → `CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException`
  - See [UPGRADE.md](UPGRADE.md#upgrading-to-012) for details

### Fixed

- **Critical**: Fixed `RequestConverter` missing `REMOTE_ADDR`, breaking `getClientIp()` and trusted proxies ([#61](https://github.com/crazy-goat/workerman-bundle/issues/61))
  - Reads client IP and port from Workerman's `TcpConnection` object
  - `$request->getClientIp()` now returns actual client IP instead of null
  - Trusted proxy mechanism (`isFromTrustedProxy()`, `X-Forwarded-*` headers) now works correctly
  - Fallback values (`127.0.0.1:0`) provided for unit test scenarios

- **Critical**: Fixed `BinaryFileResponse` returning empty body for file downloads ([#70](https://github.com/crazy-goat/workerman-bundle/issues/70))
  - `BinaryFileResponse::getContent()` returns `false`, causing empty responses
  - New `BinaryFileResponseStrategy` uses Workerman's `withFile()` for proper streaming
  - File downloads via `$this->file()` or `BinaryFileResponse` now work correctly

- **Critical**: Fixed RequestConverter bypassing ServerBag, breaking HTTP authentication and server bag reads ([#59](https://github.com/crazy-goat/workerman-bundle/issues/59))
  - HTTP headers are now converted to `HTTP_*` format in server bag (CGI convention)
  - `Authorization` header is correctly parsed into `PHP_AUTH_USER`/`PHP_AUTH_PW` for Basic/Digest auth
  - `Content-Type`, `Content-Length`, `Content-MD5` use CGI convention (no `HTTP_` prefix)
  - `SERVER_PROTOCOL` now has correct `HTTP/1.1` format instead of `1.1`
  - `$request->getUser()` and `$request->getPassword()` now work correctly
  - `$request->server->get('HTTP_HOST')` and other server bag reads now return expected values

### Migration Guide

For detailed migration instructions for all breaking changes, see [UPGRADE.md](UPGRADE.md#upgrading-to-012).
