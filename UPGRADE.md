# Upgrade Guide

This document lists breaking changes between releases and describes how to migrate from one version to another.

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
