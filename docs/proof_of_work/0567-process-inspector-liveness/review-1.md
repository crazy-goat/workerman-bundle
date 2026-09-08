# Review Round 1 — Issue #567: ProcessInspector liveness poll /proc read reduction

**Branch:** `perf/issue-567-processinspector-isprocessalive-reads-an`
**Files reviewed:** `src/ProcessInspector.php`, `src/ProcessSnapshot.php` (new), `tests/ProcessInspectorTest.php`, `CHANGELOG.md`
**Date:** 2026-09-08

## Helpers loaded (tag index)

- `faq.md`: FAQ-007 (tests,grpc,macos,daemon,process), FAQ-030 (tests,process), FAQ-032 (ci,tests,process), FAQ-037 (php82,ci,lint,tests)
- `decisions.md`: DEC-006 (security,policy), DEC-009 (knowledge-base,process,policy)

No violations of documented decisions found. DEC-006 (master identification hardened, fail-closed) is preserved — see analysis below.

## Earlier-round findings

No `findings-review.md` existed prior to this round. This is round 1.

## Automated checks run

| Check | Result |
|---|---|
| `phpstan analyse --level=8` (ProcessInspector, ProcessSnapshot, ProcessInspectorTest) | ✅ No errors |
| `php-cs-fixer fix --dry-run --diff` (full repo) | ✅ 0 of 256 files to fix |
| `phpunit --filter ProcessInspectorTest` | ✅ 45 tests, 15 skipped (Linux-only), 0 failures |
| `phpunit --filter ProcessTest|MasterFingerprintTest` | ✅ 45 tests, 0 failures |
| `phpunit --filter MasterWorkerTest|ServerManagerTest|WorkermanCommandTest|ServicesConfiguratorTest` | ✅ 80 tests, 1 skipped, 0 failures |
| PHP 8.2 syntax compatibility (FAQ-037) | ✅ `final readonly class` is 8.2+; no 8.3+ constructs |

## Acceptance criteria verification

| Criterion | Status | Evidence |
|---|---|---|
| Zombie detection no longer reads whole /proc/{pid}/status, no /m regex | ✅ Pass | `isProcessAlive()` now calls `readProcessStateFromStat()` which reads `/proc/{pid}/stat` and uses `strrpos`/`trim`/`$state[0]` — no `file_get_contents` of status, no `preg_match` |
| Zombies not-alive, running alive (existing tests + StuckChildRunner fixture) | ✅ Pass | Existing tests unchanged (no lines removed from test file); all pass. New tests `testReadProcessStateFromStatReturnsZForZombie` and `testReadProcessStateFromStatReturnsStateForRunningPid` add direct coverage |
| matchesFingerprint() bounded documented number of /proc reads | ✅ Pass | Docblock (lines 89-92) documents "two bounded /proc reads per call". Code: `readLinuxProcessSnapshot()` reads `/proc/{pid}/stat` once + `MasterFingerprint::readUidForPid()` reads `/proc/{pid}/status` once = 2 reads |
| Fail-closed preserved for unreadable UID — with a test | ⚠️ Partial | See finding F-1 below |
| Fail-closed preserved for unreadable start time — with a test | ⚠️ Partial | See finding F-2 below |
| Fail-closed preserved for process dying mid-verification — with a test | ✅ Pass | `testMatchesFingerprintFailsClosedForZombieProcess` — forks child, captures fingerprint, kills child, waits for zombie, asserts `matchesFingerprint()` returns false |
| Non-Linux behaviour unchanged | ✅ Pass | Non-Linux branch restructured but same logic: `isProcessAlive()` → `posix_getuid()` check. All macOS tests pass |
| Existing ProcessInspectorTest, ProcessTest, MasterFingerprintTest pass | ✅ Pass | All run green |
| CHANGELOG.md entry under [Unreleased] | ✅ Pass | Present, detailed, references #567 |

## Behavioral analysis

### isProcessAlive() fail direction — preserved

Old: unreadable `/proc/{pid}/status` → return `true` (alive).
New: unreadable `/proc/{pid}/stat` → `readProcessStateFromStat()` returns `null` → `null !== 'Z'` is `true` → return `true` (alive).
Same fail direction. ✅

### matchesFingerprint() Linux path — fail-closed mapping verified

| Old path | New path | Preserved |
|---|---|---|
| `isProcessAlive()` false (top of method) | snapshot null or `isAlive()` false | ✅ |
| `readUidForPid()` null + process dead | snapshot null or `isAlive()` false (state Z/null) | ✅ |
| `readUidForPid()` null + process alive | snapshot `isAlive()` true + uid null → false + warning | ✅ |
| UID mismatch | snapshot uid !== fingerprint uid → false + warning | ✅ |
| `readStartTimeForPid()` 0 + process dead | snapshot null or `isAlive()` false | ✅ |
| `readStartTimeForPid()` 0 + process alive | snapshot `isAlive()` true + startTime 0 → false + warning | ✅ |
| startTime mismatch | snapshot startTime !== fingerprint startTime → false + warning | ✅ |

### matchesFingerprint() non-Linux path — unchanged

Old: `isProcessAlive()` (top) → `posix_getuid()` check.
New: `isProcessAlive()` (in else branch) → `posix_getuid()` check.
Same logic, same order. ✅

### ProcessSnapshot DTO — correct

- `final readonly class` — PHP 8.2+ compatible, matches existing `ProcessInspector`.
- `isAlive()`: `state !== null && state !== 'Z'` — correct fail-closed (null state = unreadable = not alive).
- PSR-4 autoloading correct (`src/ProcessSnapshot.php` in `CrazyGoat\WorkermanBundle`).

### Read count analysis

