# Findings — coder, issue #722

## Obstacles and surprises

- **Host is Darwin, but previous fix was Linux-only.** `getParentPid()` returned 0 and `killOrphanedIntermediateFork()` early-returned on non-Linux, so the ancestry path from #721 never fired on this host. Manual `ps` probes had to confirm `ps -o ppid=` and `ps -ww -o args=` both work and that `exec -a` titles survive into `ps` output on macOS. Existing Darwin tests expected `getParentPid()` to return 0; they had to be rewritten to expect the real PPID, otherwise the new implementation would break them.
- **`ps` pollution in test harness.** A `php -r '...'` parent whose source code literally contains the string `WorkerMan: master process` (the `$title` variable) made any forked child without `exec` inherit that string in its `ps -ww -o args=` output, causing `isWorkermanMasterTitle()` to return true for a plain forked child (false positive). The plain-child negative test had to use `pcntl_exec` to a clean PHP loop (`php -r 'while(true){sleep(1);}'`) rather than a bare `pcntl_fork()` copy.
- **Re-read race already filed as #790.** `killOrphanedIntermediateFork()` still re-reads `getParentPid(fingerprint->pid)` after the master is dead. If the hung intermediate reaped the zombie in the window between `waitForProcessToStop()` and the `ps` re-read, the check fails closed and the intermediate leaks once. The production flow already captures `parentPid` before `posix_kill(master)` while alive; using that captured value as the ancestry proof would be race-free. Left as #790.

## Bugs / weak spots noticed (including out of scope)

| Where | What | Suggested fix |
| --- | --- | --- |
| `src/ProcessInspector.php:303-317` (pre-fix) | `isWorkermanMasterTitle()` did single `file_get_contents("/proc/$pid/cmdline")` without handling empty file (kernel thread) and without logging — inconsistent with fingerprint path's warning logging. | No correctness fix needed (empty -> false, fail closed), but consider logging when title check returns false due to unreadable/empty cmdline during ancestry verification. |
| `src/ProcessInspector.php:261-277` + `src/ServerManager.php:50,59` | Ancestry check re-reads `getParentPid(fingerprint->pid)` after master death — may miss when master already reaped (issue #790). | Pass already-captured `parentPid` from `ServerManager::stop()` as ancestry proof without re-read, or record intermediate PID at start time in a sidecar. |
| `tests/ProcessInspectorTest.php:850-1000` (pre-fix) + Darwin host | Ancestry tests are `@requires OS Linux` and skipped on Darwin hosts (including macOS+grpc dev host). Darwin dev never exercised ancestry path locally; only CI did. | The new Darwin tests (`OnDarwin` suffix) close the gap for the ps-based path; consider making the Linux tests OS-agnostic or adding a mocked `isLinux()` test subclass so `composer test` on Darwin also pins the `/proc` path. |
| `src/ProcessInspector.php:303-317` | `-ww` wide output relies on `ps` supporting BSD `-ww` flag; on exotic POSIX `ps` may truncate title despite `-ww`. | Fail closed (leak once, no wrong kill) — acceptable; if truncation observed, add fallback `ps -o command=` or read `ps` header width dynamically. |
| `composer.json` test script | `composer test` boots a real Workerman daemon on 8888/9999 and leaked one daemonize intermediate per run on macOS+grpc before #722 (FAQ-007). | Verify `composer test` on Darwin+grpc leaves zero `WorkerMan: master process` `Ss` processes after exit (`ps aux | grep WorkerMan`). |

## Notes for the reviewer

- `php -l` clean for `src/ProcessInspector.php` and `tests/ProcessInspectorTest.php`.
- Manual Darwin verification: `getParentPid(getmypid()) === posix_getppid()` (YES), `getParentPid(999999)` === 0, `isWorkermanMasterTitle` plain exec false / titled exec true, ancestry kill true / shell safety true (both on Darwin).
- `composer lint` will be run in step 7; check-changelog must pass (entry added for #722).
- No `docs/helpers/` edits; candidates proposed in this cycle's report (see step 14).
