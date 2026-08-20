# Code decision 1 — make `connection_timeout`/`keepalive_timeout: 0` reachable from YAML (#625)

## Approach taken

**Option 1 from the issue (relax the config), the preferred direction.**

- `src/DependencyInjection/ConfigurationTreeBuilder.php`: `->min(1)` → `->min(0)`
  on both `connection_timeout` and `keepalive_timeout`, and the `->info()`
  strings now state "Set to 0 to disable the timeout."
- Docs: README.md config-table rows and docs/security.md `Connection Timeouts`
  section state that `0` disables the timeout.
- CHANGELOG: new `### Added` entry for #625; the #555 entry's trailing note
  ("the YAML configuration still enforces a minimum of 1 second") was updated
  because it ships in the same Unreleased release and would otherwise be false.
- Tests:
  - `ConfigurationTreeBuilderTest::testConfiguredTreeAcceptsZeroTimeouts`
    — `Processor::process()` accepts `connection_timeout: 0` /
    `keepalive_timeout: 0` and keeps the values (`0`, `0`). Follows the exact
    pattern of the existing `testConfiguredTreeParsesConnectionTimeouts`.
  - `RunnerTest::testConfigWithZeroTimeoutsDisablesSweeper` — end-to-end
    flow-through at unit level: config with zeros → `Runner::createWorkers()`
    → the created `[Server]` worker's `onWorkerStart` runs on a real `Select`
    event loop → asserts `getTimerCount() === 0` (no sweeper armed).

## Why the flow-through test boots onWorkerStart with a real event loop

The Sweeper is only armed inside `onWorkerStart` (ServerWorker.php:100-127,
`array_filter(..., $timeout > 0)` then `Timer::add(...)`). A Runner-level test
that only checks "worker created without exception" would not prove the
"disabled" semantics — the existing `testConfigSetsConnectionTimeouts` already
covers plain creation. Booting `onWorkerStart` with `Timer::init(new Select())`
mirrors `ServerWorkerTest::createStartedWorkerForTimerTests()` and makes the
assertion real: with both timeouts 0 the timer count stays 0, i.e. the
runtime's disabled semantics (already pinned by
`ServerWorkerTest::testZeroTimeoutsDoNotArmSweeper` for direct construction)
is now reachable through the config path.

## Rejected alternatives

- **Option 2 (docs only)** — rejected because the issue itself marks option 1
  as preferred ("if disabling is intended"), the runtime explicitly supports
  `0` = disabled (a deliberate fix in #555), and keeping `min(1)` would leave
  a documented-but-absent capability. The maintainers can still revert if
  disabling is actually unwanted — the diff is tiny and self-contained.
- **Adding a ServerWorker-level test** — not needed: `testZeroTimeoutsDoNotArmSweeper`
  (0,0) and `testKeepaliveTimeoutZeroKeepsOnlySweeperTimer` (5,0) already pin
  the runtime semantics for direct construction. The gap was purely the config
  gate and the wiring, which the two added tests cover.
- **Verifying negative values** — `min(0)` still rejects negatives; the
  runtime's `> 0` checks would treat them as disabled, but allowing them via
  config would be a useless ambiguity. Kept `min(0)`.

## Things I was unsure about

- **Editing the #555 CHANGELOG note.** Historical changelog entries are
  usually left untouched, but both entries are in the same `[Unreleased]`
  release, and leaving "the YAML configuration still enforces a minimum of 1
  second" next to a new entry saying the opposite would ship a contradictory
  changelog. The note is rewritten to point at #625 instead.
- **Fixing the stale "Timers are per-connection" bullet in docs/security.md.**
  It is technically outside the two-line doc ask, but it sits in the exact
  section being edited and directly contradicts the sweeper semantics that
  the new `0`-documentation builds on (see findings-coder.md).
- **Scope of the Runner flow-through test**: it re-uses the container/handler
  mock boilerplate from ServerWorkerTest. It needs `Worker::$outputStream`
  (memory stream) and `Worker::$logFile` (temp file) set because
  `onWorkerStart` calls `$worker->log()`, and Workerman's log path
  `file_put_contents('')` throws a ValueError when `logFile` is the default
  `''` in unit-test context. Both are saved/restored via
  `saveWorkerState()`/`restoreWorkerState()`.
