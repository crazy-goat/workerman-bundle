# Review Round 2 — Issue #567: ProcessInspector liveness poll /proc read reduction

**Branch:** `perf/issue-567-processinspector-isprocessalive-reads-an`
**Files reviewed:** `src/ProcessInspector.php`, `src/ProcessSnapshot.php`, `tests/ProcessInspectorTest.php`, `CHANGELOG.md`
**Date:** 2026-09-08

## Helpers loaded (tag index)

Tags matching the diff files (`src/ProcessInspector.php`, `src/ProcessSnapshot.php`, `tests/ProcessInspectorTest.php`):

- `faq.md`: FAQ-007 (tests,grpc,macos,daemon,process), FAQ-014 (tests,phpstan), FAQ-030 (tests,process), FAQ-032 (ci,tests,process), FAQ-037 (php82,ci,lint,tests)
- `decisions.md`: DEC-006 (security,policy — master identification hardened), DEC-009 (knowledge-base,process,policy)

No violations of documented decisions found. DEC-006 (master identification hardened, fail-closed) is preserved — see analysis below.

## Earlier-round findings disposition

| # | Round-1 finding | Disposition | Evidence |
|---|---|---|---|
| F-1 | Missing test for unreadable UID with alive process (medium) | **fixed** | `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` (line 1627) creates a crafted snapshot (`state='S', startTime=100, uid=null`) and calls `snapshotMatchesFingerprint()` via reflection. Verified: the method hits `$snapshot->uid === null` (line 541) → logs warning → returns false. The test directly exercises the branch that round 1 identified as uncovered. ✅ |
| F-2 | Missing test for unreadable start time with alive process (medium) | **fixed** | `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` (line 1653) creates a crafted snapshot (`state='S', startTime=0, uid=posix_getuid()`) and calls `snapshotMatchesFingerprint()` via reflection. Verified: the method passes the UID check, enters `$fingerprint->startTime > 0` (100 > 0), hits `$snapshot->startTime === 0` (line 564) → logs warning → returns false. The test directly exercises the branch. ✅ |
| F-3 | Duplicated /proc/{pid}/stat parsing logic (low) | **still present** (deliberately not fixed) | The "find last `)` then split" parsing exists in `readProcessStateFromStat()` (line 459), `readLinuxProcessSnapshot()` (line 503), and `MasterFingerprint::readStartTimeForPid()` (line 90). The coder marked it out of scope. Disposition is reasonable: the parsing is stable (kernel /proc format), the duplication is small, and a shared helper would be a follow-up refactor. No functional impact. |
| F-4 | FQCN used instead of import for ProcessSnapshot (nit) | **not a real finding** (confirmed) | `\CrazyGoat\WorkermanBundle\ProcessSnapshot` is used as FQCN on line 129 and 539, consistent with the existing convention in this file (`\CrazyGoat\WorkermanBundle\MasterFingerprint` on lines 177, 228). php-cs-fixer passes clean. Disposition is correct. |

## Automated checks run

| Check | Result |
|---|---|
| `phpstan analyse --level=8` (ProcessInspector, ProcessSnapshot, ProcessInspectorTest) | ✅ No errors |
| `php-cs-fixer fix --dry-run --diff` (full repo, 256 files) | ✅ 0 files to fix |
| `phpunit --filter ProcessInspectorTest` | ✅ 47 tests, 17 skipped (Linux-only), 0 failures |
| `phpunit --filter 'ProcessTest\|MasterFingerprintTest\|MasterWorkerTest\|ServerManagerTest\|WorkermanCommandTest\|ServicesConfiguratorTest'` | ✅ 125 tests, 1 skipped, 0 failures |
| `php -l` on all changed PHP files | ✅ No syntax errors |
| PHP 8.2 syntax compatibility (FAQ-037) | ✅ `final readonly class` is 8.2+; no 8.3+ constructs (no `#[\Override]`, no typed constants, no property hooks, no anonymous readonly classes) |

## Behavioral analysis — fail-closed semantics verification

### `isProcessAlive()` — fail direction preserved

Old: unreadable `/proc/{pid}/status` → return `true` (alive).
New: unreadable `/proc/{pid}/stat` → `readProcessStateFromStat()` returns `null` → `null !== 'Z'` is `true` → return `true` (alive).
Same fail direction. ✅

### `matchesFingerprint()` Linux path — fail-closed mapping verified

