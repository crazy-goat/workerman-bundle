# Code Decision — Round 1 — Issue #562

## Approach taken
Removed `needsPeakMemory()` entirely (Option B) rather than repurposing `MemoryRebootStrategy` to read `memory_get_peak_usage()` (Option A).

Rationale for Option B:
- No shipped strategy ever returned `true`; the per-request `memory_reset_peak_usage()` call was dead code since #317. Removing it eliminates a branch and a syscall setup on every request and removes a trap for custom-strategy authors (the interface docblock previously implied `MemoryRebootStrategy` used peak, but it used `memory_get_usage()`).
- `MemoryRebootStrategy`'s GC optimization depends on `memory_get_usage()` — it collects cycles and re-reads current usage to avoid a reload when GC freed enough. Peak (`memory_get_peak_usage()`) is monotonic per request and does not drop after `gc_collect_cycles()`, so a peak-based verdict would make the GC path useless and would change the semantics of `limit`/`gc_limit` (peak ≥ current, more reloads) without a clear benefit. The config docs and README already state the metric is `memory_get_usage()` (emalloc), which is the correct signal for leak detection in a long-running worker.
- Keeping a dead interface method for a hypothetical peak-tracking custom strategy adds complexity for no shipped benefit. Custom peak strategies can call `memory_reset_peak_usage()` themselves (documented in UPGRADE.md).

Changes:
- `RebootStrategyInterface` — removed `needsPeakMemory()` and its docblock.
- `AlwaysRebootStrategy`, `MaxJobsRebootStrategy`, `MemoryRebootStrategy`, `ExceptionRebootStrategy`, `StackRebootStrategy` — removed the method (Stack: removed loop).
- `HttpRequestHandler` — removed `private readonly bool $resetPeakUsage`, the constructor assignment, and the `if ($resetPeakUsage) \memory_reset_peak_usage()` block in `__invoke()`.
- `Benchmark/BenchRebootStrategy` and `tests/Fixtures/control_byte_dos_e2e_runner.php` — removed stub method.
- `tests/RebootStrategyTest.php` — removed `needsPeakMemory` from anonymous test doubles.
- `tests/HttpRequestHandlerTest.php` — removed `TestPeakMemoryRebootStrategy`, collapsed `TestRebootStrategy` to only `shouldReboot`, removed three gating tests (`testMemoryResetPeakUsageIsSkipped…`, `testMemoryResetPeakUsageIsCalled…`, `testMemoryResetPeakUsageGatingDoesNotAffect…`) and two mock `needsPeakMemory()->willReturn(false)` stubs in the failure-path tests. Remaining 67 handler tests pass; 36 RebootStrategy tests pass.
- `CHANGELOG.md` — Added `### Removed` entry under `[Unreleased]` referencing #562 and #317.
- `UPGRADE.md` — Added `## Upgrading to 0.28` section with BC break description and migration snippets for both generic and peak-tracking custom strategies.

## Rejected alternatives
**Option A — make MemoryRebootStrategy peak-based and return true:** Rejected because it would (a) change the meaning of `limit`/`gc_limit` config (peak triggers more reloads than current usage, a silent behavior change for existing deployments), (b) require redesigning the GC re-read logic (peak does not benefit from GC), (c) still leave the question of whether peak is the right reload signal — current usage captures retained leaks, peak captures transient spikes that are freed before the reboot check and likely do not warrant a reload. If a future use case needs peak, it can be added as a separate strategy or a `memory.peak` config flag rather than overloading the existing threshold.

Minimal docblock fix (just remove the misleading example) was also rejected: it would leave dead code and the per-request branch in `HttpRequestHandler` with zero consumers.

## Uncertainties
- Milestone for UPGRADE.md: used `0.28` (the lowest open milestone per `bin/pick-issue.php` is 0.28.0 with 17 open issues). If the release is cut as `0.28.0`, the heading matches; if maintainers prefer `0.29`, heading will be renamed before merge — the migration text is version-agnostic.
- Whether to keep a `memory_reset_peak_usage()` call for custom strategies in `HttpRequestHandler` as an opt-in via tagged service argument — decided against: custom strategies that need peak can reset themselves (one line), no need to keep dead gating in core.

## Verification
- `composer lint` — 0 fixable files, PHPStan 0 errors, Rector done, kb-lint OK (1 pre-existing warning for faq.md over budget), check-changelog OK.
- `vendor/bin/phpunit --no-coverage --filter RebootStrategyTest` — 36/36 pass.
- `vendor/bin/phpunit --no-coverage --filter HttpRequestHandlerTest` — 67/67 pass (3 removed).
- `vendor/bin/phpunit --no-coverage` full suite — 2501 tests, 1 pre-existing failure (PharReadOnlyGuardTest when phar.readonly enabled), 60 skipped.
