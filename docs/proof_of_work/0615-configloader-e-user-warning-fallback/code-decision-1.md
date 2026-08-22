# Code decision — round 1 (issue #615)

## Problem

`ConfigLoader::validateCacheFilePermissions()` (`src/ConfigLoader.php`) has a
no-logger fail-open path: when the PSR-3 logger is not configured and the
permission metadata cannot be read (a TOCTOU window or a filesystem where
the POSIX implication does not hold), it surfaced the advisory warning via
`trigger_error($verdict['warn'], \E_USER_WARNING)`. An absolute
`\E_USER_WARNING` is unconditionally delivered to the installed error handler.
Under a throwing error handler — Symfony's `DebugErrorHandler` in debug mode
escalates `E_USER_WARNING` to `ErrorException` — an advisory "fail-open with a
signal" turns into a hard, uncaught boot failure. Fail-open becomes
fail-closed. The path was untested against a throwing handler and the
`trigger_error` choice was undocumented.

## Approach taken

Replace `trigger_error($verdict['warn'], \E_USER_WARNING)` with
`error_log($verdict['warn'])`, and document the choice in the method's
phpdoc:

```php
if ($this->logger instanceof \Psr\Log\LoggerInterface) {
    $this->logger->warning($verdict['warn'], ['path' => $cachePath]);
} else {
    error_log($verdict['warn']);
}
```

Three decisions inside this:

1. **`error_log()` as the no-logger signal channel.** It writes directly to
   the configured log and **does not invoke the PHP error handler at all**,
   so it cannot throw. This is precisely the codebase-wide no-logger
   convention (issue #670 faced the identical decision and documents it):
   `src/Worker/ServerWorker.php:87-89`, `src/Http/HttpRequestHandler.php:193/272`,
   `src/DTO/RequestConverter.php:285/293`, `src/Phar/SfxDownloader.php:97/572/586/594`.
2. **phpdoc update.** The old docs said the warning was "raised as an
   `\E_USER_WARNING`" in the no-logger case; that is now wrong. The doc now
   says it is emitted via `error_log()` and adds a sentence explaining it is
   deliberate so a throwing error handler cannot turn the fail-open warning
   into an exception.
3. **Scope kept tight.** Only the `validateCacheFilePermissions()` no-logger
   branch is changed. No other `trigger_error` was touched (see
   `findings-coder.md` for the audit).

## What I rejected

- **`trigger_error(\E_USER_WARNING)` (status quo)** — invokes the error
  handler; a strict handler (Symfony `DebugErrorHandler` in debug mode)
  converts it to `ErrorException`, turning fail-open into a hard boot
  failure. This is the exact bug being fixed.
- **Temporarily swallowing the error handler around the call** (install a
  no-op handler, call `trigger_error`, restore) — fragile and global: it
  affects the process's handler state and, if any restore is skipped, leaks a
  broken handler for the rest of boot. It also still routes the message
  through the handler machinery instead of the log, and fights the framework
  instead of staying out of its way.
- **Documentation-only** (add a caveat but keep `trigger_error`) — gets
  nothing for the affected users; debug-mode Symfony apps still hard-fail at
  boot. The whole point of fail-open is to *not* crash.

## Acceptance-criterion reasoning

The criterion is "a throwing handler still fires for unrelated warnings".
`error_log()` does not touch the error handler at all — PHP's error-handling
pipeline is bypassed — so a subsequently installed throwing handler
continues to receive unrelated `trigger_error()`/warning calls elsewhere
unchanged. Nothing about this change globally disables or modifies
`set_error_handler()` state (contrast with "temporarily swallowing the
handler", which would have to). New test
`testValidateCacheFilePermissionsDoesNotThrowWithThrowingErrorHandlerAndNoLogger`
registers such a throwing handler and proves (a) no exception escapes the
unreadable-metadata path and (b) the warning still reached `error_log`.

## What I was unsure about

- **`error_log()` buffering / destination on every SAPI.** `error_log()`
  with the default message type writes to the configured `error_log` ini
  target; a `php.ini` `error_log` setting of `syslog` would redirect it.
  Capturing via `ini_set('error_log', $file)` in the test is not a substitute
  for the production destination, but it is the repo's established
  capture pattern (`tests/HttpRequestHandlerTest.php:927-945`) and
  `error_log()` for message type 0 is line-atomic enough for a per-line
  assert.
- **`error_log()` vs `syslog`/PSR-3 asymmetry.** The logger path adds
  `['path' => $cachePath]` context; `error_log()` has no structured context,
  so the path is only embedded in the message. That is consistent with the
  other no-logger call sites and was deliberately not "fixed" — batch-adding
  the path context to every message would have widened scope.
- **Whether `validateCacheFilePermissions()` is reached through the public
  API at all in the unreadable-metadata case** — it normally is not (see the
  long phpdoc note: `is_file()` gates first), which is why this path is
  "defensive". The throwing-handler test drives the private method via
  reflection, exactly like the pre-existing logger-path test.
