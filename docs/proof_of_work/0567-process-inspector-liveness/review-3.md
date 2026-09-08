# Review Round 3 — Issue #567: ProcessInspector liveness poll /proc read reduction

**Branch:** `perf/issue-567-processinspector-isprocessalive-reads-an`
**Round-3 commit:** `d93adab` — "docs: fix stale docblock; test: drop needless @requires on crafted-snapshot tests"
**Files reviewed (round-3 diff):** `tests/ProcessInspectorTest.php`
**Full diff also re-verified:** `src/ProcessInspector.php`, `src/ProcessSnapshot.php`, `CHANGELOG.md`
**Date:** 2026-09-08

## Helpers loaded (tag index)

Tags matching the diff files (`tests/ProcessInspectorTest.php`, `src/ProcessInspector.php`, `src/ProcessSnapshot.php`):

- `faq.md`: FAQ-007 (tests,grpc,macos,daemon,process), FAQ-014 (tests,phpstan), FAQ-030 (tests,process), FAQ-032 (ci,tests,process), FAQ-037 (php82,ci,lint,tests)
- `decisions.md`: DEC-009 (knowledge-base,process,policy), DEC-014 (memory,long-running,http,tests)

No violations of documented decisions found.

## Earlier-round findings disposition

| # | Round | Finding | Disposition | Evidence |
|---|---|---|---|---|
| F-1 | 1 | Missing test for unreadable UID with alive process (medium) | **fixed** (confirmed) | `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` (line 1621) creates a crafted snapshot (state='S', startTime=100, uid=null) and calls `snapshotMatchesFingerprint()` via reflection. The method hits `$snapshot->uid === null` (src line 541) → logs warning → returns false. Test passes on macOS (verified: `phpunit --filter 'testSnapshotMatchesFingerprintFailsClosedForUnreadable'` → OK, 2 tests, 2 assertions). ✅ |
| F-2 | 1 | Missing test for unreadable start time with alive process (medium) | **fixed** (confirmed) | `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` (line 1645) creates a crafted snapshot (state='S', startTime=0, uid=posix_getuid()) and calls `snapshotMatchesFingerprint()` via reflection. The method passes the UID check, enters `fingerprint->startTime > 0` (100 > 0), hits `$snapshot->startTime === 0` (src line 564) → logs warning → returns false. Test passes on macOS. ✅ |
| F-3 | 1 | Duplicated /proc/{pid}/stat parsing logic in three places (low) | **still present** (deliberately not fixed) | The "find last `)` then split" pattern exists in `readProcessStateFromStat()` (src line 459), `readLinuxProcessSnapshot()` (src line 503), and `MasterFingerprint::readStartTimeForPid()` (src line 90). Coder marked out of scope. Disposition reasonable: stable kernel ABI, small duplication, shared helper would cross class boundaries. No functional impact. |
| F-4 | 1 | FQCN used instead of import for ProcessSnapshot (nit) | **not a real finding** (confirmed) | `\CrazyGoat\WorkermanBundle\ProcessSnapshot` is used as FQCN on src lines 129 and 539, consistent with existing convention (`\CrazyGoat\WorkermanBundle\MasterFingerprint` on src lines 177, 228 in test file). php-cs-fixer passes clean. |
| F-5 | 2 | Stale/misleading docblock on `testMatchesFingerprintFailsClosedForMismatchedUid` (low) | **fixed** | The old first paragraph described the round-1 test gap ("the real fail-closed path for unreadable UID is covered by... a non-existent PID returns null") which is now closed. The new docblock (lines 1512-1519) states precisely: "Fail-closed: `matchesFingerprint()` must return false for a live process whose UID does not match the fingerprint. The PID and start time match, but the UID is different — the UID check must refuse." It also points readers to the correct test for the unreadable-UID branch. The `@requires OS Linux/pcntl/posix` annotations are correctly preserved — this test genuinely forks via `pcntl_fork()`. ✅ |
| F-6 | 2 | Unnecessarily restrictive `@requires OS Linux` on crafted-snapshot tests (low) | **fixed** | `@requires OS Linux` removed from both `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess` (was line 1625) and `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess` (was line 1651). Verified both test bodies are platform-independent: synthetic `ProcessSnapshot`/`MasterFingerprint` objects, `\posix_getuid()` (available on macOS, ext-posix is a hard composer dependency), reflection-based `invokeSnapshotMatchesFingerprint()`. No `/proc` reads, no `pcntl_fork`. Both tests now PASS on macOS. ✅ |

## Automated checks run

