# Code Decision 2 — Issue #588: Symfony 6.4 legs fail after pinning http-foundation

Round: CI fix (main session, PR #806 run 34403956293). All four `6.4.*` matrix
legs fail with 4 errors + 2 failures; `7.4`/`8.0` legs and lint are green.

## What happened

Declaring `symfony/http-foundation` as a direct require pins it to `6.4.*` on
the 6.4 legs via the matrix `sed`. Previously it floated transitively (the
failing leg's own log shows `symfony/error-handler v7.4.17` still floating),
so the suite had **never actually run against 6.4's `BinaryFileResponse`**:

- 6.4 has no `BinaryFileResponse::$tempFileObject` property → 4 errors from
  reflection `getProperty()` in the reflector/strategy unit tests, plus the
  E2E `ResponseTest::testBinaryFileResponseWithTempFileObject` 500ing
  because the `ResponseTestController::tempFileResponse()` fixture uses the
  same reflection.
- 6.4's `setChunkSize(0)` throws `LogicException`, 7+ throws
  `InvalidArgumentException` → 1 failure in
  `StreamedBinaryFileResponseTest::testSetChunkSizeThrowsExceptionForInvalidValues`.

## Approach taken: test-only fix, no `src/` change

`BinaryFileResponseReflector` already catches `ReflectionException` and
returns `null` for absent properties, so on 6.4 the temp-file path is
correctly dead — the production code is already 6.4-safe. Only the tests
assumed 7.x-only API:

1. **Feature-detection skips** (`property_exists(BinaryFileResponse::class,
   'tempFileObject')`) in the 4 unit tests + the E2E test, following the
   existing `!class_exists(StreamedJsonResponse)` precedent in
   `SymfonyControllerTest`. The E2E daemon boots from the same vendor dir,
   so the check in the test process reflects the daemon's Symfony.
   Deliberately version-agnostic wording (no "requires Symfony 7.x" claim
   I did not verify against the Symfony changelog).
2. **`LogicException` + message fragment** for `setChunkSize(0)`:
   `InvalidArgumentException extends LogicException`, so expecting the
   parent covers both versions; `expectExceptionMessage('cannot be less
   than 1')` (present in both the 6.4 and 8.x messages) keeps the
   assertion pinned to the chunk-size validation.

## What I rejected and why

1. **Rewriting the tests to use `setFile(new \SplTempFileObject())`
   instead of reflection.** Works without reflection on modern Symfony
   (`setFile()` assigns `tempFileObject`), but 6.4's `setFile()` behavior
   with a temp object is unknown and unverifiable locally (installed tree
   is 8.1) — it would trade a known-good skip for an untested assumption.
2. **Touching the `ResponseTestController` fixture.** The suite never
   requests the route on 6.4 once the E2E test skips; changing the
   fixture's return contract (e.g. a 501 fallback) would add
   never-exercised-on-modern-Symfony code for a test-only route.
3. **Narrowing the declared constraint (e.g. `http-foundation: ^7.0`).**
   That would "fix" CI by dropping the 6.4 support the bundle claims and
   the rest of the suite still validates — the opposite direction from
   the issue's intent.

## Anything I was unsure about

- Whether other 6.4-only incompatibilities hide behind these 6 failures
  (a test that errors early can't reveal a second one). The re-run of the
  full matrix after this fix is the oracle; the failure set was identical
  across all four 6.4 legs, which suggests a single root cause.
- The `expectExceptionMessage` fragment relies on the 6.4 message text
  seen in the CI log ("...cannot be less than 1 or greater than
  PHP_INT_MAX.") — stable within the 6.4 line, and the 7+/8.x message
  ("...cannot be less than 1.") shares the prefix.
