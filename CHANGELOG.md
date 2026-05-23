# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.18.0] - 2026-05-20

### Added

- PHAR and standalone binary packaging support ([#191](https://github.com/crazy-goat/workerman-bundle/issues/191))
  - New `workerman:build:phar` command to build PHAR archives with dynamic stub
  - New `workerman:build:bin` command to create standalone binaries (SFX + custom php.ini + PHAR)
  - New `PharHelper` utility for detecting PHAR mode and resolving runtime paths outside the archive
  - New `build` configuration section for PHAR exclusions, custom php.ini, and SFX sources
  - `--kernel-class` CLI option for overriding kernel class in PHAR stub
  - File monitor automatically disabled in PHAR mode
  - `ConfigLoader` fallback when cache is missing for PHAR scenarios

### Changed

- `Runner` source path is now configurable instead of hardcoded to `tests/App` ([#130](https://github.com/crazy-goat/workerman-bundle/issues/130))

### Fixed

- Improved cache warmup error messages to include exit codes, signal numbers, and status details ([#129](https://github.com/crazy-goat/workerman-bundle/issues/129))
- Closed `proc_open` pipes in test bootstrap to prevent file descriptor leaks ([#170](https://github.com/crazy-goat/workerman-bundle/issues/170))
- Replaced `boolval()` with `(bool)` cast for consistent style across the codebase ([#159](https://github.com/crazy-goat/workerman-bundle/issues/159))
- Added missing `final` keyword to `MiddlewareTest` and `StaticFilesMiddlewareTest` ([#168](https://github.com/crazy-goat/workerman-bundle/issues/168))
- Removed redundant `getFileInfo()` call on `SplFileInfo` object in `PollingMonitorWatcher` ([#166](https://github.com/crazy-goat/workerman-bundle/issues/166))
- Replaced deprecated `LevelSetList::UP_TO_PHP_82` with `withPhpSets()` in `rector.php` ([#164](https://github.com/crazy-goat/workerman-bundle/issues/164))
- Enabled `composer audit block-insecure` to report insecure packages instead of silently ignoring them ([#43](https://github.com/crazy-goat/workerman-bundle/issues/43))
- Aligned test namespace with PSR-4 `autoload-dev` mapping ([#167](https://github.com/crazy-goat/workerman-bundle/issues/167))
- Updated `phpunit.xml` schema version to match installed PHPUnit 10.5 ([#162](https://github.com/crazy-goat/workerman-bundle/issues/162))
- Pinned PHP version to 8.2 in CI lint job for deterministic behavior ([#169](https://github.com/crazy-goat/workerman-bundle/issues/169))
- Replaced flaky composer audit shell-based test with resilient JSON-based E2E tests ([#188](https://github.com/crazy-goat/workerman-bundle/issues/188))

## [0.17.0] - 2026-05-19

### Added

- Added `Utils::reload()` method as the canonical name for graceful worker restart ([#32](https://github.com/crazy-goat/workerman-bundle/issues/32))
  - `Utils::reboot()` is preserved as a deprecated alias with deprecation notice
  - Updated all internal callers and watcher classes

- Added `SymfonyController` injection via DI into `HttpRequestHandler` ([#158](https://github.com/crazy-goat/workerman-bundle/issues/158))
  - `HttpRequestHandler` now accepts `SymfonyController $controller` via constructor injection
  - `WorkermanCompilerPass` registers `workerman.symfony_controller` service with autowiring alias
  - Removed unused `KernelInterface` and `ResponseConverter` dependencies

### Changed

- Replaced `require` pattern in `WorkermanBundle` with proper injectable classes ([#145](https://github.com/crazy-goat/workerman-bundle/issues/145))
  - Extracted configuration tree building into `ConfigurationTreeBuilder`
  - Extracted service registration into `ServicesConfigurator`
  - Removed `src/config/configuration.php` and `src/config/services.php`

- Removed unnecessary `array_map` calls and simplified data flow in `WorkermanCompilerPass` ([#24](https://github.com/crazy-goat/workerman-bundle/issues/24))

- composer.json `audit.abandoned` config from `"ignore"` to `"report"` so abandoned package warnings are no longer silently suppressed ([#163](https://github.com/crazy-goat/workerman-bundle/issues/163))

### Fixed

- Removed FPM-specific no-op calls (`ignore_user_abort()`, `connection_aborted()`) from `StreamedBinaryFileResponse` — these have no effect in Workerman's event-driven architecture ([#160](https://github.com/crazy-goat/workerman-bundle/issues/160))
  - Added 14 unit tests and 1 E2E test for streamed binary file response

- Extracted magic string `'+10 year'` to class constant `MAX_SCHEDULE_HORIZON` in `PeriodicalTrigger` ([#156](https://github.com/crazy-goat/workerman-bundle/issues/156))

### Removed

- Removed dead `StreamResponseInterface` and `streamContent()` method from `StreamedBinaryFileResponse` — the generator-based streaming was never called by the response pipeline; `BinaryFileResponseStrategy` handles it via `withFile()` ([#165](https://github.com/crazy-goat/workerman-bundle/issues/165))

- Removed 8 permanently skipped tests from `HttpRequestHandlerTest` that were never executed ([#154](https://github.com/crazy-goat/workerman-bundle/issues/154))

## [0.16.0] - 2026-05-18

### Added

- Added configurable cache warmup timeout ([#142](https://github.com/crazy-goat/workerman-bundle/issues/142), [#180](https://github.com/crazy-goat/workerman-bundle/pull/180))
  - New `cache_warmup_timeout` configuration node with `min(1)` validation
  - New `WORKERMAN_CACHE_WARMUP_TIMEOUT` environment variable support
  - Removed hardcoded `CACHE_WARMUP_TIMEOUT` from Runner

### Security

- RequestConverter no longer trusts `X-Forwarded-Proto` header
  unconditionally. HTTPS is now detected only from the actual SSL transport
  layer. Users behind reverse proxies must configure Symfony's trusted
  proxies. ([#152](https://github.com/crazy-goat/workerman-bundle/issues/152))

### Fixed

- Fixed `Runner::run()` — `mkdir()` return value now checked to prevent silent failures ([#151](https://github.com/crazy-goat/workerman-bundle/issues/151))
  - Throws `\RuntimeException` with clear message when directory creation fails
  - Double `is_dir()` check handles race condition between check and `mkdir()` call

- Fixed `Runner::run()` — added timeout for cache warmup, use `posix_kill` instead of `exit` ([#141](https://github.com/crazy-goat/workerman-bundle/issues/141))
  - Added `CACHE_WARMUP_TIMEOUT` constant (30 seconds)
  - Use `posix_kill()` to avoid deadlock with extensions that register shutdown handlers
  - Handle SIGKILL as success, SIGTERM as error

- Fixed `ProcessHandler` and `TaskHandler` — validate dynamic method calls to prevent worker crashes ([#153](https://github.com/crazy-goat/workerman-bundle/issues/153), [#174](https://github.com/crazy-goat/workerman-bundle/pull/174))
  - Extracted shared validation into `ServiceMethodHelper`
  - Moved method existence check inside try-catch for graceful error handling

- Fixed `Utils::cpuCount()` — handle null from `shell_exec('nproc')` ([#150](https://github.com/crazy-goat/workerman-bundle/issues/150))
  - Added `is_string()` check for nproc output
  - Return 1 as safe fallback when nproc is unavailable (e.g., minimal containers)

- Fixed `PeriodicalTrigger` — removed useless `assert()` ([#178](https://github.com/crazy-goat/workerman-bundle/issues/178))

- Fixed `SchedulerWorker` — log exceptions in child process instead of swallowing ([#178](https://github.com/crazy-goat/workerman-bundle/issues/178))
  - When a scheduled task throws an exception in a forked child process,
    the exception is now logged with diagnostic information

- Fixed `ServerManager` — replaced hardcoded `sleep(1)` with polling loop ([#155](https://github.com/crazy-goat/workerman-bundle/issues/155), [#179](https://github.com/crazy-goat/workerman-bundle/pull/179))
  - Applied polling pattern to `getServerStatus()` and `getConnections()`

- Fixed `WorkermanCompilerPass::referenceMap()` — improved PHPDoc ([#171](https://github.com/crazy-goat/workerman-bundle/issues/171))
  - Described what the method does
  - Tightened `@param` type to match `findTaggedServiceIds()` return shape

- Fixed CI: upgraded `actions/checkout` from v2 to v6.0.2 with SHA pinning ([#172](https://github.com/crazy-goat/workerman-bundle/pull/172))
- Fixed CI: pinned `shivammathur/setup-php` to commit SHA in tests workflow ([#149](https://github.com/crazy-goat/workerman-bundle/issues/149), [#175](https://github.com/crazy-goat/workerman-bundle/pull/175))

## [0.15.0] - 2026-04-15

### Security

- Enabled branch protection on `master` branch ([#132](https://github.com/crazy-goat/workerman-bundle/issues/132))
  - Required status checks: `Tests/lint` and `Tests/tests`
  - Require branches to be up to date before merging
  - Require conversation resolution before merging
  - No approval count required (solo dev project)

### Added

- Added `ServerAction` enum for type-safe command actions ([#135](https://github.com/crazy-goat/workerman-bundle/issues/135))
  - Replaces string-based action constants with strongly-typed enum
  - Cases: START, STOP, RESTART, RELOAD, STATUS
  - Used by `WorkermanCommand` and `ServerManager` for improved type safety

- Added validation in `ConfigLoader::warmUp()` to ensure all config sections are set before caching ([#35](https://github.com/crazy-goat/workerman-bundle/issues/35))
  - New `ConfigSection` enum with cases: WORKERMAN, PROCESS, SCHEDULER
  - Throws `LogicException` with descriptive message when any section is missing
  - Prevents incomplete configuration from being cached

- Added pre-push git hook to run `composer lint` before pushing ([#137](https://github.com/crazy-goat/workerman-bundle/issues/137))
  - Hook runs static analysis and code style checks before push
  - Prevents pushing code that doesn't pass linting

### Fixed

- Fixed `TriggerFactory` fragile cron expression detection heuristic ([#34](https://github.com/crazy-goat/workerman-bundle/issues/34), [#138](https://github.com/crazy-goat/workerman-bundle/issues/138))
  - Uses `CronExpression::isValidExpression()` for robust detection instead of exception-based heuristic
  - Added `class_exists` check for graceful handling when package is not installed

- Fixed `SupervisorWorker` — removed `sleep(1)` hack and added proper logging ([#36](https://github.com/crazy-goat/workerman-bundle/issues/36), [#143](https://github.com/crazy-goat/workerman-bundle/issues/143))
  - Removed arbitrary 1-second sleep that was causing race conditions
  - Added proper logging for state transitions and errors
  - Improved reliability of worker supervision

- Fixed `WorkermanCompilerPass` — replaced anonymous class with proper named class ([#37](https://github.com/crazy-goat/workerman-bundle/issues/37), [#144](https://github.com/crazy-goat/workerman-bundle/issues/144))
  - Extracted anonymous class from `config/compilerpass.php` to `src/DependencyInjection/WorkermanCompilerPass.php`
  - Added comprehensive unit tests for compiler pass functionality

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
