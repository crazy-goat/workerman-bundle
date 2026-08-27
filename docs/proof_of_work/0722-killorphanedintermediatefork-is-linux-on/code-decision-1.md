# Code decision — round 1, issue #722

## Approach

* `getParentPid()` — kept Linux `/proc/$pid/status` PPid parsing for Linux, added non-Linux branch `readParentPidViaPs()` using `ps -o ppid= -p $pid`. Handles `exec` disabled (logs warning, returns 0), `ps` unavailable (exit 126/127, logs, returns 0), non-zero exit on signalable PID (logs warning, returns 0), empty output (process gone, returns 0), otherwise trimmed int. Mirrors `readProcessStateViaPs()` error handling so fail-closed semantics match `isAliveNonLinux()`.
* `isWorkermanMasterTitle()` — kept Linux `/proc/$pid/cmdline` check, added non-Linux branch `readProcessCommandViaPs()` using `ps -ww -o args= -p $pid` with identical exec/exit handling (null = ps unavailable -> false, empty = process gone -> false, otherwise `str_contains` for `WorkerMan: master process`). `-ww` avoids width truncation on macOS/BSD.
* `killOrphanedIntermediateFork()` — removed `if (!isLinux()) return;` early return. The fingerprint ancestry path (`getParentPid(fingerprint->pid) === parentPid` + title check) and the legacy fallback (`isWorkermanMasterTitle`) now both work cross-platform via the helpers above. No change to fingerprint direct-identity branch or warning messages.
* Tests — updated `testGetParentPidReturnsZeroOnNonLinux` (Darwin) to assert real parent PID via ps (was `0` before #722) and kept alias for backwards compatibility. Added three Darwin tests: ancestry kill via title, ancestry shell-safety (no kill), and `getParentPid` zero for non-existent PID. Reused existing `forkAncestryPair(true/false)` helper (now OS-agnostic, exec -a title works on Darwin). `captureFingerprintForPid()` already falls back to `posix_getuid()`/0 on Darwin, so no new helper needed.
* Docs — `CHANGELOG.md` entry under `[Unreleased] Fixed` for #722.

## Rejected

* Recording intermediate PID at `ServerManager::start()` time in a sidecar file (suggested in #790) — correct for the re-read race but out of scope for #722, which is about platform parity. The current ancestry check still re-reads `getParentPid(master)` after master death; on both Linux and Darwin this can miss when the zombie has already been reaped (returns 0, leaks once, no wrong kill). Left as #790 for a follow-up.
* Using `ps -o command=` instead of `ps -ww -o args=` — `command` on some macOS/BSD truncates at 80 columns; `-ww` + `args` is the wide, portable form.
* Adding a `TestProcessInspector` subclass that mocks `isLinux()` to force Darwin tests to run on Linux — rejected as test-only indirection; the real `ps` path is exercised on the Darwin host where it matters, and Linux CI still exercises the `/proc` path via the existing Linux-only tests.

## Uncertainties

* `ps` output for `exec -a` title on Darwin was verified manually (`ps -ww -o args=` shows `WorkerMan: master process ...` verbatim). On other BSDs the `args` field may differ (e.g., truncated or using `command` vs `args`), but `ps` is POSIX-required to provide `args`. If a host's `ps` truncates despite `-ww`, the title check would fail closed (intermediate leaks, no wrong kill).
* The re-read race from #790 remains: `ServerManager::stop()` captures `parentPid` before `posix_kill(master)`, but `killOrphanedIntermediateFork()` re-reads `getParentPid(master)` after `waitForProcessToStop()` has classified master as dead. If the hung intermediate finally reaped the zombie between `waitForProcessToStop` returning and the `ps` re-read, `getParentPid` returns 0 and ancestry fails closed. No wrong kill, but a leak. Fix would be to pass the captured `parentPid` as ancestry proof without re-read.
