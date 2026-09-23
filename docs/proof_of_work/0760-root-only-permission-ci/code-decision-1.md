## Round 1 — #760

Added a dedicated `tests-root-permissions` CI job that runs exactly the three `ConfigLoaderTest` cases which need `chgrp`/`chown` to a foreign uid/gid, under `sudo`. The job fails if PHPUnit reports any skip, so a lost-privilege regression breaks the build instead of passing silently. The job catches only the three integration cases; the pure decision-function coverage (`checkCacheFilePermissions()` with explicit ownership inputs) already runs unprivileged in the normal matrix and was left untouched.

Rejected running the whole suite as root: the other 38 `ConfigLoaderTest` cases and the broader suite are not designed to run elevated, and privilege is only needed for the three filesystem-ownership setup steps. Also rejected a Docker-as-root leg because Ubuntu runners already provide passwordless `sudo`, so a Docker build would add build time without adding coverage.

The `ci` aggregator now requires the new job on every non-scheduled, non-docs-only run, keeping the existing docs-only and schedule skip semantics intact.

Guard robustness: PHPUnit 10 prints `OK (3 tests, N assertions)` when clean and a `Skipped: N` summary when not; the step checks both shapes rather than matching only one, so a clean run is not misread as a skip.
