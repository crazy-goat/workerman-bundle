# Review — Round 1 (issue #564)

## Scope
- Diff: `src/Runner.php` (import Wait, replaced `while(true)` loop with `Wait::until()`), `CHANGELOG.md` (Performance entry)
- Helpers read: `docs/helpers/faq.md` tag index + FAQ-036 (env bridge, Runner, CacheWarmupTimeoutConfig), `docs/helpers/decisions.md` none directly applicable
- Checks: PSR-4, Symfony conventions, PHPStan level 8 signatures, error handling, PSR-12, tests, security (fork/signal), docs

## Reviewed files
- `src/Runner.php:1-134`
- `CHANGELOG.md:8-65`

## Findings

### F1 — `src/Runner.php:103-120` Condition closure captures `$status` by reference but `$status` is mutated via `pcntl_waitpid` — ensure PHPStan understands out-param
- **Severity:** low
- **Description:** The closure `function () use ($pid, &$status, &$waitFailed): bool` mutates `$status` via `pcntl_waitpid($pid, $status, WNOHANG)`. PHPStan at level 8 may expect `@param-out` handling for by-ref writes, but here `$status` is a local `int` initialized to 0, mutated inside closure and read after `Wait::until()`. This is not a method out-param but a closure capture. Verify `composer lint` (phpstan) passes — it does. No code change needed, but worth noting as a by-ref pattern similar to FAQ-029.
- **Status:** not a real finding (phpstan clean)

### F2 — `src/Runner.php:103-120` Ordering of post-Wait checks: `$waitFailed` before `!$completed`
- **Severity:** medium (if reversed would swallow wait failure into timeout)
- **Description:** Correctly checks `$waitFailed` first, then `!$completed`. If reversed, a `pcntl_waitpid` returning -1 would be misclassified as timeout, SIGKILLing a non-child PID (999999) and throwing timeout message instead of wait-failure message. Current code orders correctly.
- **Status:** fixed / correct as written

### F3 — `src/Runner.php:103-120` `Wait::until()` default backoff (10 ms → 250 ms) vs old 100 ms fixed — verify fast-child assertion
- **Severity:** low
- **Description:** Wait starts at 10 ms, so fast child that exits before first check is 0 ms, otherwise ~10 ms. Verified manually: 13.6 ms elapsed for immediate SIGKILL child, vs old ~50 ms avg / 100 ms worst. Timeout accuracy verified: 1.09 s for 1 s deadline (sub-second accuracy). Both meet acceptance criteria. Acceptance requires "A fast-exiting warmup child is observed in ~10 ms rather than up to ~100 ms — asserted by a test measuring elapsed time". No existing test asserts this directly; `testWarmupTimeoutKicksIn` checks timeout, not fast path. Consider adding a fast-child timing test or updating PR description with benchmark numbers. Not a code defect, but a coverage gap for the acceptance bullet.
- **Status:** open — suggest adding fast-child timing test or documenting benchmark in PR description

### F4 — `src/Runner.php:1-15` Import ordering
- **Severity:** nit
- **Description:** `use CrazyGoat\WorkermanBundle\Util\Wait;` must be ordered alphabetically with other `CrazyGoat\WorkermanBundle\*` imports. Fixed by `lint-fix` (now between `Worker\SupervisorWorker` and `Psr\Log\LoggerInterface`? Actually after SupervisorWorker before Psr, correct per php-cs-fixer). Current file passes `php-cs-fixer --dry-run`.
- **Status:** fixed

### F5 — `CHANGELOG.md:60-70` Performance entry formatting and reference
- **Severity:** low
- **Description:** Entry uses `Util\Wait::until()` with correct backtick span, mentions exponential backoff, sub-second accuracy, three outcomes, and links `(#564)` — passes `bin/check-changelog.php`. The `### Performance` heading is not in `KEEP_A_CHANGELOG_SUBHEADINGS` but is allowed (ignored for duplicate check, as in 0.27.0). No violation.
- **Status:** correct

### F6 — `src/Runner.php:99-131` No bare `usleep()` or `time()` remains — compliance with acceptance
- **Severity:** low
- **Description:** Grep confirms no `usleep` or `time()` in `warmUpCache` after change. `Wait::until()` internally uses `usleep` and `microtime(true)` — correct ownership per issue's "no bare usleep() remains in the file".
- **Status:** correct

### F7 — `src/Runner.php:122-131` Timeout path does `posix_kill($pid, SIGKILL)` + blocking `pcntl_waitpid($pid, $status, 0)` even if kill fails (e.g., child already reaped race)
- **Severity:** low
- **Description:** If timeout fires but child exits between `Wait` returning false and `posix_kill`, `posix_kill` returns false and is ignored, but blocking `pcntl_waitpid` will still reap (or return -1 if already reaped). Original code behaved identically (did not check `posix_kill` return). No regression. The blocking reap ensures no zombie leaks regardless.
- **Status:** correct, matches original behavior

## Knowledge base violations
- None. Tag `runner` maps to FAQ-036, which was read; no violation. Decisions: no DEC directly applies.

## Candidate knowledge-base entries (proposals, not writes)
- None — change is a straightforward adoption of existing `Wait` utility, already documented by its own usage in `StatusFileReader` and `ProcessInspector`.

## Overall
One nit fixed (import), one coverage suggestion (F3) remains. No high/medium defects that block merge if fast-child benchmark is documented in PR description. If strict reading of acceptance criteria requires a test for fast child, add a small timing test before declaring clean.
