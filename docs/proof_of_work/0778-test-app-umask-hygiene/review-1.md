# Review round 1 — #778

## Prior findings

No prior unresolved findings; initial review round.

## Review

- **Root cause is real and verified.** `vendor/symfony/runtime/GenericRuntime.php:72` (`umask(0o000)` in debug) and `vendor/workerman/workerman/src/Worker.php:1435` (`umask(0)` in `daemonize()`). Entrypoint umask pinning is therefore overwritten before Symfony writes the cache; this is why the issue's literal suggestion would have been a false fix.
- **Chosen fix works.** `tests/App/bootstrap.php` pins `umask(0077)` for the PHPUnit process and runs `harden_test_var_tree()` after `workerman_start()`. Live check under `umask 0000`: `var/cache` 700, `var/cache/dev` 700, `var/log` 700 (previously 777).
- **Idempotent and safe.** The chmod only removes group/other bits (`~0o077` dirs, `~0o022` files); the same user/daemon keeps access. Re-running is a no-op. New cache files created lazily by the daemon are group/other-writable, but the issue's concern — the reusable runner-owned ancestor tree — is addressed, and the daemon uses the restrictive parents.
- **No security regression.** Nothing is made more permissive; readability by the owner is preserved.
- **Guard + knowledge.** `tests/TestAppHygieneTest.php` pins the umask order and the top-level hardening call; FAQ-040 records the two vendor resets (the one part a textual guard cannot express).

## Checks

- Live `umask 0000` reproduction — fixed (700 on all three trees).
- `vendor/bin/phpunit --no-coverage tests/TestAppHygieneTest.php` — passed (2 tests, 11 assertions).
- `php -l tests/App/bootstrap.php`, `composer lint` (kb-lint 61 entries / 0 warnings), `git diff --check` — passed.

## Findings

None. FAQ-040 is written; no further candidate.
