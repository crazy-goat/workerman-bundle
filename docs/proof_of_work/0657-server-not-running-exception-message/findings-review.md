# Findings — Review — Issue #657

Round 1 (no earlier rounds; nothing to revisit). Gates: `composer lint`
PASS; `phpunit tests/ServerManagerTest.php tests/ProcessInspectorTest.php`
PASS (69 tests, 6 skipped, no failures).

| # | file:line | what is wrong | severity | what happened to it |
|---|-----------|---------------|----------|---------------------|
| R1-1 | tests/ServerManagerTest.php:748-750, 791-794 | Fingerprint-mismatch tests assert `'fingerprint'`, a substring the no-sidecar message also contains — a swap of the two `unverifiable()` branches (or of `$hasFingerprint` at ServerManager.php:210) would pass. Should assert `'does not match'`. No automated check can catch a weak substring assertion; fix in this PR. | low | **fixed** — both mismatch tests now assert `'does not match'` (pins the hasFingerprint=true sub-case) |
| R1-2 | tests/ServerManagerTest.php:914, 951 | getStatus/getConnections no-fingerprint tests assert only the exception type, unlike the stop/reload equivalents (:833-841, :872-880) which pin the message. Asymmetric coverage of the same `getRunningMasterPid()` path. | low | **fixed** — both tests now bind `$e` and assert `'Cannot verify'` + PID + `'no fingerprint sidecar'`, matching their stop/reload twins |
| R1-3 | docs/helpers/faq.md:278-293 (FAQ-016) | Entry says commands report "Workerman is not running." for the unverifiable case; since this PR they report "Cannot verify master process \<pid\>". Behavioral content (fail closed) still accurate. Single-writer rule (DEC-009): review only proposes. | low | open — candidate wording proposed in review-1.md §7; coder proposed the same in code-decision-1.md |
| R1-4 | src/ServerManager.php:213-225 + src/Exception/ServerNotRunningException.php:34-37 | Empty/garbage/negative pid file content collapses to pid 0 → "no pid file found" message although a pid file exists. Message accuracy only; `noPidFile()` docblock already concedes "(or empty/unreadable)". Never affects which/whether an exception is thrown. | nit | open — accepted approximation; no action needed |
| R1-5 | src/Exception/ServerNotRunningException.php:16 | Class docblock bullet for `noPidFile()` ends with ", process dead", duplicating the `processDead()` bullet and misattributing the dead-process case. | nit | **fixed** — bullet now reads "no pid file found (or empty/unreadable)." |
| R1-6 | docs/helpers/faq.md:281-284 | FAQ-016 body duplicates two lines verbatim. **Pre-existing** — verified this diff (d6aaf9d) does not touch faq.md. Also flagged in findings-coder.md item 1. | nit | open — for the retro step (DEC-009 single writer) |

## Verified non-findings (checked, no defect)

- Fail-closed behavior (FAQ-016 / #584): `notRunningException()` is called
  only after `isMasterRunning()` returned false; it always throws
  `ServerNotRunningException`; the diagnostic `isProcessAlive()`
  (ProcessInspector.php:34-53) is side-effect-free (signal 0 + read-only
  /proc). TOCTOU can only mislabel the message, never change whether/which
  exception is thrown. `restart()`'s catch-by-type (ServerManager.php:75)
  still catches all variants.
- BC: constructor widened with an optional parameter only; no-arg call
  sites unchanged; named constructors return `self` on a final class.
- PHPStan level 8, php-cs-fixer, Rector, check-changelog: all pass.
- PID in the message is not an information leak (pid file + ps already
  expose it; recovery docs require it).
- CHANGELOG.md:477 quoting the old flat message is a historical 0.25.0
  entry — correct as released history, must not be rewritten.
- `$fingerprint instanceof MasterFingerprint` vs `!== null`: identical
  semantics; mandated by Rector's FlipTypeControlToUseExclusiveTypeRector.
