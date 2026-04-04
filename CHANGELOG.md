# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Extracted `ResponseConverter` from `SymfonyController` using Strategy Pattern ([#72](https://github.com/crazy-goat/workerman-bundle/issues/72))
  - New `ResponseConverterStrategyInterface` for pluggable response conversion strategies
  - New `ResponseConverter` orchestrator that selects and executes appropriate strategy based on response type
  - New `DefaultResponseStrategy` for handling standard Symfony responses
  - New `NoResponseStrategyException` for when no matching strategy is found
  - Priority-based strategy registration via DI container tags (`workerman.response_converter.strategy`)
  - Foundation for implementing BinaryFileResponse, StreamedResponse, and EventStreamResponse support (#69, #70, #71)

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