| Scenario | Old behavior | New behavior | Preserved |
|---|---|---|---|
| Process dead (not signalable) | `isProcessAlive()` false → false | snapshot null (`/proc` unreadable) → false | ✅ |
| Process zombie | `isProcessAlive()` false (State:Z) → false | snapshot state 'Z' → `isAlive()` false → false | ✅ |
| Process alive, UID unreadable | `readUidForPid()` null → `isProcessAlive()` true → false + warning | snapshot alive, uid null → `snapshotMatchesFingerprint()` false + warning | ✅ |
| UID mismatch | uid !== fingerprint uid → false + warning | snapshot uid !== fingerprint uid → false + warning | ✅ |
| Process alive, start time unreadable | `readStartTimeForPid()` 0 → `isProcessAlive()` true → false + warning | snapshot alive, startTime 0 → `snapshotMatchesFingerprint()` false + warning | ✅ |
| Start time mismatch | startTime !== fingerprint startTime → false + warning | snapshot startTime !== fingerprint startTime → false + warning | ✅ |
| All match | true | true | ✅ |

### `matchesFingerprint()` non-Linux path — unchanged

Old: `isProcessAlive()` at top → `posix_getuid()` check.
New: `isProcessAlive()` in else branch → `posix_getuid()` check.
Same logic, same order. ✅

### Behavioral difference: warning suppression for hidepid case (not a finding)

In the old code, when `hidepid` made `/proc/{pid}/status` unreadable but the process was alive, `isProcessAlive()` returned true (unreadable status → alive), then `readUidForPid()` returned null, `isProcessAlive()` returned true again, and a warning was logged ("Cannot read UID..."). In the new code, if `/proc/{pid}/stat` is readable but `/proc/{pid}/status` is not, the snapshot has a non-null state (alive) but null uid → `snapshotMatchesFingerprint()` logs the same warning. If `/proc/{pid}/stat` is ALSO unreadable (which `hidepid=2` would cause for processes owned by other users), the snapshot is null → false with NO warning. The old code would have called `isProcessAlive()` which reads `/proc/{pid}/status` → also unreadable → returned true → then `readUidForPid()` null → `isProcessAlive()` true → warning logged.

This is a minor behavioral difference: under `hidepid=2` for another user's process, the old code logged a warning while the new code silently returns false. Both fail closed (return false), so the security property is preserved. The difference is advisory visibility only. Not a finding — the fail-closed behavior is intact.

### `snapshotMatchesFingerprint()` test seam — correctness verified

