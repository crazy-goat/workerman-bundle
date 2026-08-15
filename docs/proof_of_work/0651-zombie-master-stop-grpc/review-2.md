# Review — round 2, issue #651

Reviewer role: review-critical (round 2). Branch
`fix/issue-651-workerman-server-stop-can-time-out-and-l`, fix commit
4a1332f on top of a7d5775 (round-1 baseline). Scope: verify round-1
findings R1–R4 from `findings-review.md`, then hunt for new issues
introduced by the fixes. Knowledge-base entries consulted first, per TAG
INDEX: FAQ-007 (grpc/macos/daemon), FAQ-008 (daemon/logging), FAQ-009
(daemon/ports), FAQ-016 (control-plane/master fail-closed policy),
DEC-006 (security hardening must stay intact), DEC-009, DEC-011
(process/policy).

## Round-1 finding verification

### R1 (medium) — fail-open on abnormal `ps` exit: **FIXED**

`src/ProcessInspector.php:380-391` now re-checks `posix_kill($pid, 0)`
on any non-zero exit other than 126/127:

```php
if ($exitCode !== 0) {
    if (posix_kill($pid, 0)) {
        $this->logger->warning('... ps exited non-zero on a signalable PID; treating process as alive', ...);
        return null;
    }
    return ''; // PID unsignalable + ps failed: process gone
}
```

The discriminator is correct:

- **ps failed on a live PID** → `posix_kill($pid, 0)` true → `null` →
  `isAliveNonLinux()` line 332-334 treats as alive + warning. Fail-closed.
  This is unambiguous evidence that `ps` — not the process — misbehaved,
  because `posix_kill($pid, 0)` already passed at the `isProcessAlive()`
  entry guard (line 36).
- **ps failed on a dead PID** → unsignalable → `''` → dead. Correct.

**TOCTOU analysis (explicitly asked):** no harmful race exists between
the entry `posix_kill($pid, 0)` (line 36) and the recheck (line 381):

- Process dies between the two checks → recheck false → `''` → dead,
  which is the *correct* answer at decision time.
- Process alive throughout → `null` → alive; if it dies immediately
  after, the next `Wait::until` poll catches it.
- EPERM (process exists, owned by another user) cannot newly appear at
  the recheck: the entry guard already required `posix_kill` to succeed,
  and a process's UID cannot change. So the recheck's false means
  genuinely gone, not "permission denied".
- PID reuse between the failed `ps` and the recheck resolves in the
  fail-safe direction (reports alive → stop retries/times out instead of
  false success). Same inherent PID-reuse window round 1 already
  accepted.
- One inherent trade-off: a **non-child zombie** with a broken `ps`
  yields `posix_kill(zombie, 0) === true` → `null` → alive → stop times
  out. That is the documented fail-closed policy (FAQ-016) operating in
  a degraded environment (ps genuinely broken); there is no other way to
  detect a non-child zombie on macOS. Acceptable, not a finding.

### R2 (low) — fail-closed branches untested: **FIXED**

Four new tests in `tests/ProcessInspectorTest.php`, all
`@requires OS Darwin` (the ps path is non-Linux only):

- `testReadProcessStateViaPsReturnsEmptyForNonExistentPid` (line 893):
  reflection-invokes `readProcessStateViaPs(999_999_999)` → ps exits 1,
  PID unsignalable → asserts `''`. Exercises the R1 "unsignalable →
  gone" branch. Deterministic (real ps on a guaranteed-absent PID).
- `testReadProcessStateViaPsReturnsStateForRunningPid` (line 912):
  forks a running child → ps exit 0 → asserts non-empty, non-`Z` state.
  Exercises the exit-0 happy path. Deterministic.
- `testIsAliveNonLinuxReturnsFalseForNonExistentPid` (line 952):
  `pcntl_waitpid` → −1 (ECHILD) → ps → `''` → asserts false. Covers the
  full non-child dead path end-to-end. Deterministic.
- `testIsAliveNonLinuxReturnsTrueForRunningPid` (line 969): forked
  child → asserts true. Valid assertion; see N2 below for a docblock
  inaccuracy about *which* branch it exercises.

Test run on this host (macOS, PHP 8.5.9): **26 tests, 42 assertions, 5
skipped (Linux-only), 0 failures**; all four new tests executed and
passed (`--testdox` confirms). The exec-disabled/126/127 guards remain
untested — accepted in round 1 as defensive PHP-level checks.

