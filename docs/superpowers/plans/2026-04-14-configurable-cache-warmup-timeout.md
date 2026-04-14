# Configurable Cache Warmup Timeout — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the cache warmup timeout configurable via env var `WORKERMAN_CACHE_WARMUP_TIMEOUT` with a default of 30 seconds, and expose it as a bundle config option for documentation/discovery purposes.

**Architecture:** `Runner` is NOT a container service — it's created by `Runtime::getRunner()` before the DI container is available. Bundle config cannot reach `Runner` at runtime. The only reliable path is `$_SERVER` env var (same pattern as `APP_CACHE_DIR`). Bundle config `cache_warmup_timeout` is added for documentation and to set the container parameter (usable by other services), but `Runner` reads exclusively from `$_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT']` with a default fallback.

**Tech Stack:** PHP 8.2+, Symfony 7.1+, pcntl/posix extensions

---

### Task 1: Add `cache_warmup_timeout` to bundle configuration

**Files:**
- Modify: `src/config/configuration.php:22-25` (after `stop_timeout` node)
- Modify: `src/config/services.php:26-27` (add parameter)

- [ ] **Step 1: Add the config node in `src/config/configuration.php`**

After the `stop_timeout` node (line 25), add:

```php
            ->integerNode('cache_warmup_timeout')
                ->info('Max seconds to wait for cache warmup in forked process. Can be overridden with WORKERMAN_CACHE_WARMUP_TIMEOUT env var.')
                ->defaultValue(30)
                ->end()
```

- [ ] **Step 2: Add the container parameter in `src/config/services.php`**

After line 27 (`$container->setParameter('workerman.response_chunk_size', ...)`), add:

```php
    $container
        ->setParameter('workerman.cache_warmup_timeout', $config['cache_warmup_timeout'])
    ;
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/config/configuration.php && php -l src/config/services.php`  
Expected: No syntax errors

- [ ] **Step 4: Commit**

```bash
git add src/config/configuration.php src/config/services.php
git commit -m "feat: add cache_warmup_timeout config node and container parameter"
```

---

### Task 2: Update `Runner` to use configurable timeout

**Files:**
- Modify: `src/Runner.php`

- [ ] **Step 1: Remove the `CACHE_WARMUP_TIMEOUT` constant and add `getCacheWarmupTimeout()` method**

The `Runner` class is `readonly`, so properties cannot be reassigned in the constructor body. Use the same pattern as `getCacheDir()` — a private method that reads `$_SERVER` with fallback.

Replace the constant and add the method. In `src/Runner.php`, remove:

```php
    private const CACHE_WARMUP_TIMEOUT = 30;
```

And add a new private method after `getCacheDir()`:

```php
    private function getCacheWarmupTimeout(): int
    {
        if (isset($_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'])) {
            return (int) $_SERVER['WORKERMAN_CACHE_WARMUP_TIMEOUT'];
        }

        return 30;
    }
```

- [ ] **Step 2: Update `run()` to use `getCacheWarmupTimeout()`**

In `src/Runner.php`, in the `run()` method, replace:

```php
            $timeout = self::CACHE_WARMUP_TIMEOUT;
```

With:

```php
            $timeout = $this->getCacheWarmupTimeout();
```

- [ ] **Step 3: Verify syntax**

Run: `php -l src/Runner.php`  
Expected: No syntax errors

- [ ] **Step 4: Commit**

```bash
git add src/Runner.php
git commit -m "feat: make cache warmup timeout configurable via WORKERMAN_CACHE_WARMUP_TIMEOUT env var"
```

---

### Task 3: Update tests

**Files:**
- Modify: `tests/RunnerTest.php`

- [ ] **Step 1: Update structural test assertions**

In `tests/RunnerTest.php`, in `testRunnerUsesCorrectForkErrorHandling()`, replace the assertion for the constant:

```php
        $this->assertStringContainsString(
            'CACHE_WARMUP_TIMEOUT',
            $content,
            'Must have CACHE_WARMUP_TIMEOUT constant',
        );
```

With:

```php
        $this->assertStringContainsString(
            'WORKERMAN_CACHE_WARMUP_TIMEOUT',
            $content,
            'Must support WORKERMAN_CACHE_WARMUP_TIMEOUT env var override',
        );

        $this->assertStringContainsString(
            'getCacheWarmupTimeout',
            $content,
            'Must have getCacheWarmupTimeout method',
        );
```

- [ ] **Step 2: Add test for default timeout value**

Add a new test method in `tests/RunnerTest.php`:

```php
    public function testCacheWarmupTimeoutDefaultsTo30(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Runner.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);
        $this->assertStringContainsString(
            'return 30;',
            $content,
            'Default cache warmup timeout must be 30 seconds',
        );
    }
```

- [ ] **Step 3: Run Runner tests**

Run: `vendor/bin/phpunit tests/RunnerTest.php`  
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add tests/RunnerTest.php
git commit -m "test: update Runner tests for configurable cache warmup timeout"
```

---

### Task 4: Run full test suite and verify

- [ ] **Step 1: Run all tests**

Run: `vendor/bin/phpunit`  
Expected: All tests pass

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`  
Expected: No errors

- [ ] **Step 3: Run code style checks**

Run: `vendor/bin/php-cs-fixer fix --dry-run`  
Expected: No issues

- [ ] **Step 4: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: address lint/static analysis issues"
```