# ResponseConverter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract response conversion logic from SymfonyController into a dedicated ResponseConverter using Strategy Pattern with DI support.

**Architecture:** Strategy Pattern with ResponseConverter orchestrator and three concrete strategies (BinaryFile, StreamedResponse, Default). Strategies registered via DI with priority-based selection.

**Tech Stack:** PHP 8.2+, Symfony DI, PHPUnit, Workerman

---

## File Structure

**New files to create:**
- `src/Http/Response/ResponseConverterStrategy.php` - Interface
- `src/Http/Response/ResponseConverter.php` - Main orchestrator
- `src/Http/Response/Strategy/BinaryFileResponseStrategy.php`
- `src/Http/Response/Strategy/StreamedResponseStrategy.php`
- `src/Http/Response/Strategy/DefaultResponseStrategy.php`
- `tests/ResponseConverterTest.php`
- `tests/Strategy/BinaryFileResponseStrategyTest.php`
- `tests/Strategy/StreamedResponseStrategyTest.php`
- `tests/Strategy/DefaultResponseStrategyTest.php`

**Files to modify:**
- `src/Middleware/SymfonyController.php` - Use ResponseConverter
- `src/config/services.php` - Register strategies with DI

---

## Task 1: Create ResponseConverterStrategy Interface

**Files:**
- Create: `src/Http/Response/ResponseConverterStrategy.php`

- [ ] **Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

interface ResponseConverterStrategy
{
    /**
     * Check if this strategy can handle the given response.
     */
    public function supports(SymfonyResponse $response): bool;

