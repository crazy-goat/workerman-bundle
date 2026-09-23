# Review round 1 — #734 `file_monitor.polling_interval` / `max_files_per_tick`

## 0. Trigger

Review-critical applies. Two criteria matched:

- **> 200 changed lines** — `git diff` reports 210 insertions + 13 deletions = **223 changed lines**.
- **public interface** — the diff widens two public signatures: `FileMonitorWatcher::create()`
  (3 optional params added) and `PollingMonitorWatcher::__construct()` (new public constructor
  with promoted params). `FileMonitorWorker::__construct()` gains two optional params as well
  (the class is `final readonly`, so only callers are affected).

Not triggered: `src/Http`, security policy, process supervision (fork/signal/master identification),
`.github/workflows/*`.

Reviewed as a working-tree diff: `git log --oneline master..HEAD` is empty (branch is at `4a68d1d`,
same as `master`); the change is unstaged. `docs/proof_of_work/0734-polling-monitor-config/findings-review.md`
did not exist before this round (round 1).

## 1. docs/helpers

Tag index loaded, entries read: FAQ-002 (`bc`), FAQ-005 (`config-cache`), FAQ-006 (`inotify`),
FAQ-018 (`long-running`), FAQ-024 (`config`), FAQ-029 (`phpstan`), FAQ-034 (`yaml`), FAQ-035
(`config`/`symfony-config`), FAQ-036 (`config-cache`/`runner`), FAQ-037 (`php82`), FAQ-038
(`reflection`/`tests`); DEC-003 (`timers`), DEC-014 (`long-running`/`tests`), DEC-016
(`security`/`config-cache`), DEC-021 (`changelog`).

No documented decision is violated. Notes:

- **DEC-021** — CHANGELOG entry is present and `check-changelog` passes.
- **DEC-016 / DEC-006** — no hardening is loosened; the new nodes do not create a security opt-out.
- **FAQ-035** — the reject side is correct (one field per `process()` call via a data provider, so
  both `min(1)` nodes are exercised independently). The accept side is in-range but does **not**
  pin the boundary value `1` (finding F-4).
- **FAQ-037** — no PHP >= 8.3 syntax introduced (promoted `readonly` params are 8.1; `self::`
  constants as default values are fine on 8.2).
- **FAQ-038** — the reflection fixtures follow the established declaring-class-scope pattern;
  see F-2 for the value-validation angle.

## 2. Earlier findings

`docs/proof_of_work/0734-polling-monitor-config/findings-review.md` did not exist. `findings-coder.md`
lists three implementation observations, all acknowledged and re-checked here; the "direct
construction with `0` is not guarded" observation becomes finding F-3.

## 3. Commands run

```
vendor/bin/phpunit --no-coverage tests/PollingMonitorWatcherTest.php \
  tests/PollingMonitorWatcherE2ETest.php \
  tests/Reboot/FileMonitorWatcher/FileMonitorWatcherTest.php \
  tests/Worker/FileMonitorWorkerTest.php \
  tests/DependencyInjection/ConfigurationTreeBuilderTest.php \
  tests/RunnerTest.php
=> OK (101 tests, 275 assertions)

composer lint
=> php-cs-fixer: 0 of 259 files to fix
   phpstan level 8: OK, no errors
   rector --dry-run: OK
   kb-lint: 59 entries, 0 stale (2 pre-existing over-budget warnings)
   check-changelog: OK
   check-exception-usage: OK
```

## 4. What is correct

- **Config placement/types**: `polling_interval` and `max_files_per_tick` are two distinct
  `integerNode`s inside `configureFileMonitorStrategy()` after `file_pattern`
  (`ConfigurationTreeBuilder.php:260-269`); no duplicate or misplaced node. Both `->min(1)`.
- **Defaults**: `3` / `500` exactly match the removed `PollingMonitorWatcher::POLLING_INTERVAL` /
  `MAX_FILES_PER_TICK`; they live once on `FileMonitorWatcher` (`:12-16`) and are referenced by the
  config tree, `FileMonitorWorker`, `create()` and the promoted constructor params, so there is no
  drift between config default and runtime default. The default assertions pin both.
