# Code decision 2 — issue #721: address review round 1 nits

## What changed

- **R1 (nit):** reworded the ancestry title-mismatch warning in `src/ProcessInspector.php:271` from `"parent is not a Workerman master process"` to `"parent does not carry the Workerman master process title"`. The new wording names the exact predicate that failed (`isWorkermanMasterTitle()`), making it distinct from the generic fingerprint-mismatch warning at `src/ProcessInspector.php:280`. No behavioural change.

## What was left as-is

- **R2 (low):** the two new ancestry tests remain `@requires OS Linux`. A Darwin-runnable mock would require a test-only subclass overriding the private `isLinux()`/`getParentPid()`/`isWorkermanMasterTitle()` predicates — more churn than the low-severity developer-experience gap justifies for this cycle. The gap is already tracked in `findings-coder.md` row 3 and `findings-review.md` R2; closing it is a follow-up, not a gate for this fix.

## Verification

- `composer lint` still green (php-cs-fixer, phpstan level 8, rector, kb-lint, check-changelog).
- `vendor/bin/phpunit tests/ProcessInspectorTest.php` on Darwin: 28 tests, 21 executed, 7 skipped, 0 failures — same as round 1.
