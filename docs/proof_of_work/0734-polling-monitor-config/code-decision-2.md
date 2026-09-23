## Round 2 — #734 (finding fixes)

F-1 (medium, fixed): added `testStartSchedulesConfiguredPollingInterval` in `tests/PollingMonitorWatcherTest.php`, which stubs `Worker::$globalEvent` with an `EventInterface` mock and asserts `repeat()` is called with the configured interval (`9`). Also added `RunnerTest::testCreateWorkersForwardsFileMonitorPollingTuning`, which passes non-default `polling_interval`/`max_files_per_tick` and asserts the new `FileMonitorWorker`'s `onWorkerStart` closure captured `7` and `250`. The old `start()`-reverts-to-constant regression would now fail.

F-2 (low, fixed): added a constructor guard in `PollingMonitorWatcher` rejecting `< 1` for either value with `\InvalidArgumentException`, plus two tests. The config tree keeps its `min(1)`. The reflection fixtures bypass the constructor, so they are unaffected.

F-3 (low, deliberately not changed): `FileMonitorWatcher::create()` is called with an explicit class name in its only caller (`FileMonitorWatcher::create(...)`, no `static::` late binding), and PHP exempts constructors from signature-compatibility checks; no in-repo subclass overrides either signature. Adding params with defaults is the minimal caller-safe change. Making `create()` `final` would itself be a BC break. A BC note is not warranted for an internal, undocumented extension point.

F-4 (low, fixed): added `testConfiguredTreeAcceptsFileMonitorPollingBoundaryOne`, pinning `1` as accepted and passed through unchanged for both keys.

F-5 (nit, fixed): updated the stale comments/assertion messages referencing `MAX_FILES_PER_TICK` and the hardcoded 3 s interval in `PollingMonitorWatcher`, `tests/Fixtures/polling_watcher_mid_sweep_runner.php` and `tests/PollingMonitorWatcherTest.php`.

No finding was left unanswered, and no gate was lowered.
