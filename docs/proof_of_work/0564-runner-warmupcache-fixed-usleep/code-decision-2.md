# Code Decision — Round 2 (issue #564)

## What the review found
- F1: not a real finding (PHPStan clean)
- F2: correct ordering already
- F3: low — fast-child ~10 ms observation has no direct test; acceptance bullet says "asserted by a test measuring elapsed time"
- F4: nit fixed via lint-fix
- F5-F7: correct

## What was changed in this round
- Added `FastChildRunner` (readonly final, overrides `fork()` to `pcntl_fork` → child `posix_kill(SIGKILL)`) and `RunnerTest::testWarmupFastChildObservedQuickly()`:
  - Creates temp project dir with non-fresh ConfigLoader
  - Runs `warmUpCache` via reflection on `FastChildRunner` with 5 s timeout
  - Measures `microtime(true)` elapsed and asserts `<0.1 s` (100 ms), with message showing ms
  - Cleans up temp dir in finally
  - Elapsed measured 13–20 ms in local run vs old fixed 100 ms worst-case; generous 100 ms ceiling avoids flakes on loaded CI while still proving the ~10 ms claim
- No change to `src/Runner.php` itself — the implementation from round 1 already satisfies the other bullets (no usleep, Wait::until, three outcomes preserved, sub-second timeout via microtime). The added test only closes the coverage gap flagged by F3.

## Alternatives considered for F3
- **Document benchmark only in PR description** (rejected): would satisfy the "before/after numbers in PR description" phrasing used in other perf issues, but this issue's acceptance explicitly says "asserted by a test measuring elapsed time", so a code test is the tighter proof.
- **Isolated test via `runner_test_runner.php` + `runIsolatedTest()`** (considered): would match the existing `warmup_timeout_kicks_in` isolated pattern, but direct fork in the main PHPUnit process is safe here (child exits immediately, no sleep) and avoids adding a new isolated entry point for a trivial timing check. Kept direct.

## Verification
- `vendor/bin/phpunit --filter testWarmupFastChildObservedQuickly` → PASS (49 ms total)
- `composer lint` → 0 php-cs-fixer, 0 phpstan, kb-lint OK, changelog OK
- Manual benchmark: fast child 13.69 ms, timeout 1.09 s (sub-second accuracy) — consistent

## Files changed in round 2
- `tests/RunnerTest.php`: added test + FastChildRunner class
