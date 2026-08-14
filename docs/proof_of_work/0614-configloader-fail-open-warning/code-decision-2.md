# Issue #614 — round 2 code decision

## Why round 2

Review round 1 approved with follow-ups but surfaced two medium findings
(R1-1, R1-2) and two low/nit findings (R1-3, R1-4, R1-6) worth addressing
before the PR. This round applies the fixes that stay inside the issue's
docs-only scope and records the correct rationale for the one the coder got
wrong.

## What changed in round 2

1. **`src/ConfigLoader.php` — phpdoc of `validateCacheFilePermissions()`**
   reworked (R1-1, R1-4). The previous round-1 wording said the warn branch
   fires "when the cache file is known to exist (`is_file()` succeeded) but
   `fileperms()`/`fileowner()`/`filegroup()` return `false`". The review
   proved by reproduction that on POSIX that conjunction is essentially
   impossible: statting `dir/file` requires search (`x`) permission on `dir`
   and every ancestor — strictly more than statting `dir` — so whenever
   `is_file()` returns `true`, all four metadata reads succeed too. The new
   wording states that implication explicitly and describes the warn branch
   as a **defensive guard** reachable through the public API only in a TOCTOU
   window (the file is unlinked between the gate and the reads) or on
   filesystems where the POSIX implication does not hold (ACLs, extended
   attributes). The directory-EACCES case is now described precisely as "the
   containing directory is not searchable (no `x` permission), so `stat()` on
   paths inside it fails with `EACCES`". The stale "filesystems that do not
   report permissions" example was removed — `fileperms()` returns `false`
   only when `stat()` fails, never as a "this filesystem does not report
   permissions" signal.

2. **`docs/security.md` — "Unreadable metadata" bullet** reworked to the same
   effect (R1-1, R1-4), keeping its original structure (warning behaviour
   first, then the reachability qualification).

3. **`tests/ConfigLoaderTest.php` — `assertFileExists` precondition** added
   (R1-2). The test now asserts the cache file exists right after `warmUp()`
   and before the `chmod 0000`. Without it, if `warmUp()` ever stops writing
   the file, the test would still go green while proving nothing — it would
   be byte-for-byte equivalent to `testLoadFreshThrowsWhenNoConfigAndNoCache`.

4. **`CHANGELOG.md`** entry updated to match the refined framing
   (TOCTOU/defensive-guard, not just "only reachable when the file is known
   to exist").

## What was rejected / deferred

- **R1-3 (LogicException message reword)** — deferred to a follow-up issue.
  The message tail "no cached config file exists" is factually wrong for the
  directory-EACCES case (the file exists, it is unreachable), and the reword
  to "no cached config file could be loaded" is a one-line change with no
  guard-logic risk. But it is outside this issue's docs-only scope and
  changes a user-facing string, so it will be offered as a separate GitHub
  issue in step 14 rather than folded in here. The phpdoc no longer quotes
  the misleading tail verbatim.

- **R1-5 (hard-coded `0700` restore)** — not fixed, consistent with
  siblings. Every existing permission test in `ConfigLoaderTest.php` uses the
  same hard-coded-restore pattern. Capturing the original mode would be a
  repo-wide refactor and is out of scope for a docs issue.

## Corrected rationale (R1-6)

`code-decision-1.md` claimed "PHPUnit converts `E_USER_WARNING` to a test
error by default, so the 'NOT a warning' half of the pin needs no explicit
error handler". That is not why the test is safe. `phpunit.xml` sets no
`failOnWarning`, and PHPUnit 10 reports a triggered `E_USER_WARNING` as a
non-fatal issue rather than failing the test. The real reason no explicit
error handler is needed: in the EACCES case `validateCacheFilePermissions()`
is never entered — the `is_file()` gate at `src/ConfigLoader.php:87` returns
`false` first — so no `E_USER_WARNING` can fire at all. The PHPUnit
`failOnWarning` setting is irrelevant to this test.

## Uncertainty

- The TOCTOU window the warn branch is now said to guard against is real but
  narrow: the file must be unlinked between the `is_file()` gate and the
  `fileperms()`/`fileowner()`/`filegroup()` calls inside
  `validateCacheFilePermissions()`. On a local filesystem that window is
  microsecond-scale; on NFS it is wider. The branch is worth keeping as a
  defensive guard and is unit-tested in isolation via reflection — the docs
  now match that framing instead of implying an operator will routinely see
  the warning.
- The "filesystems where the POSIX implication does not hold (ACLs, extended
  attributes)" phrasing is intentionally broad: the team has not enumerated
  which filesystems actually break the implication. The claim is only that
  the implication is POSIX-specific, which is verifiable.
