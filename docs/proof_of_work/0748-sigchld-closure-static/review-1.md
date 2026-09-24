# Review round 1 — #748

## Prior findings

No prior unresolved findings; initial review round.

## Review

- All seven changed closures (`waitForChildReap` at :91, scheduler-handler waits at :313/:348/:383, grpc SIGKILL waits at :605/:644/:696) are declared inside free functions and capture only local variables — no `$this`, no object binding. Dropping `static` changes nothing at runtime; the closures are only invoked.
- No other `static function` remains in the file, and no other fixture was touched (issue scope).
- The optional Rector rule was deliberately not added; rationale in `code-decision-1.md`.

## Checks

- `php -l tests/Fixtures/sigchld_test_runner.php` — no syntax errors.
- `vendor/bin/phpunit --no-coverage tests/SchedulerWorkerSigchldTest.php` — passed (10 tests, 42 assertions).
- `composer lint` — passed (PHPStan/Rector/kb-lint/changelog/exception-usage clean).
- `git diff --check` — clean.

## Findings

None. No knowledge-base candidate is needed.
