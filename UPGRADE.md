# Upgrade Guide

This document lists breaking changes between releases and describes how to migrate from one version to another.

---

## Deprecations

The table below lists the deprecations currently carried by the bundle and
the version in which each is scheduled for removal. The removal target is
**1.0** — the next major (SemVer) version — for every entry. This is the
forward-looking view; past migrations live in the sections below.

| Deprecated since | Feature | Replacement | Removed in |
| --- | --- | --- | --- |
| 0.9.3 | `serve_files` / `root_dir` server options and the `static_files` node | `StaticFilesMiddleware`, registered as a service and listed under `middlewares` (see [docs/security.md#static-files-protection](docs/security.md#static-files-protection)) | 1.0 |
| 0.17.0 | `Utils::reboot()` | `Utils::reload()` | 1.0 |
| 0.23.0 | `Request::withHeader()` | `Request::setHeader()` | 1.0 |

> When migrating `serve_files`/`root_dir` to `StaticFilesMiddleware`, note
> that the `static_files.allowed_extensions` allowlist no longer applies —
> the middleware reads its allowlist from the `$allowedExtensions` constructor
> argument instead.

---

## Upgrading to 0.28

### `RebootStrategyInterface::needsPeakMemory()` removed

`RebootStrategyInterface::needsPeakMemory()` and the `memory_reset_peak_usage()` gating in `HttpRequestHandler` have been removed ([#562](https://github.com/crazy-goat/workerman-bundle/issues/562)). No shipped strategy ever returned `true` — the mechanism was dead code since its introduction in 0.17.0 ([#317](https://github.com/crazy-goat/workerman-bundle/issues/317)) — and `MemoryRebootStrategy` correctly stays on `memory_get_usage()` (emalloc), whose post-`gc_collect_cycles()` reading benefits from the GC optimization. Peak-based reloads would not benefit from that GC path (peak does not drop after collection).

**Migration:** if you implement `RebootStrategyInterface` in your own code, remove the `needsPeakMemory(): bool` method:

```php
// Before
final class MyStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool { return false; }
    public function needsPeakMemory(): bool { return false; }
}

// After
final class MyStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool { return false; }
}
```

If your custom strategy tracked `memory_get_peak_usage()` and relied on `HttpRequestHandler` to call `memory_reset_peak_usage()` before each request, call it yourself at the start of `shouldReboot()` (or in a middleware that runs before the reboot check):

```php
public function shouldReboot(): bool
{
    $peak = memory_get_peak_usage();
    memory_reset_peak_usage();
    return $peak > $this->limit;
}
```

---

## Upgrading to 0.25

### Master identification now fails closed — stop before upgrading

Since 0.25.0 the bundle verifies the identity of the Workerman master
process before sending `stop` / `reload` / `status` signals: a PID that
cannot be verified is refused instead of being signalled (issue #584,
[hardening details](docs/security.md#master-process-fingerprint-pid-file-hardening)).
Verification needs the `.fingerprint` sidecar that the new version writes
next to the pid file on every start. Servers started by older versions
have no such sidecar, and the fallback that replaces it is strictly
limited:

- On **Linux** the fallback matches the exact process title Workerman
  assigns to its master (`WorkerMan: master process ...`). It usually
  works, but fails closed when `/proc/$pid/cmdline` is unreadable
  (hidepid, master owned by another user) or on PHP >= 8.5 builds whose
  `cli_set_process_title()` does not rewrite the argv visible there.
- On **non-Linux hosts (macOS, BSD)** there is no command-line fallback
  at all: without a fingerprint file, `stop`, `reload` and `status`
  report `Cannot verify master process <pid>` (or `Workerman is not running`
  when no pid file exists), even while the server is up.

Consequence: upgrading the bundle while a server started by an older
version is still running can silently turn the control commands into
no-ops.

**Recommended upgrade path**

1. Stop the server **before** deploying the new code (with the old code
   the old identification still works).
2. Deploy and start the new version — the fingerprint sidecar is written
   automatically (foreground and daemon mode), and control commands work
   from then on.

**If you already upgraded with a running master** and the commands report
`Cannot verify master process <pid>` (the PID is alive but its identity
cannot be confirmed): recover by terminating the old master by
hand. Read the PID from the pid file, verify with
`ps -p <pid> -o pid,comm,args` that it is the process you started, and
`kill` it (never use a bare `pkill -f WorkerMan` — it would kill
unrelated Workerman applications on the host). Remove any leftover pid
file, then start the new version once. A single start restores the
control plane. If the commands report `Workerman is not running` instead,
the master is not alive — just remove the stale pid file and start.

### `start -d` returns before the master has written its pid file

`start -d` returns as soon as the double fork completes, but the pid file
— and with it the fingerprint sidecar — is written by the master process
itself a moment later (`MasterWorker::saveMasterPid()`, issue #584). A
`status`, `stop` or `reload` in that short window reports
`Workerman is not running` (no pid file yet) or
`Cannot verify master process <pid>` (pid file written but no fingerprint
sidecar yet); wait for the pid file (and its
`.fingerprint` sidecar) to appear, then retry.

### Config cache and runtime user

The other upgrade-relevant 0.25.0 change: the configuration cache file
must be owned by the process that loads it (a cache warmed up by a
different user is refused at boot). See
[Config cache and runtime user](README.md#config-cache-and-runtime-user).

---

## Upgrading to 0.24

No mandatory configuration change for **0.24.0**. Notable hardening in that
release (fingerprint sidecar for master-process identification — #327,
ReDoS guard on `exclude_patterns` — #334, middleware header-re-injection
note — #344) requires no migration.

### `RequestConverter` now throws `MalformedRequestException` (0.24.1)

`RequestConverter` throws `MalformedRequestException` (extends
`\InvalidArgumentException`, implements `ClientInputExceptionInterface`)
instead of bare `\InvalidArgumentException` for malformed client input
(control bytes in headers, invalid URI/method). A top-level
`\InvalidArgumentException` catch still catches it, but a catch that
distinguishes client errors from server faults should be narrowed:

**Before:**

```php
try {
    $request = $converter->toSymfonyRequest($workermanRequest);
} catch (\InvalidArgumentException $e) {
    // treated every InvalidArgumentException as a client error (400)
}
```

**After:**

```php
use CrazyGoat\WorkermanBundle\Exception\MalformedRequestException;

try {
    $request = $converter->toSymfonyRequest($workermanRequest);
} catch (MalformedRequestException $e) {
    // client error — return 400
} catch (\InvalidArgumentException $e) {
    // server-side misuse — return 500
}
```

`HttpRequestHandler` now maps `MalformedRequestException` (and
`FileUploadValidationException`) to **400** and wraps the whole lifecycle
in a try/catch that converts any other `Throwable` to **500** instead of
killing the worker — see #577.

---

## Upgrading to 0.23

### Config cache now refuses world-writable locations and is created with `0600`

`ConfigLoader::loadFromCache()` refuses a cache file whose containing
directory or file is world-writable, and `warmUp()` creates the file
under `umask(0077)` so it is always `0600` regardless of the surrounding
umask (#323). Deployments that warm the cache under a permissive umask
(e.g. `0000`) or on a world-writable directory now fail at boot.

**Migration:** ensure the cache directory is not world-writable and
warm the cache with a restrictive umask. Documented in
[docs/security.md#config-cache-file-protection](docs/security.md#config-cache-file-protection).

### `Request::withHeader()` deprecated

`Request::withHeader()` is deprecated in favour of `Request::setHeader()`.
`withHeader()` now emits a runtime deprecation, mutates in place (despite
the PSR-7-like name), and will be removed in 1.0 (#364).

```php
// Before
$request->withHeader('X-Custom', 'value');
// After
$request->setHeader('X-Custom', 'value');
```

---

## Upgrading to 0.22

### `StaticFilesMiddleware::$followSymlinks` now defaults to `false`

Following symlinks under the static root is now opt-in (service
argument `$followSymlinks: false` by default, #292). Deployments that
serve files through symlinks (e.g. `public/assets → shared/assets`) now
get **404** for those paths.

**Migration:** set the service argument explicitly if you rely on
symlinks:

```yaml
services:
    CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware:
        arguments:
            $followSymlinks: true
```

There is no YAML equivalent: the deprecated
`workerman.servers[].static_files` node only exposes
`allowed_extensions`; `follow_symlinks` is a `StaticFilesMiddleware`
constructor argument.

`ServerWorker` also validates that `local_cert`/`local_pk` are regular
files, not symlinks (#286), and `connection_timeout`/`keepalive_timeout`/
`body_size_cap` were added for slowloris protection (#279) — no migration
required for those.

---

## Upgrading to 0.21

### Runtime directory now created with `0700`

Runtime subdirectories (`var/run/`, status files) are created with
`0700` instead of inheriting the process umask (#270, #274). On
multi-user hosts other users can no longer read PID/status files — if
you relied on group-readable runtime files, adjust your deployment to
read them as the runtime user.

No other mandatory migration. PHAR stub validation now rejects invalid
`kernel_class` and alias characters that could alter generated code
(#259, #263).

---

## Upgrading to 0.20

No mandatory migration.

Notable changes that require no config update:

- `StaticFilesMiddleware` gains extension denylist/allowlist filtering
  (#235), `If-Modified-Since`/`If-None-Match` and LRU cache (#254).
- `SfxDownloader` gains zip-slip and cross-scheme redirect guards
  (#252, #433); `build.sfx.sha256` / `allow_insecure` options documented
  (#267).
- `SchedulerWorker` PID handling now uses exclusive `flock` with strict
  permissions (#240).

---

## Upgrading to 0.19

No mandatory migration.

Notable changes:

- `RequestConverter` now validates URI and HTTP method before propagation
  to Symfony and uses strict cookie parsing to prevent smuggling
  (#217, #220). Crafted requests previously forwarded now get **400**.
- `trusted_hosts` configuration added for Host-header enforcement
  (non-matching hosts return 400 before kernel boot, #213) — opt-in, no
  default behaviour change.
- Path handling in `StaticFilesMiddleware` hardened against traversal
  (#226).

---

## Upgrading to 0.18

No mandatory migration.

New in this release:

- PHAR and standalone binary packaging (`workerman:build:phar`,
  `workerman:build:bin`) with dynamic stub, `build` configuration
  section, `--kernel-class` CLI option and `resources/phar-stub.tpl`
  template (#191). File monitor is automatically disabled in PHAR mode.
- `Runner` source path is now configurable instead of hardcoded to
  `tests/App` (#130).

---

## Upgrading to 0.17

### `Utils::reboot()` deprecated in favour of `Utils::reload()`

`Utils::reboot()` is deprecated and will be removed in a future release. Use `Utils::reload()` instead.

**Before:**

```php
use CrazyGoat\WorkermanBundle\Utils;

Utils::reboot();
Utils::reboot(rebootAllWorkers: true);
```

**After:**

```php
use CrazyGoat\WorkermanBundle\Utils;

Utils::reload();
Utils::reload(reloadAllWorkers: true);
```

### `HttpRequestHandler` constructor signature changed

`HttpRequestHandler` now accepts `SymfonyController $controller` via constructor injection and no longer requires `KernelInterface` and `ResponseConverter`. If you instantiate this class directly (not recommended), update your call.

**Before:**

```php
$handler = new HttpRequestHandler($kernel, $rebootStrategy, $responseConverter);
```

**After:**

```php
$handler = new HttpRequestHandler($controller, $rebootStrategy);
```

### Removed `StreamResponseInterface` and `streamContent()`

The `StreamResponseInterface` and `streamContent()` method on `StreamedBinaryFileResponse` have been removed. `BinaryFileResponseStrategy` handles streaming via `withFile()`.

**Migration:** Remove any references to `StreamResponseInterface` or `streamContent()` in your code.

---

## Upgrading to 0.16

### `X-Forwarded-Proto` no longer trusted by default

`RequestConverter` no longer trusts the `X-Forwarded-Proto` header unconditionally. HTTPS is detected only from the actual SSL transport layer. If you are behind a reverse proxy, configure Symfony's trusted proxies:

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: '127.0.0.1,REMOTE_ADDR'
    trusted_headers: ['x-forwarded-proto', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-port']
```

---

## Upgrading to 0.15

### Config cache format changed

The config cache file format changed from numeric indices to string keys using `ConfigSection` enum values.

**Old format:**

```php
[0 => [...], 1 => [...], 2 => [...]]
```

**New format:**

```php
['workerman' => [...], 'process' => [...], 'scheduler' => [...]]
```

**Migration:** Clear the cache after upgrading:

```bash
rm -rf var/cache/*
```

---

## Upgrading to 0.14

### `Request::withHeader()` deprecated

`Request::withHeader()` is deprecated in favour of `setHeader()`. `withHeader()` is kept as an alias for backward compatibility but will be removed in a future release.

**Before:**

```php
$request->withHeader('X-Custom', 'value');
```

**After:**

```php
$request->setHeader('X-Custom', 'value');
```

### `ResponseConverterStrategyInterface::convert()` now requires `TcpConnection`

The `convert()` method on `ResponseConverterStrategyInterface` now requires a third `TcpConnection $connection` parameter.

**Before:**

```php
public function convert(SymfonyResponse $response, array $headers): WorkermanResponse;
```

**After:**

```php
public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection): WorkermanResponse;
```

**Migration:** Add `TcpConnection $connection` as the third parameter to your custom strategy's `convert()` method.

### `RequestConverter::toSymfonyRequest()` returns empty content for multipart

`toSymfonyRequest()` now returns an empty string for `getContent()` on `multipart/form-data` requests, matching PHP-FPM behaviour where `php://input` is not available for multipart. Files remain accessible via `$request->files`.

**Migration:** If your code reads the raw multipart body via `getContent()`, adapt it to use `$request->files` or `$request->request` instead.

See: `src/DTO/RequestConverter.php`

---

## Upgrading to 0.13

### Priority-based strategy ordering enforced

Response converter strategies are now sorted by priority tag value (descending) in the compiler pass. Previously the order depended on service registration order.

**Migration:** If you have custom `ResponseConverterStrategyInterface` implementations, ensure they are tagged with the correct priority:

```yaml
services:
    App\Response\MyCustomStrategy:
        tags:
            - { name: 'workerman.response_converter.strategy', priority: 50 }
```

Strategies are registered with the `workerman.response_converter.strategy` tag. The built-in strategies use the following priorities:

| Strategy                   | Priority |
|----------------------------|----------|
| `BinaryFileResponseStrategy` | 100      |
| `StreamedResponseStrategy`   | 50       |
| `DefaultResponseStrategy`    | 0        |

---

## Upgrading to 0.12

### Exception namespace migration

Root-level exception classes have been moved into the `Exception` namespace. Update your import statements:

**Before:**

```php
use CrazyGoat\WorkermanBundle\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\ServerStopFailedException;
```

**After:**

```php
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
```

### Generic PHP exceptions replaced with typed exceptions

9 `\InvalidArgumentException` throw sites and 2 `\RuntimeException` throw sites have been replaced with domain-specific exceptions. Code catching generic exceptions (`\RuntimeException`, `\InvalidArgumentException`) continues to work, but there are two noteworthy changes:

| Before                          | After                                                |
|---------------------------------|------------------------------------------------------|
| `\InvalidArgumentException`     | `FileUploadValidationException`, `ConfigurationValidationException`, `InvalidTriggerException`, `InvalidCronExpressionException`, `InvalidMiddlewareException`, `StaticFileMiddlewareException` |
| `\RuntimeException`             | `KernelCreationException`, `InvalidCacheDirectoryException`  |
| `\LogicException`               | `InvalidCronExpressionException` (extends `\InvalidArgumentException`) |

**Exception hierarchy:**

```text
WorkermanExceptionInterface
├── WorkermanException (extends \RuntimeException)
│   ├── ServerException
│   │   ├── ServerAlreadyRunningException
│   │   ├── ServerNotRunningException
│   │   └── ServerStopFailedException
│   └── KernelException
│       ├── KernelCreationException
│       └── InvalidCacheDirectoryException
├── ValidationException (extends \InvalidArgumentException)
│   ├── FileUploadValidationException
│   └── ConfigurationValidationException
├── SchedulerException (extends \InvalidArgumentException)
│   ├── InvalidTriggerException
│   └── InvalidCronExpressionException
├── MiddlewareException (extends \InvalidArgumentException)
│   ├── InvalidMiddlewareException
│   └── StaticFileMiddlewareException
└── NoResponseStrategyException (extends \LogicException)
```

**Migration:** If you were catching `\LogicException` from cron expression instantiation, update to catch `InvalidCronExpressionException`:

```php
// Before
try {
    new CronExpressionTrigger('* * * * *');
} catch (\LogicException $e) {
    // Handle missing package
}

// After
try {
    new CronExpressionTrigger('* * * * *');
} catch (InvalidCronExpressionException $e) {
    // Handle missing package or invalid expression
}
```
