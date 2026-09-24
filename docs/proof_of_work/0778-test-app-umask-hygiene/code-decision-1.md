## Round 1 — #778

Root cause found while validating the naive fix: pinning `umask(0077)` in `tests/App/index.php` does **not** work. `Symfony\Runtime\GenericRuntime::__construct()` calls `umask(0o000)` in debug, and `Workerman\Worker::daemonize()` calls `umask(0)` before forking workers — measured with `sh -c 'umask 0000; php tests/App/index.php start -d'`, which still produced `var/cache` and `var/cache/dev` at `0777`.

Chosen fix, in `tests/App/bootstrap.php`:
1. `umask(0077)` at the top, for the PHPUnit process itself (in-process kernel boots create `var/cache` descendants).
2. `harden_test_var_tree()` after `workerman_start()`: walks `var/cache` and `var/log` and chmods dirs to drop group/other bits and files to drop group/other write. The daemon is up by then, so its already-created tree is corrected regardless of the umask resets.

Verified: `sh -c 'umask 0000; php -r "require tests/App/bootstrap.php; ..."'` now reports `var/cache` 700, `var/cache/dev` 700, `var/log` 700.

Rejected overriding `Kernel::initializeContainer()` in the test kernel: it only scopes cache creation at boot, while cache pools and other files are created lazily by the daemon (umask 0) afterwards, so descendants would still be world-writable. Rejected asking users to run with a fixed umask: the point is that `composer test` behaves identically under any invoking umask.

Added `tests/TestAppHygieneTest.php` pinning the umask order and the post-start hardening call, plus FAQ-040 recording that the two vendor resets make entrypoint umask pinning ineffective.
