# Code Decision 1 — Issue #700: SchedulerWorker discards trigger exception message

## Approach

The fix is the smallest correct change: capture the caught exception as `$e`
and append its message to the existing log line with a `: %s` suffix.

**Before** (`src/Worker/SchedulerWorker.php:61-62`):
```php
} catch (\InvalidArgumentException) {
    $this->worker->log(sprintf('%s Task "%s" skipped. Trigger "%s" is incorrect', $this->worker->name, $taskName, $serviceConfig['schedule']));
```

**After**:
```php
} catch (\InvalidArgumentException $e) {
    $this->worker->log(sprintf('%s Task "%s" skipped. Trigger "%s" is incorrect: %s', $this->worker->name, $taskName, $serviceConfig['schedule'], $e->getMessage()));
```

The existing log format/prefix is preserved verbatim; only `: <message>` is
appended at the end. This matches the issue's suggested fix exactly and stays
consistent with the other `Task "..." skipped.` log lines in the same method
(line 55, "Trigger has not been set").

## What I rejected and why

- **Logging the full exception (class + trace)** — Rejected. The other failure
  log paths in this worker that *do* dump a trace (e.g. `handleChild` line 324)
  are for task execution failures where a stack trace is actionable. Here the
  exception is a configuration rejection at startup: the message alone
  ("Interval must be a positive duration", "Unknown or bad format (PT)") is the
  actionable signal; a trace would be noise in the scheduler log.
- **Re-throwing after logging** — Rejected. The current contract is
  fail-soft: a bad trigger skips one task and lets the rest schedule. Changing
  that to fail-hard would be a behavior change outside this issue's scope and
  would break the existing `testZeroInterval...` test which expects the loop to
  continue.
- **Adding a dedicated `catch (InvalidTriggerException)` branch** — Rejected.
  `InvalidTriggerException` extends `\InvalidArgumentException` (via
  `SchedulerException`), and `TriggerFactory` can also throw a plain
  `\InvalidArgumentException` from `new \DateInterval()` (caught and rewrapped
  by `PeriodicalTrigger`, but the catch type should stay broad to match the
  factory's `@throws` contract). Narrowing the catch type risks missing a
  future trigger type's exception.

## Tests

Two tests now cover this path in `tests/Worker/SchedulerWorkerTest.php`:

1. **`testZeroIntervalTaskIsSkippedWithIncorrectTriggerLog`** (existing,
   extended) — now also asserts `Interval must be a positive duration` appears
   in the output. This is the `PT0S` → `PeriodicalTrigger` validation path
   added by #667.
2. **`testIncorrectTriggerLogIncludesExceptionMessage`** (new) — uses `PT`
   (malformed ISO-8601 duration, fails `new \DateInterval('PT')`) to exercise a
   different rejection path and assert the log line matches
   `/Trigger "PT" is incorrect: .+/` (the message is present, not just the
   prefix). I used a regex rather than pinning the exact PHP-version-dependent
   `DateInterval` error text ("Unknown or bad format (PT)") so the test is
   stable across PHP versions.

Both follow the existing `invokeOnWorkerStartWithConfig` helper pattern that
captures `Worker::log()` output via a `php://memory` stream.

## Anything I was unsure about

- Whether to assert the exact `DateInterval` error string in the new test. I
  chose a regex (`is incorrect: .+`) because the underlying message is emitted
  by PHP's `DateInterval` constructor and its wording has changed between PHP
  versions. The `PT0S` test pins the exact message
  (`Interval must be a positive duration`) because that string is owned by our
  own `PeriodicalTrigger` and is stable.
