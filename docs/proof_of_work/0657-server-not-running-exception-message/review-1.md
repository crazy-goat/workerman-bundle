# Review 1 — Issue #657 (review-critical)

## 0. Trigger verification

`review-critical` applies on three criteria (any one suffices):

- **Security-relevant code / policy** — the diff touches the fail-closed
  master-identification control plane (issue #584): `ServerManager::
  getRunningMasterPid()` decides whether `stop`/`reload`/`status` may
  signal a PID.
- **Process supervision** — master/worker identification, PID liveness,
  fingerprint sidecar handling.
- **Public interface** — `ServerNotRunningException` gains three new public
  named static constructors; the constructor signature changes (BC-preserving).
- Also: ~315 changed lines (> 200 threshold).

## Gates run

- `composer lint` — PASS (PHPStan level 8: no errors; php-cs-fixer: 0/252
  fixable; Rector: done, no changes; kb-lint: OK with one pre-existing
  faq.md line-budget warning; check-changelog: OK).
- `vendor/bin/phpunit tests/ServerManagerTest.php tests/ProcessInspectorTest.php`
  — PASS: 69 tests, 139 assertions, 6 skipped (platform/extension gates),
  1 runner warning (no coverage driver — environmental, not a defect).

## 1. docs/helpers check (TAG INDEX driven)

Tags matching this diff: `control-plane`, `master`, `upgrade` (FAQ-016);
`process`, `tests`; `knowledge-base` policy (DEC-009). Read: FAQ-016,
DEC-009. No violation of documented decisions found:

- **FAQ-016 (fail-closed since 0.25.0):** preserved — see section 2. The
  *wording* of FAQ-016 is now partially stale (it says commands report
  "Workerman is not running." in the unverifiable case, which is now
  "Cannot verify master process \<pid\>"). Not a decision violation; a
  candidate entry update is proposed in section 6 (single-writer rule,
  DEC-009 — I do not edit docs/helpers/).
- **DEC-009 (single writer):** respected by the coder (proposed wording in
  code-decision-1.md instead of editing faq.md) and by this review.

Pre-existing kb hygiene issues (confirmed **not** introduced by this diff —
faq.md is untouched in d6aaf9d): FAQ-016 body has duplicated lines
(faq.md:281-284 repeat verbatim) and faq.md is over its 300-line budget
(kb-lint warning, pre-existing). Both already flagged in findings-coder.md;
recorded here as nits for the retro step.

## 2. Behavior preservation (the critical question)

Verified by tracing `ServerManager::getRunningMasterPid()`
(src/ServerManager.php:177-187) and `notRunningException()` (:200-211):

- The throw site is unchanged: `if (!isMasterRunning(...)) throw ...`.
  `notRunningException()` is invoked **only** inside that branch, after the
  fail-closed decision is made. Whatever the helper returns, it is thrown —
  the helper cannot suppress, replace, or re-type the exception. It always
  returns a `ServerNotRunningException` (all three named constructors return
  `new self(...)`).
- The diagnosis calls `ProcessInspector::isProcessAlive()`
  (src/ProcessInspector.php:34-53): `posix_kill($pid, 0)` (signal 0 = pure
  existence check, no signal delivered) plus a read-only `/proc/<pid>/status`
  zombie check (Linux) / `isAliveNonLinux`. **No side effects**; safe to call
  a second time. It is already called multiple times on the same path
  (`isMasterRunning` → `matchesFingerprint` → `isProcessAlive`).
- **TOCTOU:** a master dying between `isMasterRunning()` and the diagnostic
  `isProcessAlive()` flips the message from "processDead" to "unverifiable";
  a PID reused in that microsecond window flips it the other way. Both are
  message-label inaccuracies only — the same exception type is thrown at the
  same point under the same conditions. Correctly documented in the helper's
  docblock and code-decision-1.md.
- `restart()`'s `catch (ServerNotRunningException)` (ServerManager.php:75)
  still catches all variants (single final class, named constructors return
  `self`). Confirmed by findings-coder.md item 3.
- Tests pin behavior: the four "refuses to signal" tests assert the child
  process is **still alive** after the refused signal (e.g.
  tests/ServerManagerTest.php:757-760) — the fail-closed guarantee is under
  test, not just the message.

Conclusion: FAQ-016's fail-closed behavior is **unchanged**. No finding.

## 3. Type correctness / BC

- `__construct(string $message = 'Workerman is not running.')` — the only
  prior call convention was `new ServerNotRunningException()`; adding an
  optional parameter is fully BC for callers. Named constructors return
  `self` on a `final` class — correct (no `static` late-binding concern).
- `$fingerprint instanceof MasterFingerprint` vs `!== null` — semantically
  identical here; Rector's `FlipTypeControlToUseExclusiveTypeRector` forced
  it and the lint gate passes. Fine.
- PHPStan level 8: no errors.

## 4. Findings (new, this round)

### LOW-1 — Fingerprint-mismatch message assertions cannot catch a branch swap
tests/ServerManagerTest.php:748-750 and :791-794
The mismatch tests assert `'Cannot verify'`, the PID, and `'fingerprint'`.
But the *no-sidecar* message ("...no fingerprint sidecar was found...") also
contains "Cannot verify" and "fingerprint" — so if `unverifiable()`'s two
branches (or the `$hasFingerprint` argument at ServerManager.php:210) were
swapped, the mismatch tests would still pass. Only the no-sidecar tests
(:838, :877, asserting `'no fingerprint sidecar'`) discriminate, and only in
one direction. Asserting `'does not match'` in the mismatch tests would pin
each sub-case. No automated check can catch a weak substring assertion; the
fix is one word per test, in this PR.

### LOW-2 — getStatus/getConnections no-fingerprint tests got no message assertions
tests/ServerManagerTest.php:914 and :951 catch the exception without binding
`$e`, while the stop/reload equivalents (:833-841, :872-880) assert
`'Cannot verify'` + PID + `'no fingerprint sidecar'`. All four commands
funnel through `getRunningMasterPid()`, so the uncovered surface is small —
but the asymmetry means a regression that made `getStatus()`/`getConnections()`
throw a *different* message variant would pass. Two-line fix per test; worth
doing in this PR for symmetry. (This answers the review-brief question: the
gap is acceptable-but-not-ideal; flag as low.)

### LOW-3 — FAQ-016 wording now stale (propose, do not write)
docs/helpers/faq.md:278-293. Title and body say the commands report
"Workerman is not running." after a 0.25.0 upgrade / in the daemon-start
window. Since this PR, the unverifiable case reports "Cannot verify master
process \<pid\>". The behavioral content (fail closed, recovery steps) stays
accurate. Candidate entry proposal in section 6. kb-lint cannot detect
content staleness — human/retro step only.

### NIT-1 — "no pid file" message also covers unparseable pid files
src/ServerManager.php:213-225: `getMasterPid()` returns 0 when the pid file
exists but is empty, negative, or non-numeric (`(int) 'abc' === 0`), and
`notRunningException()` maps all of these to
`noPidFile()` → "no pid file found". The `noPidFile()` docblock already
concedes "(or empty/unreadable)", so this is a known approximation; message
accuracy only, never behavior. Not worth a fourth named constructor.

### NIT-2 — Class docblock bullet misattributes "process dead"
src/Exception/ServerNotRunningException.php:16:
"`- {@see self::noPidFile()} — no pid file found (or empty), process dead.`"
— the trailing "process dead" duplicates bullet 2 (`processDead()`) and
reads as if noPidFile covers dead processes. Cosmetic.

### NIT-3 — Pre-existing FAQ-016 duplicated lines (not this diff)
docs/helpers/faq.md:281-284 duplicate verbatim. Confirmed pre-existing
(this diff does not touch faq.md). For the retro step, per DEC-009.

## 5. Docs accuracy sweep

- UPGRADE.md (:27, :43-51, :58-61), README.md (:252-258),
  docs/security.md (:690-693): all updated accurately; the recovery
  instructions match the new messages (verified against the actual strings
  in ServerNotRunningException.php:36,45,64,70).
- CHANGELOG.md: new `### Changed` entry accurate; check-changelog gate
  passes. The 0.25.0 *historical* entry (CHANGELOG.md:477) still quotes
  "Workerman is not running." — correct as a historical record of what
  0.25.0 did; rewriting released history would be wrong. No finding.
- Repo-wide grep for "Workerman is not running" found no other stale
  operator-facing references outside helpers (covered by LOW-3).

## 6. Security check

PID exposure in the message is not a leak: the PID is already in the
world-readable pid file and `ps` output, the message is shown to the CLI
operator running the control command, and the recovery procedures in
UPGRADE.md (`ps -p <pid> -o pid,comm,args`, `kill <pid>`) *require* the
operator to know it. The `kill %d` hint in the no-sidecar message matches
the documented manual recovery. The fail-closed direction is never
loosened — no signal path was added or widened.

## 7. Candidate docs/helpers entries (proposal only, per DEC-009)

1. **Update FAQ-016 in place** (not a new entry): append one sentence —
   "Since #657 the operator-facing message distinguishes the causes:
   'Workerman is not running (no pid file found ...)' / '(master process
   \<pid\> is not alive)' vs 'Cannot verify master process \<pid\>: ...'
   for the unverifiable cases; the fail-closed behavior is unchanged."
   Also delete the duplicated lines 283-284 while there. (Coder proposed
   the same in code-decision-1.md §"Proposed FAQ-016 wording update".)

## Verdict

**Approve with minor follow-ups.** No high/medium findings. Fail-closed
behavior (FAQ-016, #584) verified unchanged by code trace and by the
alive-after-refusal test assertions. Recommend addressing LOW-1 and LOW-2
in this PR (both are ≤4 lines of test changes); LOW-3 and the nits can go
to the retro step.