- **Wiring**: `Runner.php:290-299` -> `FileMonitorWorker` closure (:32-35) -> `create()`
  (:35-38) -> `PollingMonitorWatcher` (`:33-34`, used at `:42` and `:67`). `Runner`'s `?? DEFAULT`
  keeps hand-built config arrays and stale config caches working.
- **Inotify ignores the polling-only values**: `create()` passes the two ints only in the
  `else` branch; `InotifyMonitorWatcher` is constructed with the original three args.
- **No file skips / no off-by-one**: with `maxFilesPerTick = N`, each tick processes exactly N
  entries and returns on entry N+1 *before* `next()`, so the resume point is neither skipped nor
  processed twice. `min(1)` guarantees forward progress for config users.
- **Reflection fixtures**: the promoted `readonly` params are initialized in all four
  `newInstanceWithoutConstructor()` sites (`tests/PollingMonitorWatcherTest.php:69-70`,
  `tests/Reboot/FileMonitorWatcher/FileMonitorWatcherTest.php:224-225`,
  both `tests/Fixtures/polling_watcher_*_runner.php`), each via the declaring class's
  `ReflectionProperty`, matching the existing scope workaround documented at
  `tests/PollingMonitorWatcherTest.php:79-104`.
- **Docs**: README YAML example and the two reference-table rows, the `WorkermanBundle` array-shape
  phpdoc and the CHANGELOG `### Added` entry are all present and consistent.

## 5. Findings

### F-1 (medium) — The configurable interval is never asserted at its point of use; `Runner` forwarding is only exercised through the `?? DEFAULT` path

`src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:42` is the only place `pollingInterval`
is consumed (`$globalEvent?->repeat($this->pollingInterval, ...)`). No test calls
`PollingMonitorWatcher::start()`. `testConstructorStoresConfigurablePollingTuning`
(`tests/PollingMonitorWatcherTest.php:316-325`) reads the promoted property only. On an
inotify-enabled CI host (Linux) `FileMonitorWorkerTest::testOnWorkerStartCreatesCorrectWatcherBasedOnExtension`
takes the inotify branch, so line 42 is not executed there either. A regression that reverted
`start()` to the constant would keep the whole suite green.

Likewise `src/Runner.php:295-298` is fed non-default values by no test: every `RunnerTest`
`file_monitor` array omits the new keys (`tests/RunnerTest.php:767-772, 800-805, 836-841`), so only
the `?? FileMonitorWatcher::DEFAULT_*` fallback runs. `max_files_per_tick` is covered at the watcher
level (`testConfigurableMaxFilesPerTickBoundsTheSweep`), `polling_interval` is not covered anywhere.

Suggested: a `start()` test with a stub `EventInterface` capturing `repeat()`'s interval — the exact
precedent exists at `tests/HttpRequestHandlerTest.php:183-202` — plus a `RunnerTest` case asserting
`pollingInterval`/`maxFilesPerTick` in the `FileMonitorWorker` closure's static vars (as
`tests/Worker/FileMonitorWorkerTest.php:87-97` already does for the worker itself).
Automated check that *could* have caught it: clover line coverage would flag `start()` as uncovered
on the Linux CI leg, but not the value wiring; neither check exists until the test is written.

### F-2 (low) — The new public numeric parameters are unvalidated outside the config tree: `0`/negative can hot-loop or stall the sweep

`PollingMonitorWatcher::__construct` (`src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:29-37`),
`FileMonitorWatcher::create()` (`src/Reboot/FileMonitorWatcher/FileMonitorWatcher.php:28-39`) and
`FileMonitorWorker::__construct()` (`src/Worker/FileMonitorWorker.php:18-25`) accept any `int`.
The YAML boundary is guarded by `->min(1)`, but direct callers of the now-public constructor/factory
are not:

- `pollingInterval <= 0` -> `Workerman\Events\Select::repeat()` computes `runTime = microtime(true) + 0`
  (`vendor/workerman/workerman/src/Events/Select.php:163-173`), i.e. the callback runs every event-loop
  iteration — a CPU hot loop.
- `maxFilesPerTick <= 0` -> `checkFileSystemChanges()` increments to `1` and returns at
  `1 > 0` before `$iterator->next()` (`:66-69`), so the iterator never advances and changes are
  never detected (silent livelock).

