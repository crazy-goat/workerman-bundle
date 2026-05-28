## Problem

`ProcessInspector::waitForProcessToStop()` uses bare literals (`stopTimeout * 3 + 3` vs `stopTimeout + 3`) with no named constants explaining what "3" means. Anyone reading the code has to reverse-engineer the intent.

## Changes

### `src/ProcessInspector.php`

- Add `GRACEFUL_TIMEOUT_MULTIPLIER = 3` — multiplier for graceful stop timeout
- Add `TIMEOUT_BUFFER = 3` — buffer for signal delivery / process-reap latency
- Replace raw literals with named constants in `waitForProcessToStop()`

### `tests/ProcessInspectorTest.php`

- Add `testTimeoutConstantsExist()` — reflection-based verification that both constants exist with correct values and visibility
- Update `testGracefulTimeoutIsAlwaysLongerThanRegular()` to read constant values from the class instead of duplicating magic numbers

## Verification

- `vendor/bin/phpunit` — 1077 tests, 2361 assertions, all pass
- `vendor/bin/phpstan` — no errors
- `vendor/bin/php-cs-fixer fix --dry-run` — 0 files changed
- `vendor/bin/rector process --dry-run` — no errors

Closes #295
