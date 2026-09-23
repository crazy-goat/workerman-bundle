## #734 implementation findings

- Biggest implementation obstacle: `PollingMonitorWatcher`'s tests and two fixture runners build the object with `newInstanceWithoutConstructor()` and set internal state by reflection. Promoting the two tuning values to `readonly` constructor parameters therefore required initializing them in every such fixture (`tests/PollingMonitorWatcherTest.php`, `tests/Reboot/FileMonitorWatcher/FileMonitorWatcherTest.php`, `tests/Fixtures/polling_watcher_mid_sweep_runner.php`, `tests/Fixtures/polling_watcher_e2e_runner.php`); leaving them as plain declared properties defaulted to the constants avoided that but Rector demanded constructor promotion.
- `Runner` reads the two keys with `?? DEFAULT` because several `RunnerTest` cases build `file_monitor` arrays by hand without the new keys.
- Config validation lives in the tree (`integerNode()->min(1)`), not the watcher constructor; direct construction with `0` is not guarded. That is deliberate (only the config path is user-facing) but is the one place a future caller could busy-loop.
- No additional out-of-scope bugs identified.
