# Review — #625 (allow disabling connection/keepalive timeouts via YAML) — round 2

Branch: `feat/issue-625-allow-disabling-connection-timeout-keepa`
Commits: `66b06af` (original) + `06841a5` (round-1 fixes) vs `master`

## Earlier findings — revisit pass

| ID | round-1 status | round-2 verdict | evidence |
|----|---------------|-----------------|----------|
| F-1 | fixed | **fixed** (with one residual nit — see F-4) | `testConfiguredTreeRejectsNegativeTimeouts` (ConfigurationTreeBuilderTest.php:131) feeds `connection_timeout: -1` + `keepalive_timeout: -1` through the Processor and expects `InvalidConfigurationException`. Verified passing (4 tests, 12 assertions in the filtered run). The test DOES pin the guard for `connection_timeout`: if `->min(0)` is dropped from that node, `-1` is accepted, no exception is thrown, and `expectException` fails. See F-4 for the partial-coverage gap on `keepalive_timeout`. |
| F-2 | fixed | **fixed** | `tests/RunnerTest.php:460-461` captures `Timer::$event` via `new \ReflectionProperty(Timer::class, 'event')` before `Timer::init`, and restores it in `finally` at L525 with `$timerEventRef->setValue(null, $savedTimerEvent)`. `getValue()` with no args on a static property returns the current value (null if uninitialized) — correct in PHP 8.5. `setValue(null, $value)` is the correct static-property restore form. Property name `event` matches `vendor/workerman/workerman/src/Timer.php:55` (`protected static ?EventInterface $event`). The restore ordering is correct: `Timer::delAll()` first (clears timers from the test loop), then restore `$event` (points Timer away from the stopped Select), then `restoreWorkerState` (restores `Worker::$globalEvent`). Full `RunnerTest` suite passes (36 tests, 89 assertions). |
| F-3 | fixed | **fixed** | `tests/RunnerTest.php:469-477` tracks `$openedStream = true` only when the test itself opened the `php://memory` stream (i.e., `Worker::$outputStream` was null). `finally` at L526-528 calls `fclose($stream)` only when `$openedStream` is true, so it never touches a pre-existing stream it didn't create. The `$stream` variable is only referenced inside the `if ($openedStream)` guard, so there's no undefined-variable risk when the stream was already set. |

## KB entries read (tag-index match)

- `decisions.md` → **DEC-003** (`timers,long-running`): one worker-level sweeper
  supersedes per-connection timers. The diff is consistent — `min(0)` exposes
  the runtime's already-existing `0`-disables path. No violation.
- `faq.md` → **FAQ-013** (`tests,timers`): initialize `Workerman\Timer` with
  the test event loop before calling `onWorkerStart`. The `RunnerTest` does
  `Timer::init($eventLoop)` before `$onWorkerStart($w)`. Consistent. The
  round-1 fix (F-2) now also restores `Timer::$event` in `finally`, going
  beyond FAQ-013's minimum — an improvement, not a violation.

## Round-1 fix verification (specific checks requested)

### Does the reject-side test actually pin the guard?

**Yes, for `connection_timeout`.** The Processor validates children in tree
definition order — `connection_timeout` (L75) is defined before
`keepalive_timeout` (L80). With both set to `-1`, `connection_timeout` is
validated first; if its `->min(0)` is dropped, `-1` passes, the Processor
moves to `keepalive_timeout`, which (if `min(0)` is still present) throws —
so the test still passes by accident. If BOTH `min(0)` calls are dropped,
no exception is thrown and `expectException` fails — the test catches the
"drop the guard entirely" regression. See F-4 for the "only one field
dropped" gap.

### Does the Timer::$event reflection restore introduce risk?

**Low risk, acceptable.** Two specific concerns checked:

1. **Property name coupling to Workerman internals:** The string `'event'`
   is hardcoded, matching `protected static ?EventInterface $event` at
   `vendor/workerman/workerman/src/Timer.php:55`. If Workerman renames this
   property, the test throws `ReflectionProperty::__construct()` →
   `ReflectionException` at setup. This is test-only coupling and the
   property name has been stable across Workerman versions. Acceptable for
   a test; would be a concern in production code.

2. **`getValue()` null case:** `$timerEventRef->getValue()` with no object
   argument reads a static property. If `Timer::$event` is null (never
   initialized or previously reset), it returns null. The restore then sets
   it back to null. Correct — no "uninitialized typed property" error
   because the property has a default of `null`.

### Test runs

All three requested command groups pass cleanly (no shutdown fatal
encountered this round):

| command | result | summary |
|---------|--------|---------|
| `php vendor/bin/phpunit --filter 'ZeroTimeouts\|NegativeTimeouts'` | passed | 4 tests, 12 assertions |
| `php vendor/bin/phpunit tests/RunnerTest.php` | passed | 36 tests, 89 assertions |
| `php vendor/bin/phpunit tests/DependencyInjection/ConfigurationTreeBuilderTest.php` | passed | 9 tests, 40 assertions |

## Verdict

**Clean.** The round-1 fixes are correct and well-targeted. F-1 (reject-side
test) is fixed and genuinely pins the guard against a "drop `min(0)` entirely"
regression. F-2 (Timer::$event restore) is correct with no risky edge cases.
F-3 (fclose stream) is correct with a clean opened-stream guard. All affected
test suites pass. One new nit (F-4) regarding partial coverage of the
reject-side test — not a blocker.

## New findings

| ID | file:line | severity | description |
|----|-----------|----------|-------------|
| F-4 | tests/DependencyInjection/ConfigurationTreeBuilderTest.php:143-144 | nit | The reject test processes `connection_timeout: -1` and `keepalive_timeout: -1` in a single `Processor::process()` call. Symfony's Processor throws on the first invalid child — `connection_timeout` (defined first in the tree) — so `keepalive_timeout`'s `min(0)` is never independently exercised. If a future edit drops `->min(0)` from only `keepalive_timeout`, this test still passes (because `connection_timeout` still throws). Two separate test cases (or two `process()` calls with one field negative at a time) would pin both fields independently. Low impact since both nodes are in the same diff and use the same constraint. |

## Candidate KB entries

- **Title:** Reject-side config tests must exercise each constrained node
  independently
- **Tags:** `config`, `tests`
- **Trigger:** writing a `expectException(InvalidConfigurationException)` test
  that feeds multiple constrained fields through a single `Processor::process()`
  call.
- **Paragraph:** Symfony's Config `Processor` throws on the first invalid child
  node, so a single `process()` call with two out-of-range fields only
  validates the first-declared node's bound. To pin each `->min()`/`->max()`
  independently, use separate test cases or separate `process()` calls with
  one field out-of-range at a time. #625's reject test feeds both
  `connection_timeout: -1` and `keepalive_timeout: -1` in one call; only
  `connection_timeout`'s `min(0)` is actually exercised because it is declared
  first in the tree.

## Gaps in validation / areas checked clean

- **Reflection property accessibility:** PHP 8.1+ made `setAccessible()` a
  no-op for all properties; the test never calls it and passes on PHP 8.5.
  No issue.
- **`$stream` undefined-variable risk:** `$stream` is only referenced inside
  `if ($openedStream)` in `finally`; when `$openedStream` is false, `$stream`
  was never assigned and is never accessed. No issue.
- **Timer::delAll() on an unstarted Select:** `delAll()` clears the internal
  timer array without needing the loop to have run. No issue.
- **Restore ordering in `finally`:** `Timer::delAll()` → restore `Timer::$event`
  → `fclose` → `restoreWorkerState` → `removeDir`. Each step is independent
  of the next; no ordering dependency violated.
- **Full diff re-inspected:** No changes beyond what was reviewed in round 1
  plus the three round-1 fixes. No scope creep.
