# Review round 2 — #734

## Prior findings

Read `findings-review.md` round 1 entries and re-checked each:

- **F-1 (medium)** — fixed. `testStartSchedulesConfiguredPollingInterval` exercises `start()` and asserts the interval passed to `EventInterface::repeat()`; `testCreateWorkersForwardsFileMonitorPollingTuning` feeds non-default values through `Runner` and asserts the captured closure values. A revert of either point of use now fails a test.
- **F-2 (low)** — fixed. The constructor rejects `< 1` with `\InvalidArgumentException`; two tests cover both fields. The config tree's `min(1)` remains the user-facing gate.
- **F-3 (low)** — not changed, by design. Verified: no `static::create()` call exists (the single caller uses the explicit `FileMonitorWatcher::create(...)`), PHP does not enforce constructor signature compatibility, and no in-repo class overrides these signatures. Rationale recorded in `code-decision-2.md`.
- **F-4 (low)** — fixed. Boundary `1` accepted and passed through, asserted for both keys.
- **F-5 (nit)** — fixed. Stale constant/interval comments removed.

## New issues introduced by the fixes

- The new constructor guard throws before `parent::__construct`, so no partially constructed object escapes. Message names both values; no sensitive data.
- The `start()` test swaps three `Worker` statics (`$globalEvent`, `$outputStream`, `$logFile`) and the private `workers` list, all restored in `finally`; it closes the memory stream. It uses a real `Worker` because `Worker::log()` is static and cannot be mocked.
- The `Runner` forwarding test uses `array_diff_key` on the worker id map and restores prior worker state.

## Checks

- `vendor/bin/phpunit --no-coverage` on `PollingMonitorWatcherTest`, `PollingMonitorWatcherE2ETest`, `Reboot/FileMonitorWatcher/FileMonitorWatcherTest`, `Worker/FileMonitorWorkerTest`, `DependencyInjection/ConfigurationTreeBuilderTest`, `RunnerTest` — **OK (106 tests, 287 assertions)**.
- `composer lint` — php-cs-fixer 0 fixes, PHPStan level 8 OK, Rector OK, kb-lint OK (2 pre-existing budget warnings), check-changelog OK, check-exception-usage OK.
- `git diff --check` — clean.

## Findings

None open. No knowledge-base entry written: FAQ-035 is already satisfied by the new boundary test, and the two review candidates (BC of optional params; validate tunables in the runtime constructor) were considered — the second is now enforced by code, so it does not need an entry.
