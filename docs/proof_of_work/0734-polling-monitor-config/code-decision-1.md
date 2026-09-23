## Round 1 — #734

Wired two new `file_monitor` keys through the configuration tree, `Runner`, `FileMonitorWorker`, `FileMonitorWatcher::create()` and into `PollingMonitorWatcher`: `polling_interval` (default 3 s) and `max_files_per_tick` (default 500). Both are `integerNode(...)->min(1)`; the defaults live as public constants on `FileMonitorWatcher` (`DEFAULT_POLLING_INTERVAL`, `DEFAULT_MAX_FILES_PER_TICK`) so the config tree, the worker defaults and the watcher defaults share one source.

`PollingMonitorWatcher` takes the two values as promoted `readonly` constructor parameters and uses them for the repeat-timer interval and the per-tick budget, replacing the former `private const`. `FileMonitorWatcher::create()` passes them to the polling watcher only; the inotify watcher ignores them.

Rejected leaving the constants in place with a setter: a constructor parameter matches the existing `create()` factory and keeps the object immutable (the codebase favours `readonly`). Rejected validating in the watcher constructor: the config tree already enforces `min(1)` at the user boundary, and the reflection-based test fixtures build the object without the constructor.

`Runner` uses `?? DEFAULT` for the two keys to match the surrounding defensive style (`$config['connection_timeout'] ?? 120`) and to keep hand-built config arrays in tests working.
