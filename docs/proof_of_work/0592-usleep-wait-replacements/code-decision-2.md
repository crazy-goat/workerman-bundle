# Code decision 2 — #592 fix round: review round 1 findings

## Approach

Fix round for the five Round-1 findings. All are small, localized
test/tooling fixes; no behavior change to `src/` and no CHANGELOG change
(the entry's claims do not materially shift).

### R1-F1 (medium) — orphaned child in ServerManagerTest fork helpers

The three fork helpers call `waitForChildReady($readyMarker)` inside the
helper, before `return $pid`. `waitForChildReady` throws
`AssertionFailedError` on a 5s timeout; the exception would propagate
before the test method's `try/finally { killChildBlocking($pid); }` is
entered, orphaning the `for (;;) { usleep(100_000); }` child.

Decision: factor the fix **once** into a small private helper,
`waitForChildReadyOrKill(string $marker, int $pid)`, placed directly
after `waitForChildReady`:

```php
private function waitForChildReadyOrKill(string $marker, int $pid): void
{
    try {
        $this->waitForChildReady($marker);
    } catch (\PHPUnit\Framework\AssertionFailedError $e) {
        $this->killChildBlocking($pid);
        throw $e;
    }
}
```

All three helpers call it. Factoring once was chosen over 3× duplication
because the try/catch–kill–rethrow shape is identical in all three sites
and a helper keeps the failure path reviewable in one place. The test
method's own try/finally remains untouched as a second line of defense
(no behavioral change for the success path). Re-throwing the original
exception preserves the "Child did not install signal handlers within 5s"
message.

Rejected alternatives: duplicating the try/catch at each call site (3×
copy of the same 6 lines, worse for future changes); killing in
`waitForChildReady` itself (that helper is also used by nothing else
today, but `killChildBlocking` needs the pid and mixing wait+kill in one
method makes the caller-visible contract muddier).

Note: `killChildBlocking` uses SIGKILL + `pcntl_waitpid` — per FAQ-007,
forked test children on grpc/macOS hosts must be killed this way, never
via `exit()`.

### R1-F2 (low) — fractional --timeout truncated in bin/wait-for-ports.php

`--timeout` is parsed as `(float)`; `Wait::until()` takes an int.
`(int) $timeoutSeconds` truncated `1.5` → `1` while the error message
printed `1.5`.

Decision: `(int) ceil($timeoutSeconds)` at the `Wait::until()` call site,
per the review's smallest-fix option A. The wait is now at least what the
error message claims ("within 1.5 seconds" → waits 2 × 1s backoff
bounded). Rejected: printing the truncated int in the error message —
that would *shrink* the claimed wait to match the truncation and keep the
silent data loss; printing a float while waiting an int is the honest
direction only if the wait is >= the claim, which ceil guarantees.

### R1-F3 (low) — PID > 0 validation dropped in ControlByteWorkerDosE2ETest

`startWorker()`'s Wait::until condition checked only `portIsOpen()`
after the two `is_file` checks; an empty PID file would return PID 0.

Decision: add `(int) \trim((string) \file_get_contents(...)) <= 0 →
return false` to the condition (after the is_file checks, before the
process-status checks). The later unconditional read is preserved (the
review allowed either). Kept the failure mode as the existing
"Worker did not become ready within 15s" `fail()` rather than adding a
new assertion, since a zero PID now can only mean the wait timed out.

### R1-F4 (nit) — unused $argc

Removed the cargo-culted `$argc = $_SERVER['argc'] ?? 0;` line.

### R1-F5 (nit) — misleading test name

Renamed `testWaitForPortsScriptExistsAndIsExecutable` →
`testWaitForPortsScriptExists`. Per the review's hard rule, no
`is_executable()` assertion was added: the script runs via
`php bin/wait-for-ports.php`. `CoverageCiGateTest` has an unrelated
`AndIsExecutable` test that stays.

## Validation

- `vendor/bin/phpunit tests/ServerManagerTest.php tests/ControlByteWorkerDosE2ETest.php tests/BinDirectoryTest.php` — 61 tests, 234 assertions, OK (1 platform-conditioned skip, 1 no-code-coverage-driver warning).
- `php -l bin/wait-for-ports.php` — clean.
- Manual timeout probes (see R1-F2 evidence in findings-review.md).
- Full `composer test` not re-run: only the three affected test files and
  one bin script changed, and all of them were executed above; daemon
  ports 8888/9999 were verified free before and after.
- `composer lint` — run before push (also enforced by the pre-push hook).

## What I was unsure about

- Whether to factor the R1-F1 fix once or duplicate it 3×: chose the
  helper; the task explicitly blessed either.
- `ceil()` overshoot: `--timeout=1.5` now waits 2s + backoff tail rather
  than exactly 1.5s. This is within `Wait::until`'s documented
  overshoot semantics and matches the review's requested direction
  ("at least what the message claims").
