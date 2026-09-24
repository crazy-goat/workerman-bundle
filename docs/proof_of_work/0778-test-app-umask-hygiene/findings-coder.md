## #778 implementation findings

- Biggest obstacle (and the key discovery): the issue's suggested fix — pin the umask in the tests/App runner — is defeated by two vendor calls. `Symfony\Runtime\GenericRuntime` sets `umask(0o000)` in debug (GenericRuntime.php:72) and `Workerman\Worker::daemonize()` sets `umask(0)` (Worker.php:1435). Reproduced: daemon `start -d` under `umask 0000` with an entrypoint pin still left `var/cache`/`var/cache/dev` at 0777.
- Working fix: pin umask for the PHPUnit process and post-process the daemon's tree with `harden_test_var_tree()` in `tests/App/bootstrap.php`; verified 700 on `var/cache`, `var/cache/dev`, `var/log` under `umask 0000`.
- The audit confirms the only reused runner-owned trees are Symfony's `var/cache`/`var/log`; the 0777 recursive `mkdir()` sites in individual tests are `uniqid()`-scoped temp fixtures and are not a concern (as the issue states).
- Proposed knowledge-base entry FAQ-040 (accepted and written by the main session) records the two umask resets, since the regression test only pins the bootstrap wiring.
- No additional out-of-scope bugs identified.
