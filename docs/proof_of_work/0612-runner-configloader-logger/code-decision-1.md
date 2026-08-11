# Code Decision 1 — Source the logger for Runner's ConfigLoader without booting the kernel

Issue: #612 — `Runner::createConfigLoader()` builds a `ConfigLoader` without a
logger, so the config-cache fail-open warning (from the #586 permission guard)
only reaches stderr via `trigger_error(\E_USER_WARNING)` on the production
`serve` path instead of the application logs.

## What I chose

Add a nullable `?LoggerInterface $logger = null` third constructor parameter to
`Runner` (a readonly class), store it as a `private readonly` promoted property,
and pass it as the 4th argument (`logger:`) in `createConfigLoader()`:

```php
public function __construct(
    private KernelFactory $kernelFactory,
    private int $cacheWarmupTimeout = CacheWarmupTimeoutConfig::DEFAULT,
    private ?LoggerInterface $logger = null,
) { ... }
```

`ServerManager::start()` / `ServerManager::restart()` now forward the logger it
already receives via DI constructor injection (`private LoggerInterface $logger
= new NullLogger()`, autowired) into the `Runner`:

```php
return (new Runner($this->createKernelFactory(), $this->resolveCacheWarmupTimeout(), $this->logger))->run();
```

The `Runtime` path (`index.php start` via Symfony runtime) is intentionally left
unchanged: it has no access to the logger service without eagerly booting the
kernel, which `Runner` is designed to avoid.

## Alternatives considered and rejected

**1. Obtain the logger inside `createConfigLoader()` from the kernelFactory.**
This requires `$this->kernelFactory->createKernel()->getContainer()` in the main
process before `run()`. This would force an eager kernel boot, exactly
what `warmUpCache()` / `Runner` exist to avoid (the kernel is only ever booted in
the forked warm-up child). Rejected on that basis, plus the container lookup is
tied to the legacy `FrameworkExtension` container layout and is brittle across
Kernel variants (microkernel, PHAR).

**2. Boot the kernel lazily on first `createConfigLoader()` call.** Still boots
the kernel in the main (launcher) process on every `start`, defeating the
fork-based warm-up design and adding the full container construction cost to
start-up latency. Rejected for the same reason as (1).

**3. Pull the logger out of a globally-resolved service (e.g. container access)
in `Runtime`.** The `Runtime::getRunner()` receives only a `KernelFactory` whose
`app` closure constructs the kernel; there is no container yet. Any access would
again require an eager boot. Rejected.

**4. Inject a `NullLogger` into the runtime-built Runner so the warning "works"
silently.** This would swallow the fail-open warning entirely — strictly worse
than today's `trigger_error`, which at least reaches stderr. Rejected.

## Why what I chose satisfies the issue without the eager-boot trade-off

- `Runner` stays decoupled from the container; the decision of *where the logger
  comes from* is pushed to the call site that already has one in hand
  (`ServerManager`, which is a DI service autowiring `LoggerInterface`).
- The documented production serve path (`bin/console workerman:server start`) is
  served by `ServerManager`, so the fix reaches the logs exactly there.
- Existing call sites are untouched: `new Runner($kernelFactory)` and
  `new Runner($kernelFactory, int)` remain valid because the new param has a
  default of `null`.
- The `trigger_error(\E_USER_WARNING)` fallback in `ConfigLoader` is preserved
  for standalone / non-DI construction paths (default `$logger = null`).

## What I was unsure about

- **Runtime path coverage.** The Symfony-runtime `start` path (`APP_RUNTIME`
  `index.php start`) still cannot log the fail-open warning because there is no
  logger available without an eager kernel boot. I considered this an acceptable
  residual: it is the documented alternative entry point, `workerman:server` is
  the primary one, and _silently swallowing_ the warning is never acceptable, so
  the safest behaviour (stderr fallback) was retained there. If the maintainers
  want the runtime path too, the correct fix is passing a pre-wired anonymous
  service factory (not a plain logger instance) — out of scope here.

## Verification

- `vendor/bin/phpunit tests/ConfigLoaderTest.php tests/RunnerTest.php tests/RuntimeTest.php tests/ServerManagerTest.php`
- `composer lint` (php-cs-fixer --dry-run, phpstan level 8, rector --dry-run, kb-lint)
