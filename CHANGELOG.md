# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- Enabled branch protection on `master` branch ([#132](https://github.com/crazy-goat/workerman-bundle/issues/132))
  - Required status checks: `Tests/lint` and `Tests/tests`
  - Require branches to be up to date before merging
  - Require conversation resolution before merging
  - No approval count required (solo dev project)

### Added

- Added validation in `ConfigLoader::warmUp()` to ensure all config sections are set before caching ([#35](https://github.com/crazy-goat/workerman-bundle/issues/35))
  - New `ConfigSection` enum with cases: WORKERMAN, PROCESS, SCHEDULER
  - Throws `LogicException` with descriptive message when any section is missing
  - Prevents incomplete configuration from being cached

### Changed

- **Breaking**: Config cache format changed from numeric indices to string keys ([#35](https://github.com/crazy-goat/workerman-bundle/issues/35))
  - **Old format:** `[0 => ..., 1 => ..., 2 => ...]`
  - **New format:** `['workerman' => ..., 'process' => ..., 'scheduler' => ...]`
  - Uses `ConfigSection` enum values as keys for clarity and type safety
  - **Migration**: Clear cache after upgrade: `rm -rf var/cache/*`

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

### Removed

- **Breaking**: Removed root-level exception classes (moved to `Exception` namespace)
  - `CrazyGoat\WorkermanBundle\ServerAlreadyRunningException` → `CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException`
  - `CrazyGoat\WorkermanBundle\ServerNotRunningException` → `CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException`
  - `CrazyGoat\WorkermanBundle\ServerStopFailedException` → `CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException`

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

#### For consumers catching specific exceptions

Update your catch blocks to use the new exception classes:

```php
// Before
use CrazyGoat\WorkermanBundle\ServerAlreadyRunningException;

// After
use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
```

#### For consumers catching generic exceptions

**No changes required** - backward compatibility is maintained for:
- Code catching `\RuntimeException` (all exceptions extend it via `WorkermanException`)
- Code catching `\InvalidArgumentException` (validation/scheduler/middleware exceptions extend it)

#### For consumers catching LogicException

**Breaking change**: The exception thrown when cron expression package is not installed changed from `\LogicException` to `InvalidCronExpressionException` (extends `\InvalidArgumentException`).

Update your code:

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
