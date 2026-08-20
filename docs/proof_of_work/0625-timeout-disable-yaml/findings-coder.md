# Findings — #625 (allow disabling connection/keepalive timeouts via YAML)

## Obstacles

1. **Filtered `phpunit` runs can end in a fatal at shutdown.**
   `tests/App/bootstrap.php:58` runs `@unlink(...)` in the
   `register_shutdown_function(workerman_stop(...))`; when the unlink hits a
   non-existent file (daemon state differs between runs) the suppressed
   warning is still routed to PHPUnit 10's `ErrorHandler`, which throws
   `NoTestCaseObjectOnCallStackException` at shutdown. Seen twice while
   iterating on `RunnerTest`; disappears once the test itself errors/passes
   (the marker files exist). Pre-existing harness fragility, not caused by
   this change — full `composer test` runs were unaffected.

2. **`Worker::$logFile` defaults to `''` in unit-test context.**
   `vendor/workerman/workerman/src/Worker.php:753` only assigns a real log
   path when the worker actually runs; `Worker::log()` →
   `file_put_contents(static::$logFile, ...)` (Worker.php:2353) then throws
   `ValueError: Path must not be empty`. Also `Worker::safeEcho()` hits
   `feof(null)` when `Worker::$outputStream` is null (Worker.php:2427).
   `ServerWorkerTest` sidesteps both in `setUp()`; the new RunnerTest
   flow-through test had to do the same (memory stream + temp log file).

3. **`->min(0)` keeps rejecting negatives** but the runtime's `> 0` guards
   would treat them as disabled — fine, but the config tree and the runtime
   now differ on the negative range (`min(0)` rejects, runtime tolerates).
   Harmless; documented in code-decision-1.md.

## Issues noticed (including outside this issue's scope)

1. **docs/security.md, Connection Timeouts → Security Considerations**
   (bullet now fixed as part of this change): *"Timers are per-connection:
   Each connection's timers are independent."* — stale since #555. Timeout
   enforcement is one worker-level sweeper (`Timer::add` once in
   `ServerWorker::onWorkerStart`, ServerWorker.php:109); there are no
   per-connection timers (see DEC-003). The bullet was contradicting the
   very paragraph above it in the same section. **Fix applied**: rewritten
   to "Timeouts share one worker-level sweeper…". If the maintainers object
   to the wording, the minimum correct edit is deleting the bullet.

2. **README.md:35** (feature list): "configurable `connection_timeout` … and
   per-server `body_size_cap`" — still accurate after this change, but if a
   follow-up wants the feature list to advertise disabling, the "0 disables"
   note lives only in the config table + security.md. No action needed.

3. **`tests/App/bootstrap.php:52-59`** — `workerman_stop()` suppresses
   unlink errors with `@`, which is ineffective under PHPUnit's error
   handler at shutdown (see obstacle 1). Suggested fix: guard with
   `is_file()`/`file_exists()` before `unlink()` instead of `@`, or
   `error_clear_last()`; the fatal costs nothing in CI (full runs have the
   marker files) but makes filtered local runs crash after an erroring test.

4. **`Runner::createWorkers` uses `?? 120` / `?? 30` fallbacks**
   (src/Runner.php:259-260) although the config tree always defaults these
   keys — the fallbacks are dead code for the bundle path but keep
   `Runner` usable with hand-built arrays (which RunnerTest does). Not a
   bug; leave as is.

## Runtime sanity check (issue asked to verify)

ServerWorker.cpp semantics match the issue's description exactly:
- ServerWorker.php:103-106 — `array_filter([$connectionTimeout, $keepaliveTimeout], fn $t > 0)`; empty array ⇒ `Timer::add` never runs ⇒ no sweeper.
- ServerWorker.php:122-123 — `$timeout = $requestCompleted ? $keepaliveTimeout : $connectionTimeout; if ($timeout > 0 && ...)` — per-connection check also skips 0/negative.
- Both already pinned by tests (`testZeroTimeoutsDoNotArmSweeper`, `testKeepaliveTimeoutZeroKeepsOnlySweeperTimer`).

One nuance worth noting (done in the docs): `0` disables the *bundle's*
timeout enforcement; with both timeouts set to `0` there is no sweeper at all,
so neither bundle nor Workerman's HTTP layer closes slow or idle connections
on its own.
