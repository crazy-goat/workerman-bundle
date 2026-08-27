# Findings — Coder (issue #564)

## Biggest problem faced
The three distinct outcomes of the old loop must be preserved with `Wait::until()`'s boolean return, which cannot express them alone. `pcntl_waitpid()` returning `-1` (wait failure) must not be swallowed into a generic timeout — it needs an immediate `RuntimeException('Failed to wait...')`, while a genuine timeout (deadline elapsed, child still alive) must SIGKILL the child, do a blocking reap, and throw the timeout message. Solving this without changing `Wait`'s signature required a `$waitFailed` flag captured by reference in the condition closure, and two separate post-`Wait` checks: `if ($waitFailed) throw wait-failure` before `if (!$completed) { kill+reap; throw timeout; }`. The ordering matters: wait-failure must win even if deadline also elapsed.

## Surprises / obstacles
- `Runner` already validates `cacheWarmupTimeout >=1` in constructor, so `Wait::until()`'s `timeoutSeconds <0` guard never fires, but `Wait` also validates `initialDelayMs >=1` and `maxDelayMs >= initialDelayMs` — defaults are safe.
- Original timeout used `time() + $timeout` (whole seconds), so a test (`testWarmupTimeoutKicksIn`) allows 0.05–3.0 s and comments that `time()` granularity makes the firing window 0.1–1.1 s for a 1 s timeout. After the fix the firing is tighter (~1.0 s ± 0.25 s), but the generous bounds still pass.
- No per-request state leaks concern here — the handler is stateless for this path, but the child PID and `$status` must be correctly captured by reference in the closure; PHP's `pcntl_waitpid` second argument is `int &$status`.

## Bugs / weak spots noticed (including out-of-scope)
- | `src/Runner.php:98-120` (before) | Fixed 100 ms poll added up to 100 ms cold-start latency per boot; `time()` granularity could be off by ~1 s vs configured `WORKERMAN_CACHE_WARMUP_TIMEOUT` — fixed in this change |
- | `src/Runner.php:18-23` | `Runner` is `readonly` but holds `KernelFactory` which itself holds a closure — fine, but readonly + closure interaction can be surprising for future contributors; no change needed |
- | `src/Util/Wait.php:83-97` | `Wait::until()` total wall time may exceed `$timeoutSeconds` by up to `$maxDelayMs` plus condition time (documented) — could surprise callers expecting hard deadline; maybe worth noting in Runner's docblock that timeout is "at least" not "at most" — low severity |
- | `tests/StuckChildRunner.php:18-22` | `sleep(10)` in forked child without `posix_kill` guard — if parent times out and SIGKILLs, child is already in sleep; after wake it still does `posix_kill(getmypid(), SIGKILL)` which is harmless but double-kill is redundant; no fix needed |
- | `tests/RunnerTest.php:345-347` (warmup_timeout_kicks_in) | Test comment says timeout fires 0.1–1.1 s due to `time()` but after Wait fix it should be tighter; comment will be stale after merge — suggest updating comment to reflect `microtime(true)` and `Wait` backoff — low nit |

