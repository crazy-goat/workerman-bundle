# Findings — Coder — Issue #700

## What I found along the way

### Obstacles / surprises

- None significant. The fix is a one-line change. The exception hierarchy was
  the only thing worth verifying: `InvalidTriggerException` →
  `SchedulerException` (abstract) → `\InvalidArgumentException`, so the
  existing `catch (\InvalidArgumentException)` already catches all trigger
  rejections — the only missing piece was capturing `$e` and appending its
  message. Confirmed at `src/Exception/SchedulerException.php:13` and
  `src/Exception/InvalidTriggerException.php:10`.

### Biggest problem faced

No real problem. The only nuance was choosing a test trigger value whose
exception message is stable enough to assert against. `PT0S` exercises our own
`PeriodicalTrigger` validation (stable, owned string), while `PT` exercises
PHP's native `DateInterval` parser (wording varies by PHP version), so I
asserted the former with an exact string and the latter with a regex.

## Discovered bugs / places to improve

### 1. `SchedulerWorker` empty-schedule log line is inconsistent with the corrected one (out of scope, minor)

**File:** `src/Worker/SchedulerWorker.php:55`

The "Trigger has not been set" log line has no trailing punctuation/context
while the sibling rejection line now ends with `: <message>`. This is purely
cosmetic and not worth a separate issue, but if someone later normalizes the
skip-log format, both lines should be aligned.

**Suggested fix:** Leave as-is; only revisit if the skip-log format is
standardized.

### 2. `TriggerFactory::create()` can let a raw `\InvalidArgumentException` from `new \DateInterval()` escape unrewrapped (out of scope, very minor)

**File:** `src/Scheduler/Trigger/PeriodicalTrigger.php:36` and `:39`

`PeriodicalTrigger::__construct()` wraps its whole body in `try/catch
(\Throwable $e)` and rethrows as `InvalidTriggerException`, so in practice
every rejection is normalized. However the `try` also catches the
`InvalidTriggerException` thrown at line 54 and rewraps it *again* (line 61),
producing a nested message like `Invalid interval "PT0S": Interval must be a
positive duration` where the inner message is already descriptive. This double
wrapping is harmless (the message is still surfaced correctly after this
issue's fix) but slightly redundant. Not in scope for #700.

**Suggested fix:** In `PeriodicalTrigger::__construct()`, rethrow
`InvalidTriggerException` unchanged inside the `catch (\Throwable)` instead of
rewrapping it, e.g.:
```php
} catch (InvalidTriggerException $e) {
    throw $e;
} catch (\Throwable $e) {
    ...
    throw new InvalidTriggerException(...);
}
```

### 3. No test asserts that a *cron* expression rejection surfaces a message (out of scope, minor)

The two trigger-rejection tests both go through the `PeriodicalTrigger`/`DateInterval`
path. A cron-expression path (`CronExpressionTrigger`) is not exercised for the
rejection log. This is a test-coverage gap, not a bug — the catch is generic
so it would work — but a future regression in `TriggerFactory` dispatch could
go unnoticed.

**Suggested fix:** Add a test with an invalid cron string (e.g. `'* * * * *
bad'` or a clearly malformed expression) asserting the rejection log contains
the cron library's error message. Low priority.
