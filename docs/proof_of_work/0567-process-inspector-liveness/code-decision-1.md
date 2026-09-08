# Code Decision 1 — Issue #567: ProcessInspector liveness poll /proc read reduction

## Approach

### `isProcessAlive()` — `/proc/{pid}/stat` field 3 instead of `/proc/{pid}/status` + `/m` regex

The old Linux path slurped the whole `/proc/{pid}/status` file (~1 KB) and ran
`preg_match('/^State:\s+Z/m', $status)` on every liveness poll. Replaced with
`readProcessStateFromStat()`, which reads `/proc/{pid}/stat` (a single line) and
extracts the state character from field 3 using the same "find last `)` then
split" parsing already proven in `MasterFingerprint::readStartTimeForPid()`.
State `'Z'` → not alive; anything else → alive; `null` (unreadable) → alive
(matching the old unreadable-`/proc` fail direction so a live process is never
read as dead when /proc is transiently unavailable).

`/proc/{pid}/stat` is read by `file_get_contents` but only the first token
after `)` is consumed logically — the file is a single line so the read is
bounded by the kernel's stat line size (~200 bytes typical, vs ~1 KB for
status).

### `matchesFingerprint()` — single snapshot, 2 /proc reads

The old method called `isProcessAlive()` up to 3 times (lines 109, 117, 144),
each reading `/proc`, plus `readUidForPid()` (reading `/proc/{pid}/status`) and
`readStartTimeForPid()` (reading `/proc/{pid}/stat`) — up to 5 /proc reads per
verification. Replaced with a single snapshot via `readLinuxProcessSnapshot()`:
reads `/proc/{pid}/stat` once (yields state char + start time) and
`/proc/{pid}/status` once via `MasterFingerprint::readUidForPid()` (yields UID).
**2 bounded /proc reads per call**, documented in the method docblock.

The snapshot is a new `ProcessSnapshot` DTO (`src/ProcessSnapshot.php`) holding
`state`, `startTime`, `uid` with an `isAlive()` helper. The caller reasons over
the snapshot: null snapshot → not alive → false; `Z` state → not alive → false;
uid null → fail closed; uid mismatch → fail closed; startTime 0 when fingerprint
has one → fail closed; startTime mismatch → fail closed.

### Non-Linux path — unchanged

The non-Linux branch of `matchesFingerprint()` now explicitly calls
`isProcessAlive()` (which dispatches to `isAliveNonLinux()` — the
pcntl_waitpid/ps path, unchanged) followed by the `posix_getuid()` UID check.
This is the same logic as before, just restructured so the Linux snapshot path
and the non-Linux path are clearly separated.

## What I rejected and why

1. **Bounded `fread` of first ~256 bytes of `/proc/{pid}/status` + `strpos()`**
   — rejected because `/proc/{pid}/stat` field 3 gives the state character
   directly and the "find last `)`" parsing already exists and is tested in
   `MasterFingerprint::readStartTimeForPid()`. The stat approach also lets us
   coalesce the state read and the start-time read into a single file read in
   the snapshot path, which the status-fread approach cannot do.

2. **Reading UID from `/proc/{pid}/stat` instead of `/proc/{pid}/status`** —
   rejected. `/proc/{pid}/stat` does not contain the UID; it is only in
   `/proc/{pid}/status` (the `Uid:` line). So the snapshot still needs one read
   of status for the UID. This keeps the snapshot at 2 reads (stat + status)
   rather than 1, but UID verification requires it.

3. **Inlining the snapshot parsing into `matchesFingerprint()`** — rejected in
   favor of a separate `readLinuxProcessSnapshot()` private method + DTO, for
   testability (the tests exercise `readLinuxProcessSnapshot()` directly via
   reflection) and to keep `matchesFingerprint()` readable.

4. **Making `readProcessStateFromStat()` reuse `readLinuxProcessSnapshot()`** —
   considered but rejected. `isProcessAlive()` only needs the state character;
   calling the full snapshot (which also reads `/proc/{pid}/status` for UID)
   would add an unnecessary `/proc` read to every liveness poll in
   `waitForProcessToStop()`. Keeping `readProcessStateFromStat()` as a
   standalone single-read method is cheaper for the poll loop.

## Uncertainties

- The `readProcessStateFromStat()` method reads the full `/proc/{pid}/stat`
  line via `file_get_contents`. A truly bounded `fread` would read fewer bytes,
  but `/proc/{pid}/stat` is a single line (~200 bytes) and the state char is
  early (field 3, after the last `)`). The kernel generates this
  synchronously so the cost is dominated by the syscall, not the buffer size.
  This matches the approach already used by
  `MasterFingerprint::readStartTimeForPid()`.

- The fail-closed behavior for "process dying mid-verification" is covered by
  the snapshot's state being `'Z'` (zombie) or the snapshot being `null`
  (process gone, /proc unreadable). Both paths return false. On Linux, a
  just-killed process becomes a zombie (state `Z`) until reaped, so the
  snapshot will see `Z` rather than null in the typical case.

- `getParentPid()` (lines 55-76) still reads the whole `/proc/{pid}/status`
  and uses a `/m` regex. This is out of scope for this issue (which targets
  `isProcessAlive()` and `matchesFingerprint()`), but noted in findings.