### R3 (nit) — marker-file race: **FIXED**

`tests/ProcessInspectorTest.php:279-281`: child A now writes to
`$marker . '.' . bin2hex(random_bytes(4)) . '.tmp'` then
`rename($tmp, $marker)`. Both paths live in `sys_get_temp_dir()` (same
filesystem), so the rename is atomic and the parent's
`file_exists($marker)` poll can never observe an empty marker. The
residual leak window (A killed between write and rename, leaving a
stray `.tmp`) is a microsecond window with a harmless artifact in /tmp.
Not a finding.

### R4 (nit) — docblock overstates `''`: **FIXED**

`src/ProcessInspector.php:340-346` now reads: "an empty string when the
process no longer exists (ps exit 0 with no output, or non-zero exit on
an unsignalable PID), or null when `ps` itself could not be executed or
exited abnormally on a still-signalable PID (the caller must then fail
closed — treat as alive)". This matches the code exactly: exec-disabled
→ null (line 357), 126/127 → null (line 370), non-zero + signalable →
null (line 387), non-zero + unsignalable → `''` (line 390), exit 0 +
empty → `''` (line 394).

## New findings (introduced by or exposed by the fixes)

| # | file:line | What is wrong | Severity | Status |
| --- | --- | --- | --- | --- |
| N1 | `src/ProcessInspector.php:373-375` | **Inline comment contradicts the code it annotates.** "An empty result on a still-signalable PID means the process is gone" — in the non-zero-exit branch this comment introduces, an empty result on a still-signalable PID is treated as *alive* (`return null`, line 387); "gone" is returned only for an *unsignalable* PID (line 390). The sentence reads like a leftover from the pre-fix logic and is exactly the class of docblock/code mismatch R4 was about. One-word-class fix ("unsignalable") or rewording. | nit | OPEN |
| N2 | `tests/ProcessInspectorTest.php:961-964` | **Test docblock misdescribes the branch under test.** `testIsAliveNonLinuxReturnsTrueForRunningPid` claims to cover "the happy path after the fix — ps returns a non-Z state", but the forked child is a *direct* child of the test process, so `pcntl_waitpid()` returns 0 and the method returns at the direct-child branch (`ProcessInspector.php:327-329`) without ever invoking `ps`. The assertion is valid and deterministic; only the docblock is wrong. The non-child ps happy path is in fact covered by `testIsProcessAliveReturnsTrueForNonChildProcess` (line 222, targets `posix_getppid()`), so coverage is complete — this is a comment fix only. | nit | OPEN |
| N3 | `src/ProcessInspector.php:381-388` | **The R1 fail-closed branch itself (non-zero ps exit on a signalable PID → null) has no test.** The four R2 tests cover exit-0-with-output, non-zero+unsignalable, and the end-to-end dead/running paths, but the *core* of the R1 fix — ps misbehaving on a live PID must yield alive+warning — is only reachable by making `ps` fail artificially. Round 1 noted the lever: `@exec` at line 362 is *unqualified* inside namespace `CrazyGoat\WorkermanBundle`, so a test file defining `CrazyGoat\WorkermanBundle\exec()` could simulate exit 1 on a live PID deterministically, even on Linux CI. The fixer chose real-ps Darwin tests instead; reasonable, since a namespaced `exec()` override is global to the namespace for the whole PHPUnit process and risks polluting other tests. Noting as residual coverage gap, not a blocker: the branch fails closed, and its two sub-conditions (posix_kill recheck true/false) are each individually exercised. | low | OPEN (acceptable) |

## Gates

- `vendor/bin/phpunit tests/ProcessInspectorTest.php`: 26 tests, 42
  assertions, 5 skipped (Linux-only on this host), 0 failures. The one
  PHPUnit warning is "No code coverage driver available" — environmental,
  benign.
- `composer lint`: green (php-cs-fixer, PHPStan level 8, Rector, kb-lint).

## Verdict

All four round-1 findings are FIXED with correct code, real tests, and an
accurate docblock. The R1 fix's alive/dead discriminator is sound and has
no harmful TOCTOU. New findings are two comment-level nits (N1, N2) and
one low residual coverage gap (N3) — none affect runtime behavior.
**Clean enough to merge.** N1/N2 are one-line comment corrections that
can ride along in any follow-up; no round 3 needed.
