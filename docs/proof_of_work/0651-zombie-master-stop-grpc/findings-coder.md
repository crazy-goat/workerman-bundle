# Findings — coder, issue #651

## Obstacles and surprises

- **The working tree already contained an uncommitted implementation**
  of the fix (`isAliveNonLinux()` + `readProcessStateViaPs()` + the
  non-child zombie test) when this session started — apparently from a
  prior session on the same branch that never committed. I reviewed it
  against the issue, hardened it (`function_exists('exec')` guard),
  verified the gates, and committed it. Worth knowing for the retro: work
  left uncommitted in the tree is indistinguishable from a fresh start.
- **The host reproduces the issue's environment exactly** (macOS,
  Homebrew PHP 8.5.9, grpc loaded). Before running the tests I found
  **~20 leaked `WorkerMan: master process` processes** from previous
  days' test runs, plus two more leaked by each `composer test` run of
  this session (state `Ss`, PPID 1, not listening on any port). Ports
  8888/9999 were held by one stale pair, and the first
  `php tests/App/index.php stop` printed `stop fail`. Cleaned up with the
  repo-scoped FAQ-007 command (`pkill -9 -f 'tests/App/index.php'`).
- **The feared "flaky non-child zombie test" turned out to be writable
  deterministically**: fork A → A forks B and never reaps → B SIGKILLs
  itself (SIGKILL avoids grpc shutdown-handler hangs, per
  `ProcessTerminator`). Marker file + poll-with-deadline instead of fixed
  sleeps. Passes on this grpc/Darwin host; no `ps`-parsing-only unit test
  fallback was needed.

## Bugs / weak spots noticed (including out of scope)

| Where | What | Suggested fix |
| --- | --- | --- |
| `src/ProcessInspector.php:245-254` + `src/ServerManager.php:50,59` | `killOrphanedIntermediateFork($parentPid, $fingerprint)` can **never** kill via the fingerprint path when called from `stop()` in daemon mode: `matchesFingerprint()` requires `$parentPid === $fingerprint->pid`, but the daemon-mode fingerprint is written by the master itself (`MasterWorker::saveMasterPid()`), so it names the **master** PID, not the intermediate's. The fingerprint branch always refuses; the orphaned intermediate is only ever killed via the legacy cmdline path (Linux, no fingerprint file). | Verify **ancestry** instead of identity for the intermediate: re-read `getParentPid($masterPid)` before the master exits (or record the intermediate PID at start) and treat "is the recorded parent of the fingerprinted master" as sufficient identity. |
| `src/ProcessInspector.php:237-239` | `killOrphanedIntermediateFork()` is Linux-only, so on macOS/grpc hosts the hung daemonize intermediate is never cleaned up even after #651 makes `stop()` succeed — it leaks per stop (observed live on this host; see above). Combined with the row above, fixing this needs the ancestry verification first, then a `ps`-based `getParentPid()` for non-Linux. | After the ancestry check exists, add `ps -o ppid= -p $pid` as the non-Linux `getParentPid()` and drop the non-Linux early return. Separate issue recommended. |
| `composer.json` `test` script | The script's final `php tests/App/index.php stop` prints `Workerman[index.php] not run` and exits 0 while a master process survives the run (two leaked masters observed per run on this grpc host). Some test in the suite removes/replaces the pid file or stops the daemon before the script's own stop runs, so the script cannot clean up. | Track down which test consumes the pid file (likely a `ServerManagerTest` stop/restart case) and have the `test` script stop by PID fallback or run the FAQ-007 repo-scoped cleanup as a post step. |
| `src/ServerManager.php:50` | `getParentPid($masterPid)` is called **before** `posix_kill($masterPid, …)`; on non-Linux it always returns 0, so the value is dead on exactly the platform where the intermediate hangs. Harmless after #651, but it makes the non-Linux gap above easy to miss. | None now; resolves together with the non-Linux `getParentPid` issue. |

## Notes for the reviewer

- `composer lint`: OK (cs-fixer, PHPStan, Rector, kb-lint all green).
- `composer test`: 2058 tests, 15734 assertions, 31 skipped (Linux-only
  tests on this Darwin host), exit 0. The new
  `testIsProcessAliveReturnsFalseForNonChildZombie` runs and passes on
  Darwin; `testIsProcessAliveReturnsTrueForNonChildProcess` (running
  non-child) and `testIsProcessAliveReturnsFalseForDeadProcess`
  (direct-child zombie) still pass.
- After the test runs I removed the leaked test masters with the
  repo-scoped `pkill -9 -f 'tests/App/index.php'`; no stray listeners
  remain on 8888/9999.
