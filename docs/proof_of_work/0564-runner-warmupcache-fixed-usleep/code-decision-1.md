# Code Decision — Round 1 (issue #564)

## Context
`Runner::warmUpCache()` forks a child to boot the kernel and then waits for it with a hand-rolled `while(true)` loop: `pcntl_waitpid(..., WNOHANG)` → deadline check with `time()` (1s granularity) → `usleep(100_000)`. The fixed 100 ms tick adds up to 100 ms startup latency per cold start (expected overshoot ~50 ms). The repo already owns a shared backoff helper `Util\Wait::until()` that starts at 10 ms and caps at 250 ms, used by `StatusFileReader::waitForFile()` and `ProcessInspector::waitForProcessToStop()` — `Runner` was missed when that was unified (commit 0de7b98). The issue asks to replace the loop with `Wait::until()`, preserve three distinct outcomes (child reaped / wait failure -1 / timeout), and honour timeout with sub-second accuracy.

## Approach taken
- Import `CrazyGoat\WorkermanBundle\Util\Wait` in `src/Runner.php`.
- Replace the manual deadline + `while` + `usleep(100_000)` with `Wait::until()`:
  - Condition captures `$status` and a `$waitFailed` flag by reference, performs `pcntl_waitpid($pid, $status, WNOHANG)`.
  - Returns `true` on `$result === $pid` (child reaped) or `$result === -1` (wait failure), `false` otherwise (keep polling).
  - `Wait::until($condition, $timeout)` uses `microtime(true)` deadline and exponential backoff (10 ms → 250 ms), so fast child is observed in ~10 ms vs up to 100 ms before.
- After `Wait::until()`:
  - If `$waitFailed` → throw `RuntimeException('Failed to wait for cache warmup process')`.
  - If `!$completed` (timeout) → `posix_kill($pid, SIGKILL)`, blocking `pcntl_waitpid($pid, $status, 0)`, then throw `RuntimeException(sprintf('Cache warmup timed out after %d seconds', $timeout))`.
  - Otherwise fall through to the existing signal/exit-code inspection (SIGKILL success, SIGTERM failure, etc.) — untouched.
- Verify no bare `usleep()` remains in the file (only inside `Wait`).
- Add `CHANGELOG.md` entry under `[Unreleased] ### Performance` referencing #564.
- Keep the success/failure signal protocol (SIGKILL vs SIGTERM) intact; no changes after the loop.

## Alternatives considered
- **Inline backoff loop without Wait**: would duplicate Wait's `microtime` + backoff logic and diverge from the shared strategy — rejected because the issue explicitly asks to reuse `Wait::until()` and the two existing consumers already do.
- **Wait condition returning int instead of bool**: considered making condition return tri-state to avoid `$waitFailed` flag, but `Wait::until()` signature is `callable(): bool` — wrapping the tri-state would require either throwing inside condition or changing Wait's contract. Flag + boolean return keeps Wait generic and the `-1` case still surfaces as a distinct exception.
- **Storing timeout as float**: considered honoring sub-second `WORKERMAN_CACHE_WARMUP_TIMEOUT` values, but `CacheWarmupTimeoutConfig::DEFAULT` and Runner's constructor are `int`, and the env var parsing is integer — out of scope for this issue.
- **Changing initialDelay/maxDelay**: kept Wait defaults (10 ms / 250 ms) as documented for short waits and long waits; issue's "What to change" says to use the defaults.

## Uncertainties / risks
- `Wait::until()` checks `microtime(true) >= deadline` *after* the condition, before sleep — matches the original `time() >= deadline` placement, so no extra sleep past deadline. The worst-case overshoot is now `maxDelayMs` (250 ms) plus condition time, vs previously up to 1 s due to `time()` granularity + 100 ms — strictly better but slightly different timing for the 1-second timeout test; verify `warmup_timeout_kicks_in` still passes (it allows 0.05–3.0 s).
- `$status` is passed by reference into a closure captured by reference — PHPStan level 8 must accept `?string &$lower` style? Here it's `int &$status` initialized to 0, assigned inside closure before read — should be fine but verify lint.
- Signal handling order after loop is unchanged, but the timeout path now does a blocking `pcntl_waitpid($pid, $status, 0)` after SIGKILL exactly as before — ensures zombie is reaped.

## Files changed
- `src/Runner.php`: import Wait, replace loop
- `CHANGELOG.md`: add Performance entry

## Tests
- Existing `RunnerTest` (including `StuckChildRunner` timeout, fork-failure, child boot exception, etc.)
- New timing property (fast child ~10 ms) should be covered; verify via isolated test or manual benchmark if needed — acceptance criteria mentions a test measuring elapsed time for immediate exit.