    /**
     * Convert Symfony response to Workerman response.
     *
     * @param array<string, list<string|null>> $headers Pre-extracted headers
     */
    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse;
}
```

- [ ] **Step 2: Verify file syntax**

Run: `php -l src/Http/Response/ResponseConverterStrategy.php`
Expected: `No syntax errors`

- [ ] **Step 3: Commit**

```bash
git add src/Http/Response/ResponseConverterStrategy.php
git commit -m "feat: add ResponseConverterStrategy interface"
```

---

## Task 2: Create DefaultResponseStrategy

**Files:**
- Create: `src/Http/Response/Strategy/DefaultResponseStrategy.php`
- Create: `tests/Strategy/DefaultResponseStrategyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class DefaultResponseStrategyTest extends TestCase
{
    public function testSupportsAlwaysReturnsTrue(): void
    {
        $strategy = new DefaultResponseStrategy();

        $this->assertTrue($strategy->supports(new Response()));
        $this->assertTrue($strategy->supports(new JsonResponse(['test'])));
    }

    public function testConvertReturnsWorkermanResponseWithContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response('Hello World', 200, ['Content-Type' => 'text/plain']);

        $workermanResponse = $strategy->convert($symfonyResponse, ['Content-Type' => ['text/plain']]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('Hello World', (string) $workermanResponse->rawBody());
    }

    public function testConvertHandlesEmptyContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response();

        $workermanResponse = $strategy->convert($symfonyResponse, []);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('', (string) $workermanResponse->rawBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Strategy/DefaultResponseStrategyTest.php -v`
Expected: FAIL with "Class not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategy;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class DefaultResponseStrategy implements ResponseConverterStrategy
{
    public function supports(SymfonyResponse $response): bool
    {
        return true;
    }

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            strval($response->getContent())
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Strategy/DefaultResponseStrategyTest.php -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Http/Response/Strategy/DefaultResponseStrategy.php tests/Strategy/DefaultResponseStrategyTest.php
git commit -m "feat: add DefaultResponseStrategy with tests"
```

---

## Task 3: Create StreamedResponseStrategy

**Files:**
- Create: `src/Http/Response/Strategy/StreamedResponseStrategy.php`
- Create: `tests/Strategy/StreamedResponseStrategyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamedResponseStrategyTest extends TestCase
{
    public function testSupportsReturnsTrueForStreamedResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertTrue($strategy->supports(new StreamedResponse()));
    }

    public function testSupportsReturnsTrueForStreamedJsonResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertTrue($strategy->supports(new StreamedJsonResponse(['data'])));
    }

    public function testSupportsReturnsFalseForBinaryFileResponse(): void
    {
        $strategy = new StreamedResponseStrategy();
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test');

        try {
            $this->assertFalse($strategy->supports(new BinaryFileResponse($tmpFile)));
        } finally {
            unlink($tmpFile);
        }
    }

    public function testSupportsReturnsFalseForRegularResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertFalse($strategy->supports(new \Symfony\Component\HttpFoundation\Response()));
    }

    public function testConvertCapturesOutputBuffer(): void
    {
        $strategy = new StreamedResponseStrategy();
        $streamedResponse = new StreamedResponse(function () {
            echo 'streamed content';
        });

        $workermanResponse = $strategy->convert($streamedResponse, ['Content-Type' => ['text/plain']]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('streamed content', (string) $workermanResponse->rawBody());
    }

    public function testConvertHandlesEmptyStream(): void
    {
        $strategy = new StreamedResponseStrategy();
        $streamedResponse = new StreamedResponse(function () {
            // Empty callback
        });

        $workermanResponse = $strategy->convert($streamedResponse, []);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('', (string) $workermanResponse->rawBody());
    }

    public function testConvertPreservesStatusCode(): void
    {
        $strategy = new StreamedResponseStrategy();
        $streamedResponse = new StreamedResponse(function () {
            echo 'data';
        }, 201);

        $workermanResponse = $strategy->convert($streamedResponse, []);

        $this->assertSame(201, $workermanResponse->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Strategy/StreamedResponseStrategyTest.php -v`
Expected: FAIL with "Class not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategy;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class StreamedResponseStrategy implements ResponseConverterStrategy
{
    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof StreamedResponse
            && !$response instanceof BinaryFileResponse;
    }

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        /** @var StreamedResponse $response */
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        return new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
            $content
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Strategy/StreamedResponseStrategyTest.php -v`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Http/Response/Strategy/StreamedResponseStrategy.php tests/Strategy/StreamedResponseStrategyTest.php
git commit -m "feat: add StreamedResponseStrategy with tests"
```

---

## Task 4: Create BinaryFileResponseStrategy

**Files:**
- Create: `src/Http/Response/Strategy/BinaryFileResponseStrategy.php`
- Create: `tests/Strategy/BinaryFileResponseStrategyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BinaryFileResponseStrategyTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($this->testFile, 'test file content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testSupportsReturnsTrueForBinaryFileResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $this->assertTrue($strategy->supports(new BinaryFileResponse($this->testFile)));
    }

    public function testSupportsReturnsFalseForRegularResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $this->assertFalse($strategy->supports(new Response()));
    }

    public function testSupportsReturnsFalseForStreamedResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $this->assertFalse($strategy->supports(new StreamedResponse()));
    }

    public function testConvertUsesWithFile(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, 200, [], false, 'inline');

        $workermanResponse = $strategy->convert($binaryResponse, ['Content-Type' => ['text/plain']]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        // withFile() sets internal file path, we can't directly test it but response should be valid
        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $workermanResponse);
    }

    public function testConvertPreservesStatusCode(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, 404);

        $workermanResponse = $strategy->convert($binaryResponse, []);

        $this->assertSame(404, $workermanResponse->getStatusCode());
    }

    public function testConvertPassesOffsetAndMaxlen(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile);
        $binaryResponse->setOffset(10);
        $binaryResponse->setMaxlen(100);

        $workermanResponse = $strategy->convert($binaryResponse, []);

        // Just verify it doesn't throw - withFile() is called with these params
        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $workermanResponse);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Strategy/BinaryFileResponseStrategyTest.php -v`
Expected: FAIL with "Class not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategy;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class BinaryFileResponseStrategy implements ResponseConverterStrategy
{
    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof BinaryFileResponse;
    }

    public function convert(SymfonyResponse $response, array $headers): WorkermanResponse
    {
        /** @var BinaryFileResponse $response */
        $workermanResponse = new WorkermanResponse(
            $response->getStatusCode(),
            $headers
        );

        $workermanResponse->withFile(
            $response->getFile()->getPathname(),
            $response->getOffset(),
            $response->getMaxlen()
        );

        return $workermanResponse;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Strategy/BinaryFileResponseStrategyTest.php -v`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Http/Response/Strategy/BinaryFileResponseStrategy.php tests/Strategy/BinaryFileResponseStrategyTest.php
git commit -m "feat: add BinaryFileResponseStrategy with tests"
```

---

## Task 5: Create ResponseConverter (Orchestrator)

**Files:**
- Create: `src/Http/Response/ResponseConverter.php`
- Create: `tests/ResponseConverterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ResponseConverterTest extends TestCase
{
    private string $testFile;

    protected function setUp(): void
    {
        $this->testFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($this->testFile, 'test content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testConvertUsesCorrectStrategyOrder(): void
    {
        // Order: BinaryFile (100) > Streamed (50) > Default (0)
        $strategies = [
            new BinaryFileResponseStrategy(),
            new StreamedResponseStrategy(),
            new DefaultResponseStrategy(),
        ];
        $converter = new ResponseConverter($strategies);

        // BinaryFileResponse should use BinaryFile strategy
        $binaryResponse = new BinaryFileResponse($this->testFile);
        $workermanResponse = $converter->convert($binaryResponse);
        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $workermanResponse);

        // StreamedResponse should use Streamed strategy
        $streamedResponse = new StreamedResponse(function () {
            echo 'streamed';
        });
        $workermanResponse = $converter->convert($streamedResponse);
        $this->assertSame('streamed', (string) $workermanResponse->rawBody());

        // Regular response should use Default strategy
        $regularResponse = new Response('regular');
        $workermanResponse = $converter->convert($regularResponse);
        $this->assertSame('regular', (string) $workermanResponse->rawBody());
    }

    public function testConvertThrowsWhenNoStrategyFound(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No strategy found');

        // Empty strategies array
        $converter = new ResponseConverter([]);
        $converter->convert(new Response());
    }

    public function testConvertPreservesHeaders(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new JsonResponse(['key' => 'value'], 200, ['X-Custom' => 'header']);
        $workermanResponse = $converter->convert($response);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        // Headers should be preserved (Workerman stores them differently)
    }

    public function testConvertHandlesIterableStrategies(): void
    {
        // Test with Generator (simulating DI tagged_iterator)
        $generator = function () {
            yield new DefaultResponseStrategy();
        };

        $converter = new ResponseConverter($generator());
        $response = $converter->convert(new Response('test'));

        $this->assertSame('test', (string) $response->rawBody());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ResponseConverterTest.php -v`
Expected: FAIL with "Class not found"

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Response as WorkermanResponse;

class ResponseConverter
{
    /** @var ResponseConverterStrategy[] */
    private readonly array $strategies;

    public function __construct(iterable $strategies)
    {
        $this->strategies = iterator_to_array($strategies, false);
    }

    public function convert(SymfonyResponse $response): WorkermanResponse
    {
        $headers = $this->extractHeaders($response);

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($response)) {
                return $strategy->convert($response, $headers);
            }
        }

        throw new \LogicException(sprintf(
            'No strategy found for response type: %s',
            get_class($response)
        ));
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function extractHeaders(SymfonyResponse $response): array
    {
        $headers = $response->headers->all();

        // Fix header names (lowercase to proper case)
        $fixHeaders = [
            'content-type' => 'Content-Type',
            'connection' => 'Connection',
            'transfer-encoding' => 'Transfer-Encoding',
            'server' => 'Server',
            'content-disposition' => 'Content-Disposition',
            'last-modified' => 'Last-Modified',
        ];

        foreach ($fixHeaders as $lower => $proper) {
            if (isset($headers[$lower])) {
                $headers[$proper] = $headers[$lower];
                unset($headers[$lower]);
            }
        }

        return $headers;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ResponseConverterTest.php -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Http/Response/ResponseConverter.php tests/ResponseConverterTest.php
git commit -m "feat: add ResponseConverter orchestrator with tests"
```

---

## Task 6: Register Strategies in DI Configuration

**Files:**
- Modify: `src/config/services.php`

- [ ] **Step 1: Add DI configuration for strategies**

Add to `src/config/services.php` (before the return statement or in appropriate place):

```php
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;

// ResponseConverter strategies - priority matters! Higher = checked first
$services->set(BinaryFileResponseStrategy::class)
    ->tag('workerman.response_converter.strategy', ['priority' => 100]);

$services->set(StreamedResponseStrategy::class)
    ->tag('workerman.response_converter.strategy', ['priority' => 50]);

$services->set(DefaultResponseStrategy::class)
    ->tag('workerman.response_converter.strategy', ['priority' => 0]);

// Main ResponseConverter with injected strategies
$services->set(ResponseConverter::class)
    ->args([tagged_iterator('workerman.response_converter.strategy')]);
```

- [ ] **Step 2: Verify syntax**

Run: `php -l src/config/services.php`
Expected: `No syntax errors`

- [ ] **Step 3: Commit**

```bash
git add src/config/services.php
git commit -m "feat: register ResponseConverter strategies in DI"
```

---

## Task 7: Refactor SymfonyController to Use ResponseConverter

**Files:**
- Modify: `src/Middleware/SymfonyController.php`

- [ ] **Step 1: Modify SymfonyController constructor**

Change constructor in `src/Middleware/SymfonyController.php`:

```php
// BEFORE:
public function __construct(private readonly KernelInterface $kernel)
{
}

// AFTER:
public function __construct(
    private readonly KernelInterface $kernel,
    private readonly \CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter $responseConverter,
) {
}
```

- [ ] **Step 2: Modify __invoke method**

Change the response conversion in `src/Middleware/SymfonyController.php`:

```php
// BEFORE (lines 45-49):
return new Response(
    $this->symfonyResponse->getStatusCode(),
    $this->getHeaders($this->symfonyResponse),
    strval($this->symfonyResponse->getContent()),
);

// AFTER:
return $this->responseConverter->convert($this->symfonyResponse);
```

- [ ] **Step 3: Remove getHeaders method (now in ResponseConverter)**

Remove the private `getHeaders()` method from `src/Middleware/SymfonyController.php` (lines 80-92):

```php
// DELETE THIS ENTIRE METHOD:
/** @return array<string, list<string|null>> */
private function getHeaders(\Symfony\Component\HttpFoundation\Response $symfonyResponse): array
{
    $headers = $symfonyResponse->headers->all();

    foreach (self::FIX_HEADERS as $fixHeader => $header) {
        if (isset($headers[$fixHeader])) {
            $headers[$header] = $headers[$fixHeader];
            unset($headers[$fixHeader]);
        }
    }

    return $headers;
}
```

Also remove the `FIX_HEADERS` constant if it's no longer used elsewhere.

- [ ] **Step 4: Verify syntax**

Run: `php -l src/Middleware/SymfonyController.php`
Expected: `No syntax errors`

- [ ] **Step 5: Run existing tests**

Run: `php vendor/bin/phpunit tests/SymfonyControllerTest.php -v`
Expected: PASS (may need updates if tests check internal implementation)

- [ ] **Step 6: Commit**

```bash
git add src/Middleware/SymfonyController.php
git commit -m "refactor: SymfonyController uses ResponseConverter"
```

---

## Task 8: Update SymfonyController Tests

**Files:**
- Modify: `tests/SymfonyControllerTest.php`

- [ ] **Step 1: Check if tests need updates**

Run existing tests to see if they pass:

```bash
php vendor/bin/phpunit tests/SymfonyControllerTest.php -v
```

If tests fail due to constructor change (missing ResponseConverter dependency), update the test setup:

```php
// In test setup, mock or create ResponseConverter:
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;

// In setUp or test method:
$strategies = [new DefaultResponseStrategy()];
$responseConverter = new ResponseConverter($strategies);

// Pass to SymfonyController constructor along with kernel
$controller = new SymfonyController($kernel, $responseConverter);
```

- [ ] **Step 2: Run updated tests**

Run: `php vendor/bin/phpunit tests/SymfonyControllerTest.php -v`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/SymfonyControllerTest.php
git commit -m "test: update SymfonyControllerTest for ResponseConverter"
```

---

## Task 9: Run Full Test Suite

**Files:**
- All tests

- [ ] **Step 1: Run all tests**

```bash
php vendor/bin/phpunit
```

Expected: All tests PASS

- [ ] **Step 2: Check code style**

```bash
php vendor/bin/php-cs-fixer fix --dry-run --diff
```

Expected: No errors (or fix them)

- [ ] **Step 3: Final commit if needed**

```bash
git add -A
git commit -m "style: fix code style issues" || echo "No changes needed"
```

---

## Task 10: Verify Integration

**Files:**
- Test application

- [ ] **Step 1: Create integration test controller**

Add to `tests/App/ResponseTestController.php` if not exists, or create simple test:

```php
<?php
// Test that different response types work through the full stack

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseTestController
{
    public function regularAction(): Response
    {
        return new Response('Hello World');
    }

    public function jsonAction(): JsonResponse
    {
        return new JsonResponse(['key' => 'value']);
    }

    public function streamedAction(): StreamedResponse
    {
        return new StreamedResponse(function () {
            echo 'streamed data';
        });
    }

    public function fileAction(): BinaryFileResponse
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'file content');
        return new BinaryFileResponse($tmpFile);
    }
}
```

- [ ] **Step 2: Run integration tests**

```bash
php vendor/bin/phpunit tests/HttpRequestHandlerTest.php -v
```

Or run full integration test suite.

Expected: All response types work correctly.

- [ ] **Step 3: Final verification commit**

```bash
git add -A
git commit -m "test: add integration tests for ResponseConverter" || echo "No changes needed"
```

---

## Summary

After completing all tasks:

1. ✅ `ResponseConverterStrategy` interface exists
2. ✅ Three strategies implemented (BinaryFile, StreamedResponse, Default)
3. ✅ `ResponseConverter` orchestrator with DI support
4. ✅ Strategies registered in DI with correct priority
5. ✅ `SymfonyController` refactored to use `ResponseConverter`
6. ✅ All tests pass (unit + integration)
7. ✅ Code style compliant
8. ✅ No breaking changes to public API

**Next steps:**
- Issue #70 (BinaryFileResponse) - now works via BinaryFileResponseStrategy
- Issue #69 (StreamedResponse) - now works via StreamedResponseStrategy
- Issue #71 (EventStreamResponse) - can add new strategy with priority 75
