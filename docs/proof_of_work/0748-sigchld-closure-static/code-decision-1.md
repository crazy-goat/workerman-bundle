## Round 1 — #748

Removed `static` from the seven closures declared in free functions in `tests/Fixtures/sigchld_test_runner.php` (`waitForChildReap`, the scheduler-handler waits, and the grpc SIGKILL waits). In free-function scope there is no `$this`, so `static` binds nothing and only misleads a reader into suspecting a binding concern; the review in #592 round 2 already verified the closures are correct.

Rejected codifying this with a Rector rule for now: the repo's Rector set does not include one, adding it would change the lint pipeline for every file, and the issue marks the rule as optional. The seven sites are fixed manually and pinned by nothing but readability — behaviour is unchanged and `SchedulerWorkerSigchldTest` still passes.
