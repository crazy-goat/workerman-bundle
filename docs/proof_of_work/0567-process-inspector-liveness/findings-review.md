# Findings Review — Issue #567: ProcessInspector liveness poll /proc read reduction

## Round 1

| # | File:line | What is wrong | Severity | Status |
|---|---|---|---|---|
| F-1 | tests/ProcessInspectorTest.php (no test at the `$snapshot->uid === null` branch, src/ProcessInspector.php:133) | Missing test for unreadable UID with alive process. `testMatchesFingerprintFailsClosedForMismatchedUid` tests UID mismatch, not unreadable UID. The `$snapshot->uid === null` branch in `matchesFingerprint()` is not directly exercised — `testReadLinuxProcessSnapshotReturnsNullForNonExistentPid` tests the entire snapshot being null (process gone), not alive process with unreadable status. Acceptance criterion explicitly requires this test. | medium | fixed — `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` (new, Linux-only, round 1 fix) |
| F-2 | tests/ProcessInspectorTest.php (no test at the `$snapshot->startTime === 0` branch, src/ProcessInspector.php:156) | Missing test for unreadable start time with alive process. `testMatchesFingerprintFailsClosedForMismatchedStartTime` tests start time mismatch, not unreadable start time (startTime=0 with fingerprint startTime>0 and process alive). The `$snapshot->startTime === 0` branch is not directly exercised. Acceptance criterion explicitly requires this test. | medium | fixed — `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` (new, Linux-only, round 1 fix) |
| F-3 | src/ProcessInspector.php:487-516, 534-565; src/MasterFingerprint.php:72-106 | Duplicated /proc/{pid}/stat parsing logic (find last `)` then split) in three places. If parsing needs to change, all three must be updated. Coder noted as out of scope. | low | deliberately not fixed — out of scope, could be a follow-up refactor issue |
| F-4 | src/ProcessInspector.php:129; tests/ProcessInspectorTest.php (multiple) | FQCN `\CrazyGoat\WorkermanBundle\ProcessSnapshot` used instead of import. Consistent with existing convention in the file (`MasterFingerprint` used the same way). | nit | not a real finding — consistent with existing codebase style |

## Round 2

| # | File:line | What is wrong | Severity | Status |
|---|---|---|---|---|
| F-5 | tests/ProcessInspectorTest.php:1513-1519 | Stale/misleading docblock on `testMatchesFingerprintFailsClosedForMismatchedUid`. The first paragraph describes the round-1 test gap ("the real fail-closed path for unreadable UID is covered by... a non-existent PID returns null") which is now closed by `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess`. The preamble is confusing — it suggests the test covers unreadable UID when it actually covers UID mismatch. Should be removed or rewritten. | low | open |
| F-6 | tests/ProcessInspectorTest.php:1625,1651 | `@requires OS Linux` on `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` and `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` is unnecessarily restrictive. Neither test reads `/proc` — they create synthetic snapshots and call the pure-comparison `snapshotMatchesFingerprint()` via reflection. `posix_getuid()` works on macOS. These tests are skipped on macOS, reducing cross-platform coverage of the fail-closed branches. Compare with `testProcessSnapshotIsAlive` and `testMatchesFingerprintFailsClosedForNonExistentPidSnapshot` which correctly omit the annotation. | low | fixed — `@requires OS Linux` removed from both tests (round 3); both now run on macOS |

## Round 3

Dispositions for the two low findings fixed in this round:

| # | File:line | Disposition | Evidence |
|---|---|---|---|
| F-5 | tests/ProcessInspectorTest.php:1512-1519 | **fixed** — the stale docblock's first paragraph (which described the now-closed round-1 unreadable-UID gap via "a non-existent PID returns null") was removed. The docblock now states precisely that the test verifies the mismatched-UID fail-closed branch (live process, matching PID/start-time, different UID → false), and points the unreadable-UID branch readers to `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` where it is actually covered. | F-5 open → fixed; docblock rewritten, php-cs-fixer + phpstan clean |
| F-6 | tests/ProcessInspectorTest.php:1626,1644 | **fixed** — `@requires OS Linux` removed from `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` and `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess`. Verified both test bodies are platform-independent: they construct synthetic `ProcessSnapshot`/`MasterFingerprint` objects, call `\posix_getuid()` (available on macOS) and the reflection-based `invokeSnapshotMatchesFingerprint()`. No `/proc` read, no `pcntl_fork`. | F-6 open → fixed; both tests now PASS on macOS (`phpunit --filter 'testSnapshotMatchesFingerprintFailsClosedForUnreadable'` → `OK (2 tests, 2 assertions)`), previously skipped |

### Round 3 review confirmation (review-3.md)

All earlier findings (F-1 through F-6) reviewed and dispositioned. No new findings.

| # | File:line | What is wrong | Severity | What happened to it |
|---|---|---|---|---|
| F-5 | tests/ProcessInspectorTest.php:1512-1519 | Stale/misleading docblock on `testMatchesFingerprintFailsClosedForMismatchedUid` | low | **fixed** (confirmed by review round 3) — docblock rewritten to accurately describe the mismatched-UID fail-closed test, with a pointer to the unreadable-UID test. New text is precise; `@requires OS Linux/pcntl/posix` annotations correctly preserved (test genuinely forks). |
| F-6 | tests/ProcessInspectorTest.php:1617,1641 | Unnecessarily restrictive `@requires OS Linux` on crafted-snapshot tests | low | **fixed** (confirmed by review round 3) — `@requires OS Linux` removed from both tests. Verified: both test bodies are platform-independent (synthetic objects, `\posix_getuid()`, reflection seam; no `/proc`, no `pcntl_fork`). Both tests PASS on macOS. `ext-posix` is a hard `composer.json` dependency so no `@requires extension posix` is needed. |

Coder self-findings (findings-coder.md items 8, 9, 10) assessed: none are real findings (item 8: cosmetic only, PID 1234 is arbitrary and unchecked by `snapshotMatchesFingerprint()`; item 9: note only, Linux-only mismatch tests are correct; item 10: `ext-posix` is a hard composer dependency, annotation unnecessary).
