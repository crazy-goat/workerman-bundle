# Review 2 — Issue #657 (review-critical)

## 0. Trigger verification

Same triggers as round 1, unchanged:

- **Security-relevant code / policy** — fail-closed master-identification
  control plane (#584): `ServerManager::getRunningMasterPid()` /
  `notRunningException()`.
- **Process supervision** — master identification, PID liveness,
  fingerprint sidecar.
- **Public interface** — `ServerNotRunningException` public named
  constructors.
- Cumulative diff (d6aaf9d + a019a23) exceeds 200 changed lines.

Round-2 delta (a019a23, `git diff d6aaf9d..HEAD -- src/ tests/`): 16 lines
changed — one docblock line in `ServerNotRunningException.php`, and test-
only assertion changes in `ServerManagerTest.php`. No production logic
changed this round.

## Gates run (this round)

- `composer lint` — PASS (PHPStan level 8: no errors; php-cs-fixer: 0/252
  fixable; Rector: done; kb-lint: OK with the same pre-existing faq.md
  line-budget warning; check-changelog: OK).
- `vendor/bin/phpunit tests/ServerManagerTest.php
  tests/ProcessInspectorTest.php` — PASS: 69 tests, **145** assertions
  (round 1: 139; +6 = the 3 new assertions in each of the getStatus and
  getConnections tests — consistent), 6 skipped (platform gates), 1
  environmental warning (no coverage driver — not a defect).

## 1. Prior findings — status

Full table appended to findings-review.md. Summary:

- **R1-1 — fixed, verified in code.** tests/ServerManagerTest.php:750 and
  :795 now assert `'does not match'`. Checked against the actual messages:
  `'does not match'` occurs ONLY in the `hasFingerprint=true` branch
  (ServerNotRunningException.php:64, "its identity does not match the
  recorded fingerprint"); `'no fingerprint sidecar'` occurs ONLY in the
  `hasFingerprint=false` branch (:70). Neither substring leaks into the
  other branch — the two sub-cases are now pinned in both directions; a
  branch swap or a `$hasFingerprint` flip at ServerManager.php:210 would
  fail a test.
- **R1-2 — fixed, verified in code.** tests/ServerManagerTest.php:914-918
  and :954-958 now bind `$e` and assert `'Cannot verify'` + PID +
  `'no fingerprint sidecar'`, matching the stop/reload twins (:838-840,
  :877-879). `$pid` is in scope in both methods (assigned by
  `forkSleepingChild()` at :903/:943).
- **R1-3 — still present, correctly deferred.** faq.md:278 title still
  quotes "Workerman is not running." for what is now the
  "Cannot verify master process <pid>" case. faq.md untouched by a019a23 —
  single-writer rule (DEC-009) reserves it for the retro step. Round 1
  missed nothing here: the behavioral content (fail closed, recovery
  steps) remains accurate, so the staleness is genuinely cosmetic.
- **R1-4 — still present, accepted approximation.** getMasterPid()
  (ServerManager.php:213-225) still collapses empty/garbage/negative pid
  file content to 0 → `noPidFile()` message. The noPidFile() docblock now
  concedes "(or empty/unreadable)" both in the class-level bullet (:16)
  and the method docblock (:31). Message-only, never behavior. Correctly
  accepted.
- **R1-5 — fixed, verified in code.** ServerNotRunningException.php:16
  now reads "no pid file found (or empty/unreadable)." — the
  misattributing ", process dead" tail is gone.
- **R1-6 — still present, correctly deferred.** faq.md:281-284 verbatim
  duplication confirmed still there; a019a23 touches only src/ + tests/ +
  proof-of-work. Retro step per DEC-009.

## 2. New issues introduced by the round-1 fixes

None. The a019a23 delta is 16 lines: one docblock line, two one-word
assertion swaps, and two tests gaining three assertions each. Reviewed
line by line:

- New assertion substrings were cross-checked against the literal message
  strings in ServerNotRunningException.php (:36, :45, :64, :70) — all
  four asserted substrings actually occur in the intended branch and are
  discriminating (see R1-1 above).
- The catch-variable binding (`catch (...Exception $e)`) is syntactically
  fine on the project's PHP version and php-cs-fixer passes.
- Assertion-count delta (139 → 145) exactly matches the expected +6; no
  assertion was accidentally dropped (the swapped assertions are 1:1).

## 3. Fail-closed invariant re-confirmed

No production code changed in a019a23, so the round-1 trace (section 2 of
review-1.md) still holds: `notRunningException()` is invoked only after
`isMasterRunning()` returned false; it always returns a
`ServerNotRunningException`; the diagnostic `isProcessAlive()` is
side-effect-free; `restart()`'s catch-by-type still catches all variants.
The alive-after-refusal assertions (e.g. tests/ServerManagerTest.php:758,
:926, :966) still pin the guarantee, and the suite passes. **Unchanged.**

## 4. docs/helpers proposals (no writes, per DEC-009)

No new candidates beyond round 1's §7 (update FAQ-016 in place + drop the
duplicated lines). That proposal stands and now covers R1-3 and R1-6
together.

## Verdict

**Approve.** All three round-1 fixes verified in code with correct,
discriminating assertions; the three deferrals are correctly characterized;
no new issues; both gates green; fail-closed behavior unchanged. Ready for
the retro/docs step (FAQ-016 update per DEC-009).
