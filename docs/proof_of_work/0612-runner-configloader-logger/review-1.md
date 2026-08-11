# Review round 1 — issue #612 (Runner.ConfigLoader logger wiring)

- Commit reviewed: `0f33d8b`
- Files: `src/Runner.php`, `src/ServerManager.php`, `tests/RunnerTest.php`, `CHANGELOG.md`
- Findings ledger: `findings-review.md`

## Prior-round findings

No `findings-review.md` existed before this round (round 1), so there were no
earlier-round findings to re-verify. `findings-coder.md` was read; its
observations are cross-referenced below.

## Docs / decisions check

- FAQ-005 (config-cache / permissions) — the fail-open warning and the hard
  `RuntimeException` path are preserved. No violation.
- FAQ-008 (logging — avoid start-up warnings on stderr before daemonize) —
  the change routes the fail-open warning into the PSR-3 logger on the
  ServerManager path, consistent with its spirit. No violation.
- DEC-002 and other architecture decisions — untouched by this change.
- No decision entry covers Runner/ConfigLoader logger wiring.

## New findings

### F1 (medium) — the Symfony-runtime serve path (`index.php start`) still does not log
`src/Runtime.php:16` still builds `new Runner($application,
CacheWarmupTimeoutConfig::resolve())` with no logger. This is a documented
serve path (`README.md:225`, `composer test`), and on it the fail-open warning
still only reaches stderr via `trigger_error(\E_USER_WARNING)`.
**Disposition: deliberate scope.** The design constraint is genuine — `Runtime`
has no container/logger without an eager kernel boot, which `Runner` and
`warmUpCache()` explicitly avoid. The primary path (`workerman:server start`)
is fixed. Not fixed in code; documented in CHANGELOG and this ledger.
Would automated check catch it? No.

### F2 (low) — non-DI `ServerManager` construction now silently drops the warning
`src/ServerManager.php:38` defaults the logger to `NullLogger`; forwarding it
means a bare `new ServerManager(...)` (e.g. `tests/ServerManagerTest.php:58`)
swallows the fail-open warning where it previously reached stderr.
**Disposition: accepted, not fixed.** In practice `ServerManager` is always
autowired (`ServicesConfigurator.php:181`, `setAutowired(true)`); the Runtime
path preserves a stderr signal. Recorded as a known limitation (see KB
candidate).
Would automated check catch it? No.

### F3 (nit) — CHANGELOG over-stated serve-path coverage
The entry implied the production serve path was fully covered, but the runtime
path still reaches stderr.
**Disposition: fixed** — reworded to scope the claim to the
`workerman:server start`/`restart` path and note the runtime path retains the
stderr fallback.
Would automated check catch it? No (docs).

### F4 (low) — no test that `ServerManager` forwards its logger to `Runner`
`tests/RunnerTest.php` proves Runner passes its own injected logger to its
ConfigLoader, but nothing asserts `ServerManager::start()`/`restart()` forward
`$this->logger`.
**Disposition: accepted, not fixed.** `start()`/`restart()` fork real Workerman
masters and are not cheaply unit-testable; the Runner-level test is the nearest
unit boundary. Autowiring is already covered by `setAutowired(true)`.
Would automated check catch it? No.

### F5 (nit) — issue's stated solution not literally implemented
The issue asked to "inject the kernel's logger into Runner"; the change adds a
nullable constructor param and wires `ServerManager`'s logger instead.
**Disposition: deliberate alternative**, documented in `code-decision-1.md`
and acknowledged in this ledger / CHANGELOG.

## Checked clean

- Wiring correctness: `Runner::createConfigLoader()` passes `logger:
  $this->logger`; `ConfigLoader` fail-open branch (`src/ConfigLoader.php:147-154`)
  logs via the PSR-3 logger when present.
- Null fallback preserved: `ConfigLoader` still defaults to `null`; a Runner
  without a logger still yields the `trigger_error(\E_USER_WARNING)` fallback.
- Backward compat: all `new Runner(...)` call sites (`Runtime.php`,
  `ServerManagerTest`, `RunnerTest`) remain valid.
- Test robustness: the new test invokes the real private
  `validateCacheFilePermissions()` on a missing path (deterministic `warn`
  return); `assertSame(null, $triggered)` genuinely proves no `E_USER_WARNING`
  was raised (the handler records the message regardless of its `true` return).
  Not merely structural.
- PSR-12 / PHPStan level 8: anonymous `AbstractLogger` subclass signature is
  compatible; no level-8 issues apparent.

## Verdict

**ACCEPT with documentation caveats.** Functionally correct for the primary
documented serve path, backward compatible, well-tested at the Runner
boundary. The Runtime-path gap is acceptable scoping (F1), not blocking;
F2/F4 are low-severity follow-ups; F3 fixed in CHANGELOG. Review round 1
converged with no open blocking findings.
