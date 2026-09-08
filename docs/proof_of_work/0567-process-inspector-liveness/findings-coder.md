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
