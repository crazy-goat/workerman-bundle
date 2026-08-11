# Code decision — round 1 (issue #667)

## Problem

`PeriodicalTrigger` accepted zero or negative intervals without validation,
but `getNextRunDate()` only returns a future date when the interval is
positive (`$date > $now ? $date : null`). A trigger like `PeriodicalTrigger(0)`,
`'PT0S'`, `'0 seconds'` or a negative relative string therefore returned
`null` forever: the task was never scheduled, while the startup log still
printed `Task "..." scheduled. Trigger: "every 0"`.

## Approach taken

Reject non-positive intervals in `PeriodicalTrigger::__construct()` with the
existing `InvalidTriggerException` (in `src/Exception/`, extends
`SchedulerException` → `\InvalidArgumentException`), so:

- misconfiguration fails fast at startup (the daemon logs
  `Task "..." skipped. Trigger "..." is incorrect` via the existing
  `catch (\InvalidArgumentException)` in `SchedulerWorker::onWorkerStart`
  instead of producing a dead task), and
- the misleading "scheduled" log line is never reached for such config.

### The positivity check

The constructor normalizes every input form (int / ISO 8601 `P…` string /
relative date string / `\DateInterval` instance) to a single `\DateInterval`
first, then validates it once:

```php
$now = new \DateTimeImmutable();
if ($now->add($dateInterval) <= $now) {
    throw new InvalidTriggerException('Interval must be a positive duration');
}
```

This is deliberately the *same predicate* `getNextRunDate()` uses
(`$date > $now`), evaluated at construction time. Verified empirically before
writing the check:

| input | fields | `add()` result |
|---|---|---|
| `0` / `'0 seconds'` / `'PT0S'` | all zero | `== now` (was: `null` forever) |
| `-5` / `'-1 second'` | `s = -5` / `s = -1` | `before now` (was: `null` forever) |
| `'-1 day +25 hours'` | `d = -1, h = 25` | `+1h` (moves forward — valid!) |
| `DateInterval` with `invert = 1` | invert flag | `before now` (was: `null` forever) |
| `'PT0S'` + `f = 0.5` | `f = 0.5` | `+0.5s` (valid — sub-second support, #565) |

A field-wise check (`any of y/m/d/h/i/s/f > 0`) would have wrongly rejected
the mixed-sign `'-1 day +25 hours'` case (negative day, positive hour,
net forward), and a naive "reject `invert`" check would have been redundant.
The add-based check is exactly "the interval moves the clock forward", which
is the only thing `getNextRunDate` can honour.

### Why no change in `TriggerFactory` / `SchedulerWorker`

- `TriggerFactory::create()` routes every non-cron, non-datetime expression to
  `new PeriodicalTrigger(...)`, so constructor validation covers the factory
  automatically — no factory-level check needed (added a regression test
  there instead).
- `SchedulerWorker::scheduleCallback()`'s silent `return` on a `null` trigger
  was considered for a warning log and deliberately **not** changed:
  `DateTimeTrigger` legitimately returns `null` after its one-shot run (and
  `scheduleCallback` is re-invoked after every completed run via
  `handleParent`), so a warning there would log noise after every completed
  one-shot task. With the constructor fix, `PeriodicalTrigger` can never
  return `null`, so the misleading "scheduled" log for periodical tasks is
  gone through fail-fast rejection instead.

## What I rejected, and why

1. **Warning log in `scheduleCallback()` on null** — noise for one-shot
   tasks (see above); the issue marked it optional.
2. **Field-wise positivity check** — wrong for mixed-sign intervals.
3. **Validating in `TriggerFactory`** — duplicate; constructor is the single
   convergence point.
4. **Removing the `$date > $now` guard in `getNextRunDate()`** — it is now
   unreachable for constructed instances but harmless, documents the
   `TriggerInterface` contract (null = "no future run"), and costs nothing.
   Minimal diff won.

## What I was unsure about

- **Message wording**: the existing constructor wraps every inner exception
  into `Invalid interval "<original>": <message>`, so the final message for
  `0` reads `Invalid interval "0": Interval must be a positive duration`.
  Slightly redundant, but consistent with the existing double-wrap pattern
  (e.g. invalid strings already produce a doubled message).
- **Where to throw**: inside the existing `try` (wrapped by the catch) vs.
  after it. Chose inside — one validation site, uniform wrapping.

## Tests added

- `tests/PeriodicalTriggerTest.php` — provider-driven
  `testNonPositiveIntervalThrowsException` covering int 0, negative int,
  `'PT0S'`, `'0 seconds'`, `'-1 second'`, zero `DateInterval`, inverted
  `DateInterval`.
- `tests/TriggerFactoryTest.php` — `testZeroIntervalThrowsException`
  (`TriggerFactory::create(0)`).
- `tests/Worker/SchedulerWorkerTest.php` —
  `testZeroIntervalTaskIsSkippedWithIncorrectTriggerLog` asserting the task
  is skipped, `Trigger "PT0S" is incorrect` is logged, and **no**
  "scheduled" line is printed. First version used `schedule => 0`, which
  turned out to be caught earlier by `empty($serviceConfig['schedule'])`
  ("Trigger has not been set") — an interesting pre-existing safety net —
  so the test uses `'PT0S'` to exercise the constructor path.

## Validation

- Targeted PHPUnit (Periodical/TriggerFactory/Jitter/DateTime/Cron triggers +
  SchedulerWorkerTest): 106 tests, 466 assertions, OK.
- `composer lint` (php-cs-fixer dry-run, PHPStan level 8, Rector dry-run,
  kb-lint): all OK.
- `composer test` (real daemons on 8888/9999): 1938 tests, 0 failures,
  31 pre-existing skips.
- `composer test:coverage` not runnable locally (no xdebug/pcov driver); the
  80% coverage gate runs in CI. All new lines are exercised by the new tests.
