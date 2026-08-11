# Findings ledger — issue #612

`file:line | what is wrong | severity | what happened to it`

## Round 1

- `src/Runtime.php:16` — Symfony-runtime serve path (`index.php start`) builds
  `Runner` with no logger; fail-open warning still only reaches stderr.
  | medium | Deliberate scope — no container/logger without an eager kernel
  boot (`Runner` / `warmUpCache()` avoid it). Not fixed in code; documented in
  CHANGELOG and review-1.md (F1).

- `src/ServerManager.php:38` — non-DI construction defaults logger to
  `NullLogger`; forwarding it silently drops the fail-open warning where it
  previously hit stderr.
  | low | Accepted, not fixed — always autowired in practice; Runtime path
  preserves a stderr signal. Recorded as a known limitation (KB candidate) (F2).

- `CHANGELOG.md:50-60` — entry over-stated serve-path coverage, implying the
  production serve path was fully fixed.
  | nit | Fixed — reworded to scope to `workerman:server start`/`restart` and
  note the runtime path retains the stderr fallback (F3).

- `tests/RunnerTest.php` — no test that `ServerManager::start()`/`restart()`
  forward `$this->logger` to `Runner`.
  | low | Accepted, not fixed — fork real Workerman masters, not cheaply
  unit-testable; autowiring covered by `setAutowired(true)` (F4).

- `src/Runner.php` constructor — issue's stated solution (inject kernel's
  logger) not literally implemented; nullable param + ServerManager wiring
  instead.
  | nit | Deliberate alternative, documented in code-decision-1.md and
  review-1.md (F5).