- `isProcessAlive()` alone: 1 read (`/proc/{pid}/stat`) — was 1 read (`/proc/{pid}/status`). Same count, smaller file.
- `matchesFingerprint()` alone: 2 reads (stat + status) — was up to 5. Improvement.
- `isMasterRunning()` → `matchesFingerprint()`: 3 reads (1 from `isProcessAlive` + 2 from snapshot) — was up to 6. Improvement.

## Findings

### F-1: Missing test for unreadable UID with alive process (medium)

**File:** `tests/ProcessInspectorTest.php`
**What is wrong:** The acceptance criteria require a test for "fail-closed preserved for unreadable UID." The test `testMatchesFingerprintFailsClosedForMismatchedUid` (line 1528) tests UID **mismatch**, not UID **unreadable**. The test docblock acknowledges this gap: "the real fail-closed path for unreadable UID is covered by the snapshot returning uid=null" — but the only test for null uid is `testReadLinuxProcessSnapshotReturnsNullForNonExistentPid` (line 1448), which tests the **entire snapshot** being null (process gone), NOT the case where the process is alive (stat readable) but status is unreadable (uid=null while state is non-Z).

The `$snapshot->uid === null` branch in `matchesFingerprint()` (line 133) is not directly exercised by any test. A test that covers this branch would need to either mock `readUidForPid()` to return null while `readLinuxProcessSnapshot()` returns a non-null snapshot, or use a fixture that simulates an alive process with an unreadable `/proc/{pid}/status`.

**Severity:** medium — the code path is correct by inspection, but the acceptance criterion explicitly requires a test and none directly covers it.
**Automated check:** A coverage report would show the `$snapshot->uid === null` branch (line 133) as not covered on Linux CI.

### F-2: Missing test for unreadable start time with alive process (medium)

**File:** `tests/ProcessInspectorTest.php`
**What is wrong:** The acceptance criteria require a test for "fail-closed preserved for unreadable start time." The test `testMatchesFingerprintFailsClosedForMismatchedStartTime` (line 1576) tests start time **mismatch**, not start time **unreadable** (where `snapshot->startTime === 0` but `fingerprint->startTime > 0` and the process is alive).

The `$snapshot->startTime === 0` branch in `matchesFingerprint()` (line 156) is not directly exercised by any test. On a running Linux process with a readable `/proc/{pid}/stat`, the start time (field 22) will always be > 0, so this branch can only be hit when the stat file is readable but field 22 is missing/malformed — a scenario that requires mocking or a crafted fixture.

**Severity:** medium — same reasoning as F-1. The code is correct by inspection but the acceptance criterion explicitly requires a test.
**Automated check:** A coverage report would show the `$snapshot->startTime === 0` branch (line 156) as not covered on Linux CI.

### F-3: Duplicated /proc/{pid}/stat parsing logic (low)

**File:** `src/ProcessInspector.php:487-516` (`readProcessStateFromStat`), `src/ProcessInspector.php:534-565` (`readLinuxProcessSnapshot`), `src/MasterFingerprint.php:72-106` (`readStartTimeForPid`)
**What is wrong:** The "find last `)` then split" parsing of `/proc/{pid}/stat` is now duplicated in three places. If the parsing needs to change, all three must be updated consistently. The coder noted this in findings-coder.md #3 and marked it out of scope.
**Severity:** low — the parsing is stable and the duplication is small. No functional impact.
**Automated check:** A custom lint for duplicated parsing blocks could catch this, but no standard tool would.

### F-4: FQCN used instead of import for ProcessSnapshot (nit)

**File:** `src/ProcessInspector.php:129`, `tests/ProcessInspectorTest.php` (multiple lines)
**What is wrong:** `\CrazyGoat\WorkermanBundle\ProcessSnapshot` is used as a FQCN instead of importing the class. However, this is consistent with the existing convention in this file (`\CrazyGoat\WorkermanBundle\MasterFingerprint` is used the same way on lines 220, 271), and in the test file (all `MasterFingerprint` references use FQCN).
**Severity:** nit — consistent with existing style, no functional impact.
**Automated check:** php-cs-fixer passed clean, so the project's style rules accept this.

## Candidate knowledge-base entries

### Candidate 1: Snapshot-based /proc reading for process identity verification

- **Tags:** process, performance
- **Trigger:** "refactoring matchesFingerprint() or readLinuxProcessSnapshot() to change how many /proc reads happen per verification"
- **Paragraph:** `ProcessInspector::matchesFingerprint()` takes a single `ProcessSnapshot` (state + start time from `/proc/{pid}/stat`, UID from `/proc/{pid}/status`) — 2 bounded reads — instead of calling `isProcessAlive()` up to 3 times plus `readUidForPid()` and `readStartTimeForPid()` separately (up to 5 reads). The snapshot's `isAlive()` (state non-null and non-`Z`) subsumes the separate liveness checks; an unreadable stat → null snapshot → false, a zombie state `Z` → false, an unreadable UID → null uid → false, an unreadable start time → 0 → false. Fail-closed is preserved across all degraded cases because the snapshot captures state and identity atomically enough that a process dying mid-verification shows up as `Z` or null in the state field. `isProcessAlive()` separately reads only `/proc/{pid}/stat` field 3 (state character) — 1 read — instead of slurping `/proc/{pid}/status` and running a `/m` regex. (Issue #567.)

## Summary

The implementation correctly addresses the performance issue (#567) while preserving all fail-closed semantics. The code is clean (PHPStan level 8, php-cs-fixer, all tests pass). Two acceptance criteria are partially met: the tests for "unreadable UID" and "unreadable start time" cover mismatch cases instead of unreadable cases. The code paths are correct by inspection, but the tests don't directly exercise the unreadable-field branches. These are medium-severity gaps that could be closed with mocked tests or by restructuring the snapshot to allow injecting a null UID/start time on a live process.
