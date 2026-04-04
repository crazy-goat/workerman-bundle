# ResponseConverter Design Document

**Date:** 2026-04-04  
**Issue:** #72 - Response conversion should be extracted to a dedicated ResponseConverter  
**Status:** Approved

---

## Summary

Extract inline response conversion logic from `SymfonyController` into a dedicated `ResponseConverter` class using the Strategy Pattern. This enables testable, extensible handling of different Symfony response types and unblocks implementation of #71 (SSE), #70 (BinaryFileResponse), and #69 (StreamedResponse).

---

## Problem Statement

Currently, `SymfonyController::__invoke()` contains inline response conversion:

```php
return new Response(
    $this->symfonyResponse->getStatusCode(),
    $this->getHeaders($this->symfonyResponse),
    strval($this->symfonyResponse->getContent()),
);
```

**Issues:**
- Not testable in isolation
- Violates Single Responsibility Principle
- Cannot handle special response types (BinaryFileResponse, StreamedResponse, EventStreamResponse)
- Blocks implementation of critical bugs #71, #70, #69

---

## Solution Overview

Implement Strategy Pattern with:
- `ResponseConverter` - orchestrator that selects and executes appropriate strategy
- `ResponseConverterStrategy` interface - contract for all strategies
- Concrete strategies for each response type
- Dependency Injection for extensibility

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    ResponseConverter                        │
│                    (orchestrator)                           │
├─────────────────────────────────────────────────────────────┤
│  convert(SymfonyResponse): WorkermanResponse                 │
│  - iterates over strategies                                 │
│  - first strategy where supports() returns true → convert() │
└─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        ▼                     ▼                     ▼
┌───────────────┐   ┌──────────────────┐   ┌──────────────┐
│ BinaryFile    │   │ StreamedResponse │   │ Default      │
│   Strategy    │   │     Strategy     │   │  Strategy    │
├───────────────┤   ├──────────────────┤   ├──────────────┤
│ BinaryFileResp│   │ StreamedResponse │   │ (fallback)   │
│ withFile()    │   │ ob_start()       │   │ getContent() │
│ Range support │   │ ob_get_clean()   │   │              │
└───────────────┘   └──────────────────┘   └──────────────┘
                              │
                              ▼
                    ┌──────────────────┐
                    │  Future: SSE     │
                    │    Strategy      │
                    │ (EventStreamResp)│
                    └──────────────────┘
```

---

## File Structure

```
src/
└── Http/
    └── Response/
        ├── ResponseConverter.php              # Main orchestrator
        ├── ResponseConverterStrategy.php      # Interface
        └── Strategy/
            ├── BinaryFileResponseStrategy.php   # Priority: 100
            ├── StreamedResponseStrategy.php     # Priority: 50
            └── DefaultResponseStrategy.php      # Priority: 0
```

---

## Interface Definition

```php
interface ResponseConverterStrategy
{
    /**
     * Check if this strategy can handle the given response.
     */
    public function supports(\Symfony\Component\HttpFoundation\Response $response): bool;
    
    /**
     * Convert Symfony response to Workerman response.
     * 
     * @param array<string, list<string|null>> $headers Pre-extracted headers
     */
    public function convert(
        \Symfony\Component\HttpFoundation\Response $response,
        array $headers
    ): \Workerman\Protocols\Http\Response;
}
```

---

## Strategy Priority Order

**Critical:** Order matters due to class hierarchy!

```
BinaryFileResponse extends StreamedResponse
EventStreamResponse extends StreamedResponse
```

| Priority | Strategy | Handles | Why this priority? |
|----------|----------|---------|-------------------|
| 100 | BinaryFileResponseStrategy | BinaryFileResponse | Most specific - must be checked before parent StreamedResponse |
| 50 | StreamedResponseStrategy | StreamedResponse, StreamedJsonResponse | Parent class - checked after more specific children |
| 0 | DefaultResponseStrategy | Response, JsonResponse, etc. | Fallback for all other types |

---

## Implementation Details

### ResponseConverter

```php
class ResponseConverter
{
    /** @var ResponseConverterStrategy[] */
    private readonly array $strategies;
    
    public function __construct(iterable $strategies)
    {
        $this->strategies = iterator_to_array($strategies, false);
    }
    
