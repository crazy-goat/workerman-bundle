# Findings — review — issue #568

Round 1. One entry per finding. Status as of this round.

| # | File:line | What is wrong | Severity | Status |
|---|-----------|---------------|----------|--------|
| F-1 | tests/Phar/PcreLimitGuardTest.php:67-88 | `testExitRestoresEvenWhenProtectedBlockThrows` does not distort the ini value before `enter()`, so it cannot distinguish "restored to captured prior" from "restored to PHP default" — weaker than `testExitRestoresPriorValues` which distorts but does not throw. The integration test (PharBuilderTest:378) covers the gap. | low | open |
| F-2 | tests/Phar/PcreLimitGuardTest.php:67-88 | The restore-on-throw unit test catches the exception in a `catch` block; production has no `catch` (exception propagates through `finally`). Semantically equivalent, but the test shape diverges from the production shape. | low | open |
| F-3 | src/Phar/ExcludePattern.php:106-120 | `matches()` called outside a `PcreLimitGuard` pass runs with process-default limits. The class docblock does not warn about this. Today safe by coincidence (PHP default = `BACKTRACK_LIMIT`), but a future PHP default change or a caller that lowered the limit would change the safety net's effectiveness. | low | open |
| F-4 | src/Phar/PcreLimitGuard.php:40-85 | No `__destruct` — a caller that forgets `exit()` leaks the lowered limit for the process lifetime. By design (explicit enter/exit), noted as a trade-off. | low | open (no change recommended) |
| F-5 | src/Phar/PcreLimitGuard.php:68 | `exit()` method name visually collides with the `exit()` language construct. `release()` / `restore()` would avoid it. Purely stylistic. | nit | open |
| F-6 | tests/Phar/PharBuilderTest.php:23 | Pre-existing `GLOB_BRACE` in `tearDown` is undefined on musl libc (Alpine) → temp dirs leak. Not touched by this diff but the file is modified (new test added). | low | open (pre-existing, out of scope) |
