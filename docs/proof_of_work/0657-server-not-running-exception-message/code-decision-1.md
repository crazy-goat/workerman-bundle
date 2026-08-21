# Code Decision 1 — Issue #657

## Approach

`ServerNotRunningException` now exposes three named static constructors on
top of the backward-compatible no-arg constructor:

- `::noPidFile()` — no pid file or empty.
- `::processDead(int $pid)` — PID not alive.
- `::unverifiable(int $pid, bool $hasFingerprint)` — PID alive but identity
  cannot be confirmed; two sub-messages for fingerprint-present (mismatch)
  vs fingerprint-absent (pre-0.25.0 / daemon-start window).

`ServerManager::getRunningMasterPid()` delegates to a new
`notRunningException(int $masterPid, ?MasterFingerprint $fingerprint)`
private helper that calls `ProcessInspector::isProcessAlive()` a second
time for diagnosis and returns the right variant. The fail-closed
behaviour (which exception is thrown and when) is unchanged — only the
message text differs.

## What was rejected

1. **Adding a `cause` enum property to the exception.** The operator only
   sees `$e->getMessage()` via `WorkermanCommand.php:75`; no code branches
   on the cause. An enum would be over-engineering for a message-quality
   issue.

2. **Moving the diagnosis into `ProcessInspector`.** The issue explicitly
   says "Do NOT modify ProcessInspector.php at all." The TOCTOU between
   `isMasterRunning()` and the second `isProcessAlive()` call is acceptable
   (at worst a minor message mislabel, not a behaviour change).

3. **Using `?string $message = null` in the constructor instead of a
   default.** A default string keeps the no-arg constructor call site
   (`new ServerNotRunningException()`) fully backward compatible without
   any deprecation notice, while named constructors carry the
   cause-specific text.

4. **New test methods for every scenario on every command.** Instead, I
   added message assertions to the existing no-pid-file / dead-process /
   fingerprint-mismatch / no-fingerprint tests for `stop()` and `reload()`,
   covering both "not running" and "unverifiable" paths. This keeps the
   test count stable while adding message coverage.

## Rector adjustment

Rector's `FlipTypeControlToUseExclusiveTypeRector` rule changed
`$fingerprint !== null` to `$fingerprint instanceof MasterFingerprint`.
Applied to satisfy the lint gate; the semantics are identical.

## CHANGELOG and docs

- Added a `### Changed` entry in CHANGELOG.md referencing #657.
- Updated UPGRADE.md (lines 27, 42, 56) to distinguish "not running" from
  "Cannot verify master process \<pid\>".
- Updated docs/security.md (line ~690) and README.md (line ~255) similarly.

## Proposed FAQ-016 wording update (not applied — single-writer rule)

FAQ-016 currently says control commands "refuse to signal" when the
fingerprint is missing. Suggested addition: note that since 0.25.x the
operator-facing message now distinguishes "Workerman is not running" (no
pid file / process dead) from "Cannot verify master process \<pid\>"
(running but unverifiable), naming the PID and cause in both cases.
