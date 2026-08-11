# Findings — coder (issue #667)

## In-scope findings

1. **`SchedulerWorker` treats `schedule => 0` as "no schedule", not as an
   error** — `src/Worker/SchedulerWorker.php:71-75`
   (`empty($serviceConfig['schedule'])` → `Task "..." skipped. Trigger has
   not been set`). This is a pre-existing safety net that makes the int-0
   case *less* harmful than the issue title suggests, but it also means the
   "0" config value never reaches the trigger at all. My worker-level
   regression test initially used `schedule => 0` and failed for exactly
   this reason; switched to `'PT0S'` to exercise the constructor path.
   No code change made — `empty()` behaviour is fine as an extra guard, and
   the constructor now covers every non-empty zero/negative form.

2. **`PeriodicalTrigger::getNextRunDate()`'s null guard is now dead code for
   constructed instances** — `src/Scheduler/Trigger/PeriodicalTrigger.php:73-75`.
   The constructor rejects every interval that does not move time forward, so
   `$date > $now ? $date : null` can no longer return null. Kept deliberately:
   it encodes the `TriggerInterface` contract ("null = no future run") and is
   harmless. If the guarantee ever needs to be strict, the method could
   return `\DateTimeImmutable` unconditionally — but that would change the
   interface contract, so it was out of scope.

3. **PHP's `DateInterval` accepts per-component negative values in the
   relative-date-string format, and `DateTimeImmutable::add()` honours the
   `invert` flag** — verified empirically. `'-1 day +25 hours'` produces
   `d=-1, h=25` and moves the clock *forward* by one hour; a `DateInterval`
   with `invert = 1` moves the clock *backwards* under `add()`. This is why
   the validation is add-based rather than field-based. No code outside the
   constructor needed to know this.

## Out-of-scope observations

4. **`tests/Worker/SchedulerWorkerTest.php:602` — the sub-second test builds
   `new \DateInterval('PT0S'); $interval->f = 0.5`** — still passes with the
   new validation (add moves +0.5 s). No change needed. Worth knowing: this
   is the positive-interval edge case that a naive "all fields zero" check
   would almost have rejected if it forgot `f` — the provider in
   `tests/PeriodicalTriggerTest.php` does not re-test it because this test
   covers it.

5. **`SchedulerWorker` never logs why a trigger was rejected beyond
   `Trigger "<value>" is incorrect`** — `src/Worker/SchedulerWorker.php:91-94`
   catches `\InvalidArgumentException` and discards the message. For #667 the
   value is enough (`Trigger "PT0S" is incorrect`), but for genuinely cryptic
   intervals (e.g. a bad ISO string) including `$e->getMessage()` would make
   operator diagnosis much easier. Suggested fix (out of scope): log the
   exception message, e.g.
   `sprintf('... Trigger "%s" is incorrect: %s', $serviceConfig['schedule'], $e->getMessage())`.

6. **`PeriodicalTrigger` double-wraps `InvalidTriggerException` messages**
   — the outer `catch (\Throwable)` in `__construct` wraps the inner
   `InvalidTriggerException` into another one, so messages read
   `Invalid interval "PT0S": Interval must be a positive duration`. Purely
   cosmetic; pre-existing pattern (invalid strings already double-wrapped).

7. **No xdebug/pcov driver locally** — `composer test:coverage` /
   `coverage:check` cannot run on this machine; the 80% gate is CI-only
   here. All new code lines are covered by the added tests, so the floor
   should hold.
