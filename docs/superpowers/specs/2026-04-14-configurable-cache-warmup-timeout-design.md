# Configurable Cache Warmup Timeout

## Context

PR #141 introduced a `CACHE_WARMUP_TIMEOUT` constant (30 seconds) in `Runner.php` for the forked cache warmup process timeout. Currently this value is hardcoded and cannot be changed without modifying the source. Issue #142 tracks this.

## Requirements

- Make the cache warmup timeout configurable via bundle config
- Allow env var override (`WORKERMAN_CACHE_WARMUP_TIMEOUT`) for quick operational changes
- Priority: env var > bundle config > default (30)
- Maintain backward compatibility — default of 30 must work without any configuration

## Design

### 1. Bundle config

Add `cache_warmup_timeout` node in `src/config/configuration.php`:

```php
->integerNode('cache_warmup_timeout')->defaultValue(30)->end()
```

Users configure in `workerman.yaml`:
```yaml
workerman:
    cache_warmup_timeout: 60
```

### 2. Data flow

```
bundle config → services.php → Runtime options → Resolver → Runtime::getRunner() → Runner constructor
```

- `services.php` passes `cache_warmup_timeout` from resolved config into Runtime options
- `Runtime::getRunner()` reads the option and passes it to `new Runner($kernelFactory, $timeout)`
- `KernelFactory` is unchanged — timeout does not flow through it

### 3. Runner constructor

```php
public function __construct(
    private KernelFactory $kernelFactory,
    private int $cacheWarmupTimeout = 30,
) {
    if (isset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'])) {
        $this->cacheWarmupTimeout = (int) $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'];
    }
}
```

Priority: env var > bundle config (injected via constructor) > default 30.

Remove the `CACHE_WARMUP_TIMEOUT` constant. Replace `$timeout = self::CACHE_WARMUP_TIMEOUT` with `$timeout = $this->cacheWarmupTimeout`.

### 4. Files to change

| File | Change |
|------|--------|
| `src/config/configuration.php` | Add `cache_warmup_timeout` integer node (default 30) |
| `src/config/services.php` | Pass `cache_warmup_timeout` from config to Runtime options |
| `src/Runtime.php` | Pass timeout to `new Runner()` in `getRunner()` |
| `src/Runner.php` | Remove constant, add constructor param, add env var fallback |
| `tests/RunnerTest.php` | Update structural test, add tests for constructor and env var |

### 5. Tests

- Update `testRunnerUsesCorrectForkErrorHandling` — verify `cacheWarmupTimeout` property exists instead of `CACHE_WARMUP_TIMEOUT` constant
- Add test for env var override (`WORKERMAN_CACHE_WARMUP_TIMEOUT` takes precedence)
- Add test for constructor injection (default value = 30)