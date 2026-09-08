# Findings Coder — Issue #567: ProcessInspector liveness poll /proc read reduction

## Biggest problem faced

The core challenge was preserving the exact fail-closed semantics of
`matchesFingerprint()` while restructuring from "call `isProcessAlive()` up to
3 times + separate UID/start-time reads" to "one snapshot, reason over it."

The old code had a subtle two-step fail-closed pattern: when `readUidForPid()`
returned null, it called `isProcessAlive()` again to distinguish "process died
→ fail closed (dead)" from "process alive but UID unreadable → fail closed
(degraded, log warning)." Both paths return false, but the second logs a
warning. The same pattern existed for start time.

In the snapshot approach, this distinction collapses: the snapshot's state
field already tells us if the process is alive. If state is `Z` or null → not
alive → false (no warning needed, the process is just dead). If state is
non-`Z` but uid is null → alive but UID unreadable → false + warning. This is
cleaner but I had to carefully verify that every old fail-closed path maps to
a new fail-closed path:

| Old path | New path |
|---|---|
| `isProcessAlive()` false (line 109) | snapshot null or `isAlive()` false |
| `readUidForPid()` null + `isProcessAlive()` false (lines 115-118) | snapshot null or `isAlive()` false (state Z/null) |
| `readUidForPid()` null + `isProcessAlive()` true (lines 120-127) | snapshot `isAlive()` true + uid null → false + warning |
| `readStartTimeForPid()` 0 + `isProcessAlive()` false (lines 142-145) | snapshot null or `isAlive()` false |
| `readStartTimeForPid()` 0 + `isProcessAlive()` true (lines 147-153) | snapshot `isAlive()` true + startTime 0 → false + warning |
| UID mismatch (lines 130-137) | snapshot uid !== fingerprint uid → false + warning |
| startTime mismatch (lines 156-163) | snapshot startTime !== fingerprint startTime → false + warning |

All paths preserved. The non-Linux path was restructured to call
`isProcessAlive()` (→ `isAliveNonLinux()`) then `posix_getuid()`, same as
before but without the initial `isProcessAlive()` at the top (moved into the
non-Linux branch since the Linux branch now uses the snapshot for liveness).

## Discovered bugs / places to improve

### 1. `getParentPid()` still reads whole `/proc/{pid}/status` + multiline regex
- **File:** `src/ProcessInspector.php:55-76`
- **Problem:** `getParentPid()` reads the entire `/proc/{pid}/status` file
  and runs `preg_match('/^PPid:\s+(\d+)/m', $status, $matches)` — the same
  pattern this issue eliminated from `isProcessAlive()`. It is called in
  `killOrphanedIntermediateFork()` (line 263) during ancestry verification.
- **Suggested fix:** Parse `/proc/{pid}/stat` field 4 (PPid) instead. The
  "find last `)` then split" parsing already gives us field 4 as
  `$afterParts[1]` (ppid is field 4, index 1 after `)`). This would coalesce
  with the snapshot read if `getParentPid()` is called in the same
  verification flow. Out of scope for this issue.

### 2. `MasterFingerprint::readUidForPid()` still reads whole `/proc/{pid}/status` + multiline regex
- **File:** `src/MasterFingerprint.php:114-135`
- **Problem:** `readUidForPid()` reads the entire `/proc/{pid}/status` file
  and runs `preg_match('/^Uid:\s+(\d+)/m', $content, $matches)`. This is
  called from the snapshot path in `readLinuxProcessSnapshot()` and from
  `captureFingerprintForPid()` in tests. The UID is only available in
  `/proc/{pid}/status` (not in `/proc/{pid}/stat`), so this cannot be
  eliminated entirely, but a bounded `fread` of the first ~256 bytes + a
  non-regex `strpos`/`substr` parse would reduce the read size. The `Uid:`
  line appears early in the status file (typically within the first 10
  lines), so a bounded read is safe.
- **Suggested fix:** Replace `file_get_contents` with `fopen`+`fread` of 512
  bytes and parse the `Uid:` line with `strpos`/`substr` instead of a
  multiline regex. Low priority — this is one read per snapshot, not per
  poll. Out of scope for this issue.

