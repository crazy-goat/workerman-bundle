# Findings review — #734 polling monitor config

Round 1. `findings-coder.md` had no open issue list; its three observations were re-checked and
the "direct construction with 0 is not guarded" one is recorded below as F-2. No prior
`findings-review.md` existed, so nothing was deleted.

| id | file:line | what is wrong | severity | status |
|----|-----------|---------------|----------|--------|
| F-1 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:42; src/Runner.php:295-298 | The configurable `pollingInterval` is never asserted where it is consumed (`start()`'s `repeat()`), and `Runner`'s forwarding of both new values is only exercised through the `?? DEFAULT` fallback. On inotify-enabled CI `PollingMonitorWatcher::start()` is not executed at all, so a revert to the constant would stay green. | medium | open (new) |
| F-2 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:29-37; src/Reboot/FileMonitorWatcher/FileMonitorWatcher.php:28-39; src/Worker/FileMonitorWorker.php:18-25 | New public numeric params are unvalidated outside the config tree: `pollingInterval <= 0` hot-loops `Workerman\Events\Select::repeat()` (Select.php:163-173), `maxFilesPerTick <= 0` returns before `next()` and the sweep never advances. YAML path is protected by `->min(1)`. | low | open (new; acknowledged in findings-coder.md:5) |
| F-3 | src/Reboot/FileMonitorWatcher/FileMonitorWatcher.php:28-39; src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:29-37 | Adding optional params to non-final public `create()` / adding a 5-arg `PollingMonitorWatcher::__construct()` breaks downstream subclasses that override with the old 3-arg signature (verified PHP fatal "must be compatible"). No in-repo subclass affected; undocumented extension point. | low | open (new) |
| F-4 | tests/DependencyInjection/ConfigurationTreeBuilderTest.php:207-230 (provider :174-180) | FAQ-035: reject side pins `0`/`-1` one field per call, but the accepted boundary value `1` is never asserted (accept test uses 10/250). | low | open (new) |
| F-5 | src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:75; tests/Fixtures/polling_watcher_mid_sweep_runner.php:39; tests/PollingMonitorWatcherTest.php:373,460,489 | Stale comments still reference the removed `MAX_FILES_PER_TICK` constant name and a hardcoded 3 s interval. | nit | open (new) |

No findings from previous rounds.

## Round 2 resolutions

| id | status | evidence |
|----|--------|----------|
| F-1 | fixed | `tests/PollingMonitorWatcherTest::testStartSchedulesConfiguredPollingInterval` asserts `repeat(9, …)`; `tests/RunnerTest::testCreateWorkersForwardsFileMonitorPollingTuning` asserts the closure captures `7`/`250`. |
| F-2 | fixed | Constructor now throws `\InvalidArgumentException` for `< 1` on either value; `testConstructorRejectsNonPositivePollingTuning` and `testConstructorRejectsNonPositiveMaxFilesPerTick`. Config tree `min(1)` unchanged. |
| F-3 | not changed (low, by design) | No `static::` dispatch on `create()`; constructors are exempt from PHP signature checks; no in-repo subclass. Documented in `code-decision-2.md`. |
| F-4 | fixed | `testConfiguredTreeAcceptsFileMonitorPollingBoundaryOne` pins `1` accepted for both keys. |
| F-5 | fixed | Stale `MAX_FILES_PER_TICK` / "3 s" references updated in src and tests. |