    public function convert(\Symfony\Component\HttpFoundation\Response $response): \Workerman\Protocols\Http\Response
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
    private function extractHeaders($response): array
    {
        $headers = $response->headers->all();
        
        // Fix header names (from current SymfonyController)
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

### BinaryFileResponseStrategy

```php
class BinaryFileResponseStrategy implements ResponseConverterStrategy
{
    public function supports($response): bool
    {
        return $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse;
    }
    
    public function convert($response, array $headers): \Workerman\Protocols\Http\Response
    {
        $workermanResponse = new \Workerman\Protocols\Http\Response(
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

### StreamedResponseStrategy

```php
class StreamedResponseStrategy implements ResponseConverterStrategy
{
    public function supports($response): bool
    {
        // Exclude BinaryFileResponse - it has higher priority
        return $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            && !$response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse;
    }
    
    public function convert($response, array $headers): \Workerman\Protocols\Http\Response
    {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        
        return new \Workerman\Protocols\Http\Response(
            $response->getStatusCode(),
            $headers,
            $content
        );
    }
}
```

### DefaultResponseStrategy

```php
class DefaultResponseStrategy implements ResponseConverterStrategy
{
    public function supports($response): bool
    {
        // Fallback - handles everything
        return true;
    }
    
    public function convert($response, array $headers): \Workerman\Protocols\Http\Response
    {
        return new \Workerman\Protocols\Http\Response(
            $response->getStatusCode(),
            $headers,
            strval($response->getContent())
        );
    }
}
```

---

## Dependency Injection Configuration

```php
// src/config/services.php

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;

// Register strategies with priority (higher = checked first)
$services->set(BinaryFileResponseStrategy::class)
    ->tag('workerman.response_converter.strategy', ['priority' => 100]);

$services->set(StreamedResponseStrategy::class)
    ->tag('workerman.response_converter.strategy', ['priority' => 50]);

$services->set(DefaultResponseStrategy::class)
    ->tag('workerman.response_converter.strategy', ['priority' => 0]);

// Main converter - injects all tagged strategies
$services->set(ResponseConverter::class)
    ->args([tagged_iterator('workerman.response_converter.strategy')]);
```

---

## SymfonyController Changes

**Before:**
```php
public function __invoke(Request $request): Response
{
    $this->symfonyRequest = RequestConverter::toSymfonyRequest($request);
    $this->kernel->boot();
    
    $this->symfonyResponse = $this->kernel->handle($this->symfonyRequest);
    $this->symfonyResponse->prepare($this->symfonyRequest);
    
    return new Response(
        $this->symfonyResponse->getStatusCode(),
        $this->getHeaders($this->symfonyResponse),
        strval($this->symfonyResponse->getContent()),
    );
}
```

**After:**
```php
public function __construct(
    private readonly KernelInterface $kernel,
    private readonly ResponseConverter $responseConverter,
) {
}

public function __invoke(Request $request): Response
{
    $this->symfonyRequest = RequestConverter::toSymfonyRequest($request);
    $this->kernel->boot();
    
    $this->symfonyResponse = $this->kernel->handle($this->symfonyRequest);
    $this->symfonyResponse->prepare($this->symfonyRequest);
    
    return $this->responseConverter->convert($this->symfonyResponse);
}
```

---

## Testing Strategy

### Unit Tests

1. **BinaryFileResponseStrategyTest**
   - Test `supports()` returns true only for BinaryFileResponse
   - Test `convert()` calls `withFile()` with correct parameters
   - Test Range request handling (offset, maxlen)

2. **StreamedResponseStrategyTest**
   - Test `supports()` returns true for StreamedResponse but NOT BinaryFileResponse
   - Test `convert()` captures output buffer correctly
   - Test with StreamedJsonResponse

3. **DefaultResponseStrategyTest**
   - Test `supports()` always returns true
   - Test `convert()` uses `getContent()`

4. **ResponseConverterTest**
   - Test strategies are checked in priority order
   - Test correct strategy is selected for each response type
   - Test exception when no strategy found (edge case)

### Integration Tests

1. **SymfonyControllerTest**
   - Verify controller uses ResponseConverter
   - Test end-to-end with different response types

---

## Future Extensibility

This design enables easy addition of new response types:

**Example: EventStreamResponseStrategy (for #71)**
```php
class EventStreamResponseStrategy implements ResponseConverterStrategy
{
    public function supports($response): bool
    {
        return $response instanceof EventStreamResponse;
    }
    
    public function convert($response, array $headers): Response
    {
        // Special SSE handling with connection management
        // Requires access to TcpConnection (architectural change)
    }
}
```

**Priority:** 75 (between BinaryFile and StreamedResponse)

---

## Benefits

1. **Testability** - Each strategy testable in isolation
2. **Single Responsibility** - Each class has one clear purpose
3. **Extensibility** - New response types via new strategies
4. **Open/Closed** - Open for extension, closed for modification
5. **Unblocks** #71, #70, #69 - provides foundation for their implementation

---

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Wrong strategy order breaks BinaryFileResponse | Document priority system clearly; add integration test |
| Performance overhead of iteration | Negligible (3-4 strategies max); can optimize later if needed |
| Breaking change for existing code | SymfonyController keeps same public API; only internal change |

---

## Acceptance Criteria

- [ ] ResponseConverter class exists with DI support
- [ ] All three strategies implemented and registered
- [ ] SymfonyController uses ResponseConverter
- [ ] Unit tests for all strategies
- [ ] Integration tests for ResponseConverter
- [ ] Existing tests still pass
- [ ] No breaking changes to public API

---

## Related Issues

- #72 - This issue (extract ResponseConverter)
- #71 - EventStreamResponse support (future strategy)
- #70 - BinaryFileResponse support (BinaryFileResponseStrategy)
- #69 - StreamedResponse support (StreamedResponseStrategy)