### 3. Duplicated `/proc/{pid}/stat` parsing logic
- **File:** `src/ProcessInspector.php:487-516` (`readProcessStateFromStat`),
  `src/ProcessInspector.php:534-565` (`readLinuxProcessSnapshot`), and
  `src/MasterFingerprint.php:72-106` (`readStartTimeForPid`)
- **Problem:** The "find last `)` then `preg_split`" parsing of
  `/proc/{pid}/stat` is now duplicated in three places. If the format
  parsing needs to change (e.g. to handle a kernel edge case), all three
  must be updated.
- **Suggested fix:** Extract a shared private/static helper
  `parseProcStat(int $pid): ?array` that returns the fields after `)` as
  an array, and have all three callers use it. Low priority — the parsing
  is stable and the duplication is small. Out of scope for this issue.

### 4. kb-lint warning on docs/helpers/faq.md is pre-existing
- **File:** `docs/helpers/faq.md` — 397 lines (index excluded) over 300-line budget
- **Problem:** The kb-lint step warns that faq.md is over its line budget.
  This is pre-existing and unrelated to this issue (I did not touch
  docs/helpers/). Noted for awareness.
- **Suggested fix:** Promote or drop entries per the docs/helpers workflow.

---

## Round-1 fix (review findings F-1/F-2)

### Biggest problem faced

The two medium findings required tests exercising the `$snapshot->uid === null`
and `$snapshot->startTime === 0` fail-closed branches of `matchesFingerprint()`
**with an alive process**. Neither branch can be reached through a real `/proc`
read: a live Linux process always exposes a readable UID in `/proc/{pid}/status`
and a non-zero start-time field (field 22 of `/proc/{pid}/stat`). Making
`/proc/{pid}/status` genuinely unreadable requires a `hidepid` mount (root /
kernel config, not portable in unit tests), and `ProcessInspector` is `final
readonly` (not mockable) while `MasterFingerprint::readUidForPid()` is a public
static (not monkey-patchable). So the only clean seam is to extract the branch
decision into a method that takes a `ProcessSnapshot` and call it from tests
with a crafted snapshot via reflection.

### New findings this round

### 5. `matchesFingerprint()` test seam lives in production code
- **File:** `src/ProcessInspector.php` — new private
  `snapshotMatchesFingerprint()` extracted from the Linux branch of
  `matchesFingerprint()`.
- **Problem:** This adds a small private method purely to make the fail-closed
  branches testable. It is low-risk (private, dead-ends in `matchesFingerprint`,
  public behavior unchanged) but is a production-code change driven by test
  coverage.
- **Suggested fix:** Accept as-is. If the `ProcessInspector` `final readonly`
  constraint ever changes, an injectable snapshot-reader dependency would remove
  the extraction *and* make the unreadable-field branches reachable through
  the real `matchesFingerprint()` path (cleaner acceptance-criterion coverage).
  (No action required now.)

### 6. The mismatch tests could stay but are redundant after the extraction guard
- **File:** `tests/ProcessInspectorTest.php` — `testMatchesFingerprintFailsClosedForMismatchedUid` (line 1528) and `testMatchesFingerprintFailsClosedForMismatchedStartTime` (line 1584).
- **Problem:** These still exercise the full `matchesFingerprint()` → real-snapshot → `uid !== fingerprint->uid` / startTime-mismatch branches, which complement the new crafted-snapshot tests. Not redundant — keep both. No change needed.
- **Suggested fix:** None.

### 7. `readLinuxProcessSnapshot()` still reads whole `/proc/{pid}/status` for UID
- **File:** `src/ProcessInspector.php:562` (`readLinuxProcessSnapshot` → `MasterFingerprint::readUidForPid`), `src/MasterFingerprint.php:114-135`.
- **Problem:** Already flagged as round-1 coder finding #2. Confirmed out of scope — one read per verification, not per poll. Note only.
- **Suggested fix:** See round-1 findings #2.