The coder's `findings-coder.md:5` acknowledges this as deliberate. It is consistent with the repo's
"config tree is the validation boundary" style, so this is a low-severity hardening note rather than
a defect in the shipped YAML path. A one-line `if ($pollingInterval < 1 || $maxFilesPerTick < 1) throw
new \InvalidArgumentException(...)` in the watcher constructor would close it (the reflection fixtures
bypass the constructor, so they are unaffected). Automated check: none — PHPStan cannot express a
runtime `int<1, max>` guard from a docblock.

### F-3 (low) — Widening `FileMonitorWatcher::create()` / adding `PollingMonitorWatcher::__construct()` breaks child overrides with the old signature

Adding optional params to a non-`final` public method is caller-safe but not subclass-safe. Verified
on this host: a child `create($a, $b, $c)` against the new parent `create($a, $b, $c, $d = 3, $e = 500)`
is a hard `Fatal error: Declaration of B::create(...) must be compatible with A::create(...)` at
class declaration. Same for a `PollingMonitorWatcher` subclass that overrides the old 3-arg
`__construct`. No in-repo subclass is affected (`CountingPollingMonitorWatcher` declares no
constructor), and `create()`/the watcher constructors are undocumented extension points, so the
practical risk is low. Mitigation if these are considered public API: mention the signature change in
`UPGRADE.md`/CHANGELOG, or make `create()` `final` (which would itself be a break — so a doc note is
safer). Automated check: `roave/backward-compatibility-check` would catch it; it is not installed here.

### F-4 (low) — FAQ-035: the accepted-boundary value `1` is not pinned

`provideInvalidFileMonitorPollingOverrides` (`tests/DependencyInjection/ConfigurationTreeBuilderTest.php:174-180`)
correctly pins the reject side (`0`, `-1`, one field per `process()` call). The accept test
(`:207-230`) uses `10` / `250`, not the boundary `1`. Per FAQ-035 the new boundary value should be
asserted accepted and passed through unchanged; a later edit that drops `->min(1)` still fails on the
`0` reject case, but the exact boundary is untested. Add one case (`polling_interval: 1`) or change
the accept test to `1`. Automated check: none.

### F-5 (nit) — Stale comments still reference the removed hardcoded constants

- `src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php:75` — `(3 s polling interval)` is no longer
  true; the interval is configurable.
- `tests/Fixtures/polling_watcher_mid_sweep_runner.php:39` — `MAX_FILES_PER_TICK=500` uses the removed
  constant name (now `DEFAULT_MAX_FILES_PER_TICK`).
- `tests/PollingMonitorWatcherTest.php:373` (and `:460`, `:489`) — same old constant name in assertions
  and comments.

Automated check: none.

## 6. Candidate docs/helpers entries (proposed, not written)

1. **Title:** "Adding optional parameters to a non-final public method/constructor is a BC break for
   child overrides"
   **Tags:** `bc`, `php`, `config`
   **Trigger:** "adding optional parameters to a public method or constructor that is not `final`, or
   reviewing a public factory signature change"
   **Paragraph:** Callers are unaffected, but any existing subclass that overrides the method with the
   old parameter list now fails to load with a fatal "Declaration ... must be compatible" — verified
   while reviewing #734 (`FileMonitorWatcher::create()`, `PollingMonitorWatcher::__construct()`).
   Treat such a change as BC-relevant for the changelog/UPGRADE, or keep the signature stable by
   passing a small options value object.

2. **Title:** "Config-tree `min(1)` does not protect programmatic callers — validate numeric tunables
   in the runtime constructor too"
   **Tags:** `config`, `validation`, `long-running`
   **Trigger:** "wiring a new numeric config value into a public constructor/factory"
   **Paragraph:** #734's `polling_interval`/`max_files_per_tick` are guarded by `->min(1)` for YAML
   users, but a direct `new PollingMonitorWatcher(..., 0, ...)` hot-loops the event timer and
   `maxFilesPerTick <= 0` stalls the sweep before it advances. When a value feeds a timer/loop bound,
   add the same guard in the runtime constructor so the invariant does not depend on which entry point
   constructed the object.

## 7. Verdict

No high-severity issue. The feature is correctly wired and the config defaults match the removed
constants. The main gap is test coverage of the new interval's point of use (F-1). Findings F-2..F-5
are low/nit hardening, BC-documentation and comment-hygiene items.
