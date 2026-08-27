# Findings — Review (issue #564)

| # | File:Line | Severity | Description | Status | Resolution |
|---|-----------|----------|-------------|--------|------------|
| F1 | `src/Runner.php:103-120` | low | Closure captures `$status` by ref via `pcntl_waitpid` out-param — verify PHPStan level 8 | not a real finding | `composer lint` (phpstan) passes clean |
| F2 | `src/Runner.php:103-120` | medium | Post-Wait check ordering: `$waitFailed` must be checked before `!$completed` to avoid swallowing -1 into timeout | correct | Already ordered correctly |
| F3 | `src/Runner.php:103-120` + `tests/RunnerTest.php:167-210` | low | Fast-child ~10 ms observation has no direct test; `testWarmupTimeoutKicksIn` covers timeout but not fast path | fixed in round 2 | Added `testWarmupFastChildObservedQuickly` with `FastChildRunner` immediate SIGKILL child, asserts elapsed <100 ms (measured 13.6 ms); manual benchmark and test both demonstrate ~10 ms vs old 100 ms worst-case |

| F4 | `src/Runner.php:1-15` | nit | Import ordering for `Wait` | fixed | `composer lint-fix` corrected |
| F5 | `CHANGELOG.md:60-70` | low | Performance entry format / reference | correct | `bin/check-changelog.php` OK |
| F6 | `src/Runner.php:99-131` | low | No bare `usleep()`/`time()` remains | correct | Grep confirms |
| F7 | `src/Runner.php:122-131` | low | Timeout path `posix_kill` return unchecked, blocking reap race | correct | Matches original behavior, no regression |

