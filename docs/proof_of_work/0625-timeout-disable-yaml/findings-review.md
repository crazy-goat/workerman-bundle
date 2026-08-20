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
  `testConfiguredTreeRejectsNegativeTimeouts` (ConfigurationTreeBuilderTest.php)
  asserting `connection_timeout: -1` / `keepalive_timeout: -1` raise
  `InvalidConfigurationException`; mirrors the
  `testConfiguredTreeValidatesRequiredServerName` pattern (FQCN,
  expectException before process). Verified: filtered run passes
  (4 tests, 12 assertions).

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
  remains bound as the global timer event after the test.

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
  before `restoreWorkerState`.
