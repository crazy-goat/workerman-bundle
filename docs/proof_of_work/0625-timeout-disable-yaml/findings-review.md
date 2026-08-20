# Findings (review) — #625 (allow disabling connection/keepalive timeouts via YAML)

One entry per finding. Status is `open` for round 1 unless noted.

---

### F-1
- **file:line:** tests/DependencyInjection/ConfigurationTreeBuilderTest.php (new `testConfiguredTreeAcceptsZeroTimeouts`, ~L129)
- **what is wrong:** No test pins that **negative** timeout values are still
  rejected after `min(1)→min(0)`. The new test only asserts `0` is accepted.
  A future edit dropping `->min(0)` (or widening it) would let negatives pass
  silently into a runtime that treats them as "disabled", eroding the
  config-level guard with no test failure. A
  `expectException(InvalidConfigurationException::class)` test for
  `connection_timeout: -1` would mirror the existing
  `testConfiguredTreeValidatesRequiredServerName` pattern.
- **severity:** low
- **status:** fixed (round 1)
- **what happened to it:** Fixed by main session — added
  `testConfiguredTreeRejectsNegativeTimeouts` (ConfigurationTreeBuilderTest.php:131)
  asserting `connection_timeout: -1` / `keepalive_timeout: -1` raise
  `InvalidConfigurationException`; mirrors the
  `testConfiguredTreeValidatesRequiredServerName` pattern (FQCN,
  expectException before process). Verified round 2: filtered run passes
  (4 tests, 12 assertions). The test pins the guard for `connection_timeout`
  — if `->min(0)` is dropped from that node, `-1` is accepted, no exception
  thrown, `expectException` fails. See F-4 for the partial-coverage gap on
  `keepalive_timeout`.
  Verified round 3: the test is now a `@dataProvider` with two cases, each
  exercising one field independently. 10 tests, 42 assertions pass.

---

### F-2
- **file:line:** tests/RunnerTest.php:461,520
- **what is wrong:** `Timer::init($eventLoop)` binds `Workerman\Timer::$event`
  to the `Select` loop, but the `finally` only calls `Timer::delAll()` —
  `Timer::$event` is never restored to the saved state.
  `saveWorkerState`/`restoreWorkerState` covers `Worker::$globalEvent` but
  not `Timer::$event`, so Timer stays bound to a stopped `Select` loop for any
  later test that doesn't re-init. No failure observed (full `RunnerTest` +
  `ServerWorkerTest` suites pass), and this matches the existing pattern in
  `ServerWorkerTest`'s timer tests, so it is a latent state-leak rather than a
  flake today.
- **severity:** nit
- **status:** fixed (round 1)
- **what happened to it:** Fixed by main session — the test now captures
  `Timer::$event` via reflection before `Timer::init` and restores it in
  `finally` after `Timer::delAll()`, so the stopped `Select` loop no longer
  remains bound as the global timer event after the test. Verified round 2:
  full `RunnerTest` passes (36 tests, 89 assertions). Reflection usage is
  correct: `getValue()` reads the static property (null-safe),
  `setValue(null, $saved)` restores it. Property name `'event'` matches
  `vendor/workerman/workerman/src/Timer.php:55`. Test-only coupling to
  Workerman internals is acceptable.
  Verified round 3: no changes to `tests/RunnerTest.php` since round 2.
  Unchanged, still correct.

---

### F-3
- **file:line:** tests/RunnerTest.php:471-472
- **what is wrong:** The `fopen('php://memory', 'w')` stream assigned to
  `Worker::$outputStream` is never `fclose`'d; `restoreWorkerState` orphans
  it. Collected at EOT, harmless, but a `fclose` in `finally` (only when the
  saved state was `null`) would be tidier.
- **severity:** nit
- **status:** fixed (round 1)
- **what happened to it:** Fixed by main session — the test now tracks whether
  it opened the `php://memory` stream (`$openedStream` flag, only when the
  saved `Worker::$outputStream` was null) and `fclose()`s it in `finally`
  before `restoreWorkerState`. Verified round 2: the `$openedStream` guard
  prevents accessing an undefined `$stream` variable when the stream was
  pre-existing. Correct and clean.
  Verified round 3: no changes to `tests/RunnerTest.php` since round 2.
  Unchanged, still correct.

---

### F-4
- **file:line:** tests/DependencyInjection/ConfigurationTreeBuilderTest.php:143-144
- **what is wrong:** The reject test (`testConfiguredTreeRejectsNegativeTimeouts`)
  processes `connection_timeout: -1` and `keepalive_timeout: -1` in a single
  `Processor::process()` call. Symfony's Processor throws on the first invalid
  child — `connection_timeout` (declared first in the tree at
  `ConfigurationTreeBuilder.php:75`) — so `keepalive_timeout`'s `min(0)` is
  never independently exercised. If a future edit drops `->min(0)` from only
  `keepalive_timeout`, this test still passes because `connection_timeout`
  still throws. Two separate test cases (or two `process()` calls with one
  field negative at a time) would pin both fields independently.
- **severity:** nit
- **status:** fixed
- **what happened to it:** New finding in round 2. Fixed by main session —
  converted the test to a data provider
  (`provideNegativeTimeoutOverrides`, one field negative at a time), so each
  node's `min(0)` is exercised independently (expected exception is thrown by
  whichever node is invalid). Verified: 10 tests, 42 assertions pass.
  Verified round 3: the data provider genuinely exercises each node
  independently. Case `connection_timeout`: only `connection_timeout` is -1,
  `keepalive_timeout` uses default (30) — throws because of
  `connection_timeout`'s `min(0)`. Case `keepalive_timeout`: only
  `keepalive_timeout` is -1, `connection_timeout` uses default (120) — throws
  because of `keepalive_timeout`'s `min(0)`. All regression scenarios (drop
  one or both `min(0)` calls) are caught. PHPStan clean on the test file.
  The `@param array<string, int> $override` phpdoc (commit `2bec127`) resolves
  PHPStan's `missingType.iterableValue` diagnostic. Fix is correct and
  complete.
