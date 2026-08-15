# Review round 1 — issue #651: zombie master detection on macOS/BSD

**Role:** review-critical. **Commit:** a7d5775.
**Scope:** `src/ProcessInspector.php`, `tests/ProcessInspectorTest.php`,
`docs/troubleshooting.md`, proof-of-work docs.
**Verdict:** the fix is correct in its core logic and well tested for the
happy paths; one medium fail-open hole in the `ps` error handling (R1) and
three small items (R2–R4) to address. Details in `findings-review.md`.

## What was checked and how

1. **Read the knowledge base** per protocol: `docs/helpers/faq.md` index →
   FAQ-007 (grpc/macOS/daemon), FAQ-016 (fail-closed master
   identification); `docs/helpers/decisions.md` index → DEC-006
   (master-identification hardening must stay intact), DEC-013 (fail-open
   vs fail-closed philosophy for security-relevant checks).
2. **Read the full diff** against `origin/master` and the surrounding code
   (`ServerManager::stop()`, `Util\Wait`, `Utils::cpuCount()` guard,
   `MasterFingerprint` usage).
3. **Ran the gates:** `composer lint` — green (php-cs-fixer, PHPStan level
   8, Rector dry-run, kb-lint). `vendor/bin/phpunit
   tests/ProcessInspectorTest.php` on this Darwin+grpc host — 22 tests,
   37 assertions, 5 skipped (Linux-only), 0 failures.
4. **Live probes of the platform contract** on macOS (PHP 8.5.9):
   - `ps -o stat= -p <nonexistent>` → exit 1, empty stdout (stderr
     suppressed by the code's `2>/dev/null`) → parsed as "gone". Correct.
   - Forked A → A forked B and never reaped → B SIGKILLed itself:
     `pcntl_waitpid(B, …, WNOHANG)` from the grandparent returns `-1`
     (ECHILD), `ps -o stat= -p B` prints `Z`, exit 0. Exactly the #651
     shape; the new code reports it dead. Correct.
   - Running processes print `Ss  ` (trailing whitespace) — `trim()` at
     line 375 handles it; `str_starts_with($state, 'Z')` is the right
     test across macOS/BSD/Solaris (`Z` is zombie on all of them).

## Checklist results

**Type correctness (PHPStan 8):** clean. `readProcessStateViaPs(): ?string`
with the three-state contract (`string` state / `''` gone / `null`
unavailable) is sound; `@phpstan-impure` is present on both new methods and
on `isProcessAlive()`; `exec()` out-params are typed correctly.

**`pcntl_waitpid` semantics:** correct. `>0` reaps a zombie direct child
(dead — and the reaping side effect the test suite relies on is preserved);
`0` running direct child (alive); `-1` falls through to `ps`, which
correctly disambiguates ECHILD-not-my-child from ECHILD-no-such-process
(state line vs empty output). The die-between-`posix_kill`-and-`waitpid`
race resolves to "dead" on both paths. Correct.

**Error handling:** one hole — R1 (medium). Any `ps` exit code other than
0/126/127 is read as "process gone", which fails *open* (reports a live
process dead) when `ps` itself fails abnormally (sandboxed CLI, fork
exhaustion, non-conforming `ps`). The class otherwise documents and
implements fail-closed supervision; the fix is a `posix_kill($pid, 0)`
recheck before returning `''`. R4 (nit) is the docblock half of the same
issue.

**Security:** no injection vector — `int` PID, validated `> 0` before the
single call site. `function_exists('exec')` is the correct
`disable_functions` guard on PHP ≥ 8.0 and is per-function (an asymmetric
`disable_functions` with only `shell_exec` allowed is handled). The
DEC-006 master-identification hardening is untouched; the new code only
*reads* process state, it never signals on the `ps` result.

**Tests:** the new `testIsProcessAliveReturnsFalseForNonChildZombie` is
deterministic and correct on both Darwin (ps path) and Linux (`/proc`
`State: Z` path — the zombie is equally visible there), so it must NOT be
`@requires OS Darwin`; it is a genuine cross-platform regression test for
CI. SIGKILL for B (avoiding grpc shutdown-handler hangs) mirrors
`ProcessTerminator` — good. Two gaps: R2 (fail-closed branches untested;
a namespaced `CrazyGoat\WorkermanBundle\exec()` override plus reflection
would cover them and would run on Linux CI) and R3 (marker-file
create-vs-write race, nit). The updated comment on
`testIsProcessAliveReturnsTrueForNonChildProcess` is accurate and the test
still passes.

**Docs:** the `docs/troubleshooting.md` bullet is accurate (ps-based zombie
detection; hung intermediate still leaks on non-Linux — matches the code).
FAQ-007 is now partially stale — see knowledge-base candidates below.

**Coder disagreements:** none. The coder's out-of-scope finding (the
`killOrphanedIntermediateFork` fingerprint branch is unreachable in daemon
mode because the fingerprint names the master PID, never the
intermediate's) is **verified real** — `matchesFingerprint()` refuses on
PID mismatch by construction. Pre-existing, correctly excluded from this
PR; it deserves its own issue (ancestry-based verification before any
non-Linux intermediate cleanup).

## Assessment

Safe to merge **after R1 is fixed** (one `posix_kill` recheck plus a test);
R2–R4 can land in the same commit. No second full review round needed —
verifying the R1 diff suffices.
