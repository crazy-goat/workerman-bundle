# Code decision 1 — issue #651: `workerman:server stop` times out on a zombie master (macOS + grpc)

## Approach

Extended the non-Linux liveness path in `src/ProcessInspector.php`
(`isReaped()` → `isAliveNonLinux()`, line 321). The old fallback ran
`pcntl_waitpid($pid, $status, WNOHANG)` and treated **every** non-positive
result as "alive". That is correct for a running direct child (`0`), but
wrong for a PID that is not a direct child (`-1`/ECHILD) — exactly the
shape of a daemonized Workerman master observed from a separate CLI
`stop` process. When the daemonize intermediate hangs in
`grpc_shutdown()` on exit, the SIGINTed master becomes a zombie child of
the intermediate; from the stopper's perspective it is a non-child, and
the old code looped until `stop_timeout` and reported "stop failed".

The new path is two-step:

1. `pcntl_waitpid()` first: `> 0` reaps a zombie **direct child** (dead),
   `0` means a running direct child (alive). This preserves the existing
   direct-child zombie detection and keeps direct-child zombies from
   leaking — the test suite relies on the reaping side effect
   (`testIsProcessAliveReturnsFalseForDeadProcess` and every `finally`
   block that calls `pcntl_waitpid` after `isProcessAlive`).
2. Only on ECHILD (non-child PID) it shells out to `ps -o stat= -p $pid`
   and parses the kernel state: empty output or non-zero exit → process
   gone (not alive); state starting with `Z` → zombie (not alive);
   anything else (`S`, `R`, …) → alive. This mirrors the Linux
   `/proc/{pid}/status` `State: Z` check one-to-one and needs neither
   `/proc` nor a child relationship.

Fail-closed behaviour is kept: when `ps` cannot run (`exec` disabled —
guarded via `function_exists('exec')`, matching the `shell_exec` guard in
`Utils.php`; or exit 126/127) the method logs a warning and treats the
process as alive, same as the unreadable-`/proc` case on Linux.

## What I rejected

- **Dropping `pcntl_waitpid` entirely in favour of `ps`.** Simpler, but
  the waitpid call reaps direct-child zombies; removing it would leak
  zombies across the test suite and any long-running CLI that supervises
  children. `ps` also costs a subprocess spawn; for direct children the
  syscall answer is definitive and free.
- **Trusting `posix_kill($pid, 0)` alone.** It returns true for zombies
  until the parent reaps them — that is the bug.
- **Parsing `ps` via `proc_open`.** More code, same result; `exec` with
  an int-interpolated PID has no injection surface.
- **Killing the hung intermediate on non-Linux** (see below).

## Non-Linux `getParentPid` via `ps` — NOT added

The issue floated adding `ps -o ppid= -p $pid` as a non-Linux
`getParentPid()` so `ServerManager::stop()` could SIGKILL the hung
daemonize intermediate. I did not add it, for two reasons:

1. It would feed a call chain that currently does nothing with the value.
   `ProcessInspector::killOrphanedIntermediateFork()` returns early on
   non-Linux (line 237) — enabling it there requires an identity check we
   cannot pass today: the fingerprint branch demands
   `$parentPid === $fingerprint->pid`, but the daemon-mode fingerprint
   names the **master** PID (written by `MasterWorker::saveMasterPid()`),
   never the intermediate's, so the fingerprint path would always refuse;
   and the legacy cmdline check needs `/proc`, which macOS lacks.
2. The user-visible failure of #651 is fixed without it: once the zombie
   master reads as dead, `waitForProcessToStop()` succeeds, `stop()`
   returns true, and the pid/fingerprint files are cleaned up. The hung
   intermediate remains (a pre-existing grpc shutdown hang, documented in
   `docs/troubleshooting.md`), but it no longer blocks the control plane.
   Safely killing it on macOS needs an ancestry-based verification
   (record/compare `ppid`) that deserves its own issue — see
   findings-coder.md.

## What was uncertain

- **`ps` state format across BSDs.** `ps -o stat=` prints e.g. `Ss`,
  `R+`, `Z` on macOS; zombie state starts with `Z` on all BSDs, so
  `str_starts_with($state, 'Z')` is portable. Only macOS was verified
  here (host: PHP 8.5.9 + grpc, Darwin).
- **The non-child zombie test was expected to be the flaky part** — it
  was not (see findings-coder.md). It forks A, A forks B and never reaps
  it, B SIGKILLs itself (SIGKILL, not `exit()`, so grpc shutdown handlers
  cannot hang the test — same trick as `ProcessTerminator`). A marker
  file publishes B's PID and the assertion polls `isProcessAlive()` with
  a 2 s deadline instead of a fixed sleep. It passes deterministically on
  this grpc/Darwin host and is skipped only where pcntl/posix are absent.
