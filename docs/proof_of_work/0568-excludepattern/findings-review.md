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

Round 2. Status updated for all entries; new findings appended.

| # | File:line | What is wrong | Severity | Status |
|---|-----------|---------------|----------|--------|
| F-1 | tests/Phar/PcreLimitGuardTest.php:67-88 | `testExitRestoresEvenWhenProtectedBlockThrows` does not distort the ini value before `enter()`, so it cannot distinguish "restored to captured prior" from "restored to PHP default". | low | **fixed** (round 2): commits 01757c1 + 912e962 added distortion (777/666) before enter and corrected assertions to check distorted values. |
| F-2 | tests/Phar/PcreLimitGuardTest.php:67-88 | The restore-on-throw unit test catches the exception in a `catch` block; production has no `catch` (exception propagates through `finally`). Semantically equivalent, but test shape diverges from production. | low | **still present** (accepted): integration test (PharBuilderTest:386) models the production shape; PHP `finally` semantics are identical either way. |
| F-3 | src/Phar/ExcludePattern.php:106-120 | `matches()` called outside a `PcreLimitGuard` pass runs with process-default limits. The class docblock does not warn about this. | low | **fixed** (round 2): commit 01757c1 added `@see PcreLimitGuard` docblock (lines 107-110) warning about outside-the-pass usage. |
| F-4 | src/Phar/PcreLimitGuard.php:40-85 | No `__destruct` — a caller that forgets `exit()` leaks the lowered limit for the process lifetime. By design (explicit enter/exit), noted as a trade-off. | low | **still present** (open, no change recommended): by design; test `tearDown` uses `ini_restore` defensively. |
| F-5 | src/Phar/PcreLimitGuard.php:68 | `exit()` method name visually collides with the `exit()` language construct. Purely stylistic. | nit | **still present** (open): no rename; stylistic only. |
| F-6 | tests/Phar/PharBuilderTest.php:23 | Pre-existing `GLOB_BRACE` in `tearDown` is undefined on musl libc (Alpine) → temp dirs leak. Not touched by this diff but the file is modified. | low | **still present** (open, pre-existing, out of scope): not touched by this diff; worth a follow-up issue. |
| F-7 | tests/Phar/PcreLimitGuardTest.php:69,94-95 | `testExitRestoresEvenWhenProtectedBlockThrows` distorts both `pcre.backtrack_limit` (777) and `pcre.recursion_limit` (666) but only captures and restores `$originalBacktrack` at the end — `pcre.recursion_limit` is left at '666' (caught by `tearDown`'s `ini_restore`, but asymmetric with `testExitRestoresPriorValues` which restores both). | low | **open** (new in round 2): add `$originalRecursion` capture + restore. |
| F-8 | src/Phar/ExcludePattern.php:107 | `@see` tag has inline prose on the same line (`@see PcreLimitGuard Must be called inside ...`) — non-standard PHPDoc; `@see` should be on its own line with prose above or below. | nit | **open** (new in round 2): style only; no tool flags it. |

Round 3 (final). Status updated for all entries; no new findings.

| # | File:line | What is wrong | Severity | Status |
|---|-----------|---------------|----------|--------|
| F-1 | tests/Phar/PcreLimitGuardTest.php:67-98 | `testExitRestoresEvenWhenProtectedBlockThrows` does not distort the ini value before `enter()` — weaker than `testExitRestoresPriorValues`. | low | **fixed** (round 2, verified round 3): distortion (777/666) before enter, assertions check distorted values. Lines 69-76, 93-94 confirmed. |
| F-2 | tests/Phar/PcreLimitGuardTest.php:81-88 | The restore-on-throw unit test catches the exception in a `catch` block; production has no `catch` (exception propagates through `finally`). Semantically equivalent, but test shape diverges from production. | low | **still present** (accepted): integration test (PharBuilderTest:386) models production shape; PHP `finally` semantics identical either way. |
| F-3 | src/Phar/ExcludePattern.php:106-111 | `matches()` called outside a `PcreLimitGuard` pass runs with process-default limits. The class docblock does not warn about this. | low | **fixed** (round 2, verified round 3): docblock (lines 106-111) warns about outside-the-pass usage; `@see PcreLimitGuard` on its own line. |
| F-4 | src/Phar/PcreLimitGuard.php:40-85 | No `__destruct` — a caller that forgets `exit()` leaks the lowered limit for the process lifetime. By design (explicit enter/exit), noted as a trade-off. | low | **still present** (open, no change recommended): by design; test `tearDown` uses `ini_restore` defensively. |
| F-5 | src/Phar/PcreLimitGuard.php:68 | `exit()` method name visually collides with the `exit()` language construct. Purely stylistic. | nit | **still present** (open): no rename; stylistic only. |
| F-6 | tests/Phar/PharBuilderTest.php:23 | Pre-existing `GLOB_BRACE` in `tearDown` is undefined on musl libc (Alpine) → temp dirs leak. Not touched by this diff but the file is modified. | low | **still present** (open, pre-existing, out of scope): not touched by this diff; worth a follow-up issue. |
| F-7 | tests/Phar/PcreLimitGuardTest.php:69-97 | `testExitRestoresEvenWhenProtectedBlockThrows` distorts both limits but captures/restores only `pcre.backtrack_limit` — `pcre.recursion_limit` left at '666'. | low | **fixed** (round 2, verified round 3): commit e318282 added `$originalRecursion` capture (line 70) + restore (line 97). Both limits now restored. |
| F-8 | src/Phar/ExcludePattern.php:107 | `@see` tag has inline prose on the same line — non-standard PHPDoc. | nit | **fixed** (round 2, verified round 3): commit e318282 restructured docblock — prose on lines 107-109, `@see PcreLimitGuard` on own line 111. |
