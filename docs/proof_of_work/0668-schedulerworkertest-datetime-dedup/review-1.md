# Review — Round 1

**Branch:** `refactor/issue-668-schedulerworkertest-willreturn-datetimei`
**Issue:** #668

## Knowledge base check

Read `docs/helpers/faq.md` tag index, loaded entries matching `tests`, `scheduler`, `mocks`, `date-time`:
- **FAQ-022** — directly documents the `willReturn(new \DateTimeImmutable('+1 second'))` trap and recommends `willReturnCallback(fn(\DateTimeImmutable $now) => $now->modify('+1 second'))`. The change follows this entry exactly. ✅
- **FAQ-021** — sub-second `DateInterval` pitfalls, not relevant here.
- **FAQ-020** — scheduler test setup, not relevant here.

No `docs/helpers/decisions.md` entries violated.

## Findings

### Finding 1 — HIGH: Arrow functions missing return type declarations break `RectorConfigTest`

**File:** `tests/Worker/SchedulerWorkerTest.php:700, 739`
**What:** The two new arrow functions `fn(\DateTimeImmutable $now) => $now->modify('+1 second'))` lack explicit return type declarations. The project's Rector config includes `AddArrowFunctionReturnTypeRector`, which requires arrow functions to declare their return type. The `RectorConfigTest::testRectorDryRunPasses` test runs `rector process --dry-run` and asserts no changes are needed — with these untyped arrow functions, Rector reports changes and the test fails.
**Evidence:** On master (without the change), `RectorConfigTest` passes 7/7. With the change applied, 2 tests fail. The pre-existing arrow function in the same file (`fn(): KernelInterface => $kernel`) has a return type — the new ones are inconsistent with the file's own convention.
**Severity:** HIGH
**Automated check that could catch this:** `RectorConfigTest` (and `composer lint` which runs `rector --dry-run`). This is exactly the check that caught it.
**Status:** **FIXED** in round 1 — added `: \DateTimeImmutable` return type to both arrow functions. `RectorConfigTest` now passes (32/32 tests green).

### Verification of the two fixes

1. **`willReturnCallback` signature:** `TriggerInterface::getNextRunDate(\DateTimeImmutable $now): \DateTimeImmutable|null`. The closure `fn(\DateTimeImmutable $now): \DateTimeImmutable => $now->modify('+1 second')` returns `\DateTimeImmutable` (non-null), compatible with the interface return type. PHPUnit's `willReturnCallback` passes the actual call arguments to the closure, so `$now` receives the `\DateTimeImmutable` passed by `scheduleCallback`. ✅

2. **Unique service keys:** `ServiceMethod::toString()` returns `serviceId::method`. The `static $tickCallbacks` in `scheduleCallback` is method-local static — persists across calls within the same process. With unique keys (`args_test_service::__invoke` vs `callable_test_service::__invoke`), each test registers its own entry. The `nextRunDates` property is instance-level, reset per `new SchedulerWorker`. ✅

3. **No other tests affected:** grep confirms no remaining `willReturn(new \DateTimeImmutable('+1` patterns in `tests/`. No other test uses `test_service` as a SchedulerWorker service key. All 29 SchedulerWorkerTest tests pass. ✅

4. **Both issue #668 problems addressed:** the `willReturn` evaluation-once trap (fix 1) and the shared `test_service` key cross-test dedup (fix 2). ✅

## Summary

- 1 finding (HIGH) — **fixed** in this round
- No other findings
- Review is now **clean** after the fix
