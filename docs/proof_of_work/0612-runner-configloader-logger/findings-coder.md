# Findings — coder round (issue #612)

## In-scope obstacles

### 1. The Symfony-runtime path (`index.php start`) cannot log the fail-open warning
`src/Runtime.php:16` builds `new Runner($application, CacheWarmupTimeoutConfig::resolve())`
before any kernel/container exists. `Runtime` receives only a `KernelFactory`
whose `app` closure creates the kernel lazily. There is genuinely no logger
available in the main process of this path without eagerly booting the kernel
(`KernelFactory::createKernel()`), which `Runner` and `warmUpCache()` explicitly
avoid ("the main process never boots the kernel"). So I left `Runtime` unchanged:
the runtime entry point retains the `trigger_error(\E_USER_WARNING)` fallback.
The fix covers the primary documented `bin/console workerman:server start` path
via `ServerManager`. **Suggested follow-up (out of scope):** if the runtime
`start` path must log too, inject a `container.service_locator`/closure-based
logger producer rather than a logger instance, so `Runner` can lazily resolve it
inside `createConfigLoader()` without a boot.

### 2. ConfigLoader's fail-open branch mixes "unreadable metadata" with "hard error"
`src/ConfigLoader.php:147-154` (`validateCacheFilePermissions`) turns *any* false
metadata (including a genuinely missing cache path) into a **warning** and
proceeds (`loadFromCache()` then `require`s it — actually `is_file($cachePath)`
was already checked, so in production a missing file never reaches the
permission guard). The test relies on the *pure* decision function
`checkCacheFilePermissions(... false ...)` returning `warn`. This is by design
from #586 (fail-open on filesystems that don't report permissions), but means a
deleted cache file mid-flight would log a misleading "Cannot verify permissions"
warning rather than a "cache missing" notice. Low severity.

## Out-of-scope observations

### 3. `Runtime` has no DI-consistent logger story at all
`src/Runtime.php` constructs `Runner`/`ConfigLoader` outside the container. If
issue #612 is later extended to "every constructible path must reach the
logger", `Runtime` (and `Resolver`) need a way to receive the `logger` service
without booting. Worth recording as a known limitation in docs (see KB proposal).

### 4. `ServerManager` logger default is `NullLogger` — `start()`/`restart()` silently drop warnings if unconfigured
`src/ServerManager.php:38-50` defaults `private LoggerInterface $logger = new NullLogger()`.
In a bare (non-Symfony/DI) construction the Runner now forwards a no-op logger,
so the fail-open warning is dropped where it used to reach stderr. Acceptable in
practice (in Symfony the service is always autowired), but the `Runtime` path
keeps the stderr fallback precisely so there is *always* a signal.

### 5. `logStartupWarning()` writes to the log file directly
`src/Runner.php` (`logStartupWarning`, grpc warning) writes to `Worker::$logFile`
via `file_put_contents` and ignores the injected logger. Not changed (separate
feature), but now that `Runner` carries a `LoggerInterface` it is tempting to
unify these. Resisted to keep the change minimal — the grpc message is explicitly
tied to the Workerman log file semantics (see FAQ-008).

### 6. No test asserts the injected logger reaches the ConfigLoader through `ServerManager`-constructed Runner
`ServerManager::start()`/`restart()` fork a real Workerman master, so they can't
be unit-tested cheaply; I added the Runner-level test which is the unit-under-
test boundary closest to the wiring. A `ServicesAutowiringTest` style check that
`ServerManager` gets the `logger` service would be nice but is already implied by
`setAutowired(true)`.

## KB candidate entries

See the final report for titles/tags/triggers/paragraphs.
