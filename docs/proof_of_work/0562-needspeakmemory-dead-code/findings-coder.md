# Findings — Coder

## Obstacles / surprises
- The interface docblock at `src/Reboot/Strategy/RebootStrategyInterface.php:52-53` no longer named `MemoryRebootStrategy` explicitly (already changed in `b4232d4` to "a strategy tracking peak usage"); the issue's quoted text was stale. The real contradiction was the surviving `needsPeakMemory()` method with zero `true` implementations, not a literal naming mismatch. Verified via `git blame` and fixed by removing the method entirely.
- `HttpRequestHandler` stored the gating decision as `private readonly bool $resetPeakUsage` set in constructor. Removal required deleting both the property and the `if` in `__invoke()` (lines 76-88, 309-311). No other code referenced the property (grep confirmed).
- `tests/HttpRequestHandlerTest.php` had 5 `needsPeakMemory` reference sites: `TestRebootStrategy` + `TestPeakMemoryRebootStrategy` definitions, 3 dedicated gating tests, and 2 mock stubs at lines 1295/1319. Removing the two test doubles and three tests reduced coverage target but left no orphaned assertions.

## Discovered bugs / weak spots (including out-of-scope)

| File:Line | Severity | Description | Suggested fix |
|---|---|---|---|
| `src/Reboot/Strategy/RebootStrategyInterface.php:23` | nit | Class doc `@see MemoryRebootStrategy Reboots when memory_get_usage() exceeds a limit.` now the only description of the metric; config docs (`README.md:381`, `ConfigurationTreeBuilder.php:292`) are explicit, but the interface could state the metric for `MemoryRebootStrategy` more visibly since the `needsPeakMemory` abstraction is gone. | No action needed now; ensure future peak-based strategies document their metric in class doc like the other `@see` lines. |
| `docs/helpers/faq.md:376` | low | KB linter warns `faq.md` is 376 lines (limit 300) — pre-existing, unrelated to this issue. | Promote or drop entries per `bin/kb-lint.php` budget (see FAQ-031, DEC-009). Tracked separately. |
| `tests/Phar/PharReadOnlyGuardTest.php:42` | low | Full suite fails when `phar.readonly=1` is the default PHP ini (1 failure in 2501 tests). Not caused by this change. | Run with `php -d phar.readonly=0 vendor/bin/phpunit` or set `WORKERMAN_ALLOW_PHAR_READONLY_SKIP=1` in CI as the test advises. |
| `benchmarks/BenchRebootStrategy.php:7` | nit | Benchmark stub still exists but now only implements `shouldReboot`. No test asserts its shape matches interface after removal — could drift. | Add a static assertion test that `BenchRebootStrategy implements RebootStrategyInterface` compiles (already covered by lint, but explicit test could pin). |
| `src/Http/HttpRequestHandler.php:58` | nit | Handler now has no memory-peak state; `Utils::reload()` is the only worker-lifecycle side effect. Future reviewers may reintroduce peak gating without noticing the GC incompatibility. | Record in `decisions.md` (candidate at end of cycle) that peak-based reloads were deliberately removed and why GC makes them unsuitable for `MemoryRebootStrategy`. |