| Check | Command | Result |
|---|---|---|
| PHPStan level 8 | `vendor/bin/phpstan analyse --level=8 src/ProcessInspector.php src/ProcessSnapshot.php tests/ProcessInspectorTest.php` | ✅ No errors |
| php-cs-fixer (dry-run) | `vendor/bin/php-cs-fixer fix -v --dry-run --path-mode=intersection` (per file) | ✅ 0 files to fix (all 3 files clean) |
| PHPUnit (ProcessInspectorTest) | `phpunit --no-coverage tests/ProcessInspectorTest.php` | ✅ 43 tests, 15 skipped (Linux-only), 0 failures |
| Crafted-snapshot tests on macOS | `phpunit --filter 'testSnapshotMatchesFingerprintFailsClosedForUnreadable'` | ✅ 2 tests, 2 assertions (previously skipped) |
| Pure unit tests on macOS | `phpunit --filter 'testProcessSnapshotIsAlive\|testMatchesFingerprintFailsClosedForNonExistentPidSnapshot'` | ✅ 2 tests, 5 assertions |
| PHP 8.2 syntax check (FAQ-037) | Manual review of diff for 8.3+ constructs | ✅ `final readonly class` is 8.1+; no `#[\Override]`, typed constants, property hooks, or anonymous readonly classes |

## Round-3 diff analysis

The round-3 commit (`d93adab`) is surgical — it touches only `tests/ProcessInspectorTest.php` and the proof-of-work docs:

### F-5 fix: docblock rewrite (lines 1512-1519)

**Old docblock** (removed):
```
 * Fail-closed: `matchesFingerprint()` must return false when the UID
 * is unreadable but the process is alive. On Linux this is simulated
 * by using a fingerprint with a startTime of 0 (so the start-time
 * check is skipped) and a mismatched UID — but the real fail-closed
 * path for unreadable UID is covered by the snapshot returning
 * uid=null. We test that path directly via readLinuxProcessSnapshot
 * by verifying that a non-existent PID returns null (uid unreadable).
 *
 * This test verifies the mismatched-UID fail-closed: a live process
 * whose UID does not match the fingerprint must be refused.
```

**New docblock** (added):
```
 * Fail-closed: `matchesFingerprint()` must return false for a live
 * process whose UID does not match the fingerprint. The PID and start
 * time match, but the UID is different — the UID check must refuse.
 *
 * (The separate unreadable-UID branch — `$snapshot->uid === null` — is
 * covered by `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess`.)
```

Assessment: The new docblock is accurate and clear. It correctly describes what the test does (mismatched-UID fail-closed) and provides a pointer to the unreadable-UID test. The `@requires OS Linux/pcntl/posix` annotations are correctly preserved because the test forks a real child process via `pcntl_fork()`.

### F-6 fix: removal of `@requires OS Linux` (lines 1617-1618, 1641-1642)

Two lines removed:
- `* @requires OS Linux` from `testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess`
- `* @requires OS Linux` from `testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess`

Assessment: Both test bodies verified platform-independent. The only platform-sensitive call is `\posix_getuid()`, which is available on macOS. The `ext-posix` extension is a hard dependency in `composer.json` (line 33: `"ext-posix": "*"`), so it is always present. No `/proc` reads, no `pcntl_fork`, no Linux-specific kernel facilities. The removal restores macOS coverage of the fail-closed branches.

## Coder round-3 self-findings assessment

The coder raised three self-findings (findings-coder.md items 8, 9, 10):

| # | Coder finding | Assessment |
|---|---|---|
| 8 | Hardcoded PID 1234/startTime 100 in crafted-snapshot tests (readability) | **Not a real finding.** `snapshotMatchesFingerprint()` does not check the PID at all — the PID check happens in `matchesFingerprint()` before calling the extracted method. The PID 1234 and startTime 100 are arbitrary values for the fingerprint side against which the snapshot-side unreadable branch fails closed. They are not "matched" against anything in the test. Cosmetic only. |
| 9 | Mismatch tests remain Linux-only by necessity (note only) | **Confirmed, not a finding.** The mismatch tests (`testMatchesFingerprintFailsClosedForMismatchedUid`, `testMatchesFingerprintFailsClosedForMismatchedStartTime`) correctly retain `@requires OS Linux` because they fork real processes and read `/proc/{pid}/stat`. This is intentional and unchanged. |
| 10 | Missing `@requires extension posix` on crafted-snapshot tests | **Not a real finding.** `ext-posix` is a hard dependency in `composer.json` (line 33). The extension is always present when the bundle is installed. PHPUnit will never encounter a missing posix extension in this project. The pre-existing tests that lack the annotation (`testMatchesFingerprintFailsClosedForNonExistentPidSnapshot`, `testProcessSnapshotIsAlive`) follow the same pattern. Adding the annotation would be harmless but is unnecessary. |

## New findings

**No new findings.** The round-3 changes are minimal, surgical, and correct. All automated checks pass. The two fixes address the round-2 low findings precisely as described.

## Summary

All six findings from rounds 1–2 are resolved:
- F-1, F-2: Fixed in round 1 (crafted-snapshot reflection tests). Confirmed still fixed.
- F-3: Deliberately not fixed (out of scope). Disposition reasonable.
- F-4: Not a real finding (consistent codebase style). Confirmed.
- F-5: Fixed in round 3 (docblock rewrite). Verified accurate.
- F-6: Fixed in round 3 (`@requires OS Linux` removed). Verified tests pass on macOS.

No new findings. The branch is ready to merge.
