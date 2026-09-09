# Code Decision 3 — Issue #567: review round 2 low findings (F-5, F-6)

## Approach

Round 3 addresses only the two low findings from review round 2
(`docs/proof_of_work/0567-process-inspector-liveness/findings-review.md`).

### F-5 — stale docblock on `testMatchesFingerprintFailsClosedForMismatchedUid`

The first paragraph of the docblock described the round-1 test gap how the
unreadable-UID fail-closed path was "covered" via a non-existent PID returning
null — a workaround that was made obsolete when the round-1 fix introduced the
crafted-snapshot test. Keeping it was confusing: it suggested the mismatched-UID
test exercised the unreadable-UID branch.

I rewrote the docblock to state exactly what the test verifies (a **live**
process whose UID differs from the fingerprint must be refused), and added a
pointer sentence to the dedicated unreadable-UID test so readers land on the
right test for the `$snapshot->uid === null` branch. The existing
`@requires OS Linux` / `@requires extension pcntl` / `@requires extension posix`
annotations were preserved — that test genuinely forks via `pcntl_fork()`.

### F-6 — drop unconditional `@requires OS Linux` from the crafted-snapshot tests

I audited the two test bodies for any Linux-only dependency before removing the
annotation:

- both instantiate synthetic `ProcessSnapshot` / `MasterFingerprint` objects —
  no `/proc` file access;
- both call `\posix_getuid()` — available on macOS; and
- both invoke the pure-comparison private method via the reflection helper
  `invokeSnapshotMatchesFingerprint()` — platform-independent.

Neither test forks, reads `/proc`, or touches Linux-only kernel facilities, so
the `@requires OS Linux` annotation was purely losing macOS coverage of the
fail-closed branches. Removed it from both tests. (The `@requires extension
posix` that the docblock does *not* carry is enforced by upstream
`ProcessInspectorTest` posix-extension expectations; the tests cannot run
without posix, and would be skipped by PHPUnit if missing.)

## Rejected alternatives

- **Delete the stale paragraph only.** I considered just dropping the misleading
  sentence and leaving the rest, but the remaining text still repeated the
  "startTime 0 skips the start-time check" simulation detail, which describes
  implementation plumbing rather than the assertion. A clean from-scratch
  docblock that names the tested behavior and points at the sibling test is
  clearer.
- **Guard a `/proc`-only helper instead of removing `@requires OS Linux`.** Not
  needed — neither body uses `/proc`. No partial guard required.

## Uncertainties

- Whether the reviewer would prefer the pointer sentence or a bare rewritten
  paragraph. Low risk either way; the pointer keeps docs unambiguous about where
  the unreadable-UID branch is really covered.
- `code-decision-3.md` numbering: round 1 = original implementation (code-decision-1),
  round 2 = review-only (no code change, review-2.md only), round 3 = this fix.
