## #760 implementation findings

- The three root-only cases live in `tests/ConfigLoaderTest.php` and skip (`markTestSkipped`) when `chgrp`/`chown` fail on a non-root runner. The unit-level `checkCacheFilePermissions()` cases already cover the decision logic with explicit ownership inputs, so only the three filesystem-backed cases need a privileged leg.
- Biggest implementation obstacle: the privileged path cannot be exercised locally (no passwordless root on this workstation), so the acceptance signal is the new CI job itself. To reduce the chance of a false green, the job greps the PHPUnit summary for skips and verifies the three tests actually ran, in addition to PHPUnit's own non-zero exit.
- A first attempt put a "fail on skip" switch in the test class; it was rejected because it changes test-suite behaviour for all callers and adds an env-propagation dependency through `sudo`. The guard now lives entirely in the workflow step.
- PHPUnit output-shape trap: a clean filtered run prints `OK (3 tests, N assertions)`, not `Tests: 3, ... Skipped: 0`, so the guard must accept both. Documented in the workflow comment.
- No additional out-of-scope bugs identified.