The extracted private method takes a `ProcessSnapshot` and `MasterFingerprint` and performs the identity checks (UID null → false, UID mismatch → false, startTime 0 with fingerprint startTime > 0 → false, startTime mismatch → false). The method accesses `$this->logger` for warnings, which is a `NullLogger` by default in tests. The extraction is behavior-preserving — the branch bodies are identical to the old inline code. The `pid` in warning context arrays now uses `$fingerprint->pid` instead of the old `$pid` parameter, which is correct (the fingerprint's pid was already verified to equal the candidate pid at the top of `matchesFingerprint`).

## Test correctness verification

### F-1 fix test: `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` (line 1627)

- Creates fingerprint: `pid=1234, startTime=100, uid=posix_getuid()`
- Creates snapshot: `state='S', startTime=100, uid=null`
- Calls `snapshotMatchesFingerprint()` via reflection
- Traces: `$snapshot->uid === null` (line 541) → true → logs warning → returns false ✅
- Asserts `false` ✅
- **Correctly exercises the unreadable-UID-on-alive-process branch.**

### F-2 fix test: `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` (line 1653)

- Creates fingerprint: `pid=1234, startTime=100, uid=posix_getuid()`
- Creates snapshot: `state='S', startTime=0, uid=posix_getuid()`
- Calls `snapshotMatchesFingerprint()` via reflection
- Traces: uid not null → uid matches → `fingerprint->startTime > 0` (100) → `$snapshot->startTime === 0` (line 564) → true → logs warning → returns false ✅
- Asserts `false` ✅
- **Correctly exercises the unreadable-start-time-on-alive-process branch.**

### Other new tests — verified correct

- `testReadProcessStateFromStatReturnsStateForRunningPid`: forks child, reads state, asserts non-null and non-'Z'. Correct.
- `testReadProcessStateFromStatReturnsNullForNonExistentPid`: uses PID 999999999, asserts null. Correct.
- `testReadProcessStateFromStatReturnsZForZombie`: forks child, kills, polls until state 'Z', asserts 'Z' and `isProcessAlive()` false. Correct.
- `testReadLinuxProcessSnapshotReturnsValidSnapshot`: forks child, reads snapshot, asserts all fields. Correct.
- `testReadLinuxProcessSnapshotReturnsNullForNonExistentPid`: uses PID 999999999, asserts null. Correct.
- `testMatchesFingerprintFailsClosedForZombieProcess`: forks child, captures fingerprint, kills, waits for zombie, asserts `matchesFingerprint()` false. Correct — this is the "process dying mid-verification" test.
- `testMatchesFingerprintFailsClosedForMismatchedUid`: forks child, builds fingerprint with wrong UID, asserts false. Correct.
- `testMatchesFingerprintFailsClosedForMismatchedStartTime`: forks child, builds fingerprint with wrong start time (1), asserts false. Correct.
- `testMatchesFingerprintFailsClosedForNonExistentPidSnapshot`: uses PID 999999999, no `@requires OS Linux` (runs on all platforms). Correct — on Linux the snapshot is null → false; on non-Linux `isProcessAlive()` returns false → false.
- `testProcessSnapshotIsAlive`: pure unit test of DTO, no `@requires OS Linux`. Correct.

## New findings

### F-5: Stale/misleading docblock on `testMatchesFingerprintFailsClosedForMismatchedUid` (low)

**File:** `tests/ProcessInspectorTest.php:1513-1519`
**What is wrong:** The first paragraph of the docblock describes the old test gap from round 1: "the real fail-closed path for unreadable UID is covered by the snapshot returning uid=null. We test that path directly via readLinuxProcessSnapshot by verifying that a non-existent PID returns null (uid unreadable)." This was the round-1 rationale for why the test covered mismatch instead of unreadable. Now that `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` directly exercises the unreadable-UID branch, this preamble is stale and confusing — it suggests the test does something it doesn't. The second paragraph correctly describes the test. The first paragraph should be removed or rewritten to just say "This test verifies the mismatched-UID fail-closed."
**Severity:** low — documentation clarity only, no functional impact.
**Automated check:** None — stale docblocks are not caught by any standard tool.

### F-6: Unnecessarily restrictive `@requires OS Linux` on crafted-snapshot tests (low)

**File:** `tests/ProcessInspectorTest.php:1625,1651`
**What is wrong:** `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` and `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` carry `@requires OS Linux`, but neither test reads `/proc` or uses any Linux-specific functionality. They create synthetic `ProcessSnapshot` and `MasterFingerprint` objects and call `snapshotMatchesFingerprint()` via reflection — a pure comparison method that only accesses object properties and `$this->logger`. The only platform-sensitive call is `\posix_getuid()`, which is available on all POSIX platforms including macOS. Compare with `testProcessSnapshotIsAlive` and `testMatchesFingerprintFailsClosedForNonExistentPidSnapshot`, which correctly omit `@requires OS Linux`. The annotation needlessly skips these tests on macOS, reducing cross-platform coverage of the fail-closed branches.
**Severity:** low — tests are correct but over-restricted; they will run on Linux CI which is the primary platform.
**Automated check:** None — PHPUnit does not flag over-restrictive `@requires` annotations.

## F-3 and F-4 disposition assessment

**F-3 (duplicated /proc stat parsing):** The "find last `)` then split" pattern appears in `readProcessStateFromStat()`, `readLinuxProcessSnapshot()`, and `MasterFingerprint::readStartTimeForPid()`. The disposition "deliberately not fixed — out of scope" is reasonable: (1) the /proc stat format is a stable kernel ABI, (2) the duplicated code is ~10 lines per site, (3) extracting a shared helper would touch `MasterFingerprint` which is not in this issue's scope, (4) no functional impact. A follow-up refactor issue would be the right venue. **Disposition confirmed reasonable.**

**F-4 (FQCN nit):** `\CrazyGoat\WorkermanBundle\ProcessSnapshot` is used as FQCN on lines 129 and 539, consistent with the existing convention (`\CrazyGoat\WorkermanBundle\MasterFingerprint` on lines 177, 228). php-cs-fixer passes clean. **Disposition confirmed: not a real finding.**

## Candidate knowledge-base entries

No new candidate entries for this round. The round-1 candidate (snapshot-based /proc reading for process identity verification) remains the only candidate and is still relevant.

## Summary

The round-1 findings F-1 and F-2 are properly fixed: the new `snapshotMatchesFingerprint()` private method provides a clean test seam, and the two crafted-snapshot reflection tests directly exercise the unreadable-UID and unreadable-start-time fail-closed branches with alive-process snapshots. I verified the test traces through the code to confirm the correct branches are hit. F-3 and F-4 dispositions are reasonable.

Two new low-severity findings:
- F-5: A stale docblock on the mismatched-UID test still describes the round-1 gap that is now closed.
- F-6: Two crafted-snapshot tests carry `@requires OS Linux` unnecessarily, reducing macOS coverage.

No high or medium findings. Fail-closed semantics are fully preserved across all degraded cases. PHPStan level 8, php-cs-fixer, and all tests pass. No PHP 8.3+ syntax in the diff.
