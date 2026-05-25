# E2E Test Application

This directory contains a minimal Symfony application used as a testbed for
end-to-end testing of the WorkermanBundle.

## Purpose

- Provides a complete Symfony kernel with WorkermanBundle configured for testing
- Used by E2E tests in `tests/` (e.g., `build-phar-e2e-test.php`) that require a
  real Symfony application to boot and interact with Workerman servers
- Serves as a reference for the minimal configuration needed to use WorkermanBundle

## Structure

```
e2e/
├── composer.json   # Requires bundle and framework-bundle
├── console         # Symfony console entry point
└── src/
    ├── Kernel.php              # Minimal Symfony kernel with WorkermanBundle
    └── Controller/
        └── TestController.php  # Test controller for E2E assertions
```

## How to run E2E tests

Most E2E tests (PHPUnit test cases) are part of the main test suite and run
automatically via `composer test`. The PHAR build test (`build-phar-e2e-test.php`)
is a standalone script and must be invoked separately (see below).

No separate setup is required — the application is resolved via Composer's
path repository.

### Run the full test suite

```bash
composer test
```

### Run only E2E tests

```bash
vendor/bin/phpunit tests --filter=E2E
```

### Run a specific standalone E2E test

The PHAR build test is a standalone script (not a PHPUnit test case):

```bash
php -d phar.readonly=0 tests/build-phar-e2e-test.php
```

Or via Composer:

```bash
composer test:build:phar
```

## Prerequisites

- PHP 8.2+ with `phar.readonly=0` for PHAR-related E2E tests
- `pcntl` and `posix` extensions (for server lifecycle tests)

## Note

The test server binds to `127.0.0.1:8887`. Ensure this port is free before
running E2E tests that start a Workerman server.
