# Code Decision — Round 1

**Issue:** #668 — SchedulerWorkerTest: `willReturn(DateTimeImmutable('+1 second'))` evaluates once and shared `test_service` key causes static `$tickCallbacks` cross-test deduplication

## Approach

Two minimal, surgical fixes in `tests/Worker/SchedulerWorkerTest.php`:

### Fix 1: `willReturn(new \DateTimeImmutable('+1 second'))` → `willReturnCallback`

Both `testScheduleCallbackPassesArgsToDelay` (line ~686) and
`testScheduleCallbackUsesFirstClassCallable` (line ~725) used
`->willReturn(new \DateTimeImmutable('+1 second'))`, which evaluates the
relative string **once** at stub-configuration time. The resulting fixed
absolute date was returned on every call — a trap already documented in
`docs/helpers/faq.md` (FAQ-022, from issue #565 work).

Replaced with the recommended pattern:
```php
->willReturnCallback(fn(\DateTimeImmutable $now) => $now->modify('+1 second'))
```

This makes the return value relative to the injected `$now` argument on each
call, matching the real `getNextRunDate(\DateTimeImmutable $now)` signature.

### Fix 2: Shared `test_service` key → unique per-test service keys

Both tests used `new ServiceMethod('test_service', '__invoke')`. Since
`SchedulerWorker::scheduleCallback()` uses a `static $tickCallbacks = []`
deduplicated by `$service->toString()` (i.e. `'serviceId::method'`), the
second test reused the first test's recorded closure/args instead of
registering its own. Currently harmless (the second test doesn't assert on
args), but order-dependent.

Renamed to unique keys matching the convention already used by the newer
tests in the same file (`cadence_test_service`, `lock_test_service`,
`subsecond_test_service`):
- `testScheduleCallbackPassesArgsToDelay` → `args_test_service`
- `testScheduleCallbackUsesFirstClassCallable` → `callable_test_service`

## What was rejected

- **Clearing `static $tickCallbacks` between tests**: rejected — the static
  is inside a private method and not directly resettable without reflection
  hacks. Using unique keys is the convention the rest of the file already
  follows.
- **Adding assertions to catch the cross-test dedup**: rejected — the
  fundamental fix (unique keys) eliminates the class of bug; adding
  order-dependent assertions would be testing the bug, not the behavior.

## What was uncertain

Nothing. Both fixes are directly prescribed by the issue body and the
existing FAQ-022 entry.
