## Round 1 — #753

Guarded cleanup of each known marker file with `is_file()` before calling `unlink()`. This keeps removal behavior for regular marker files while avoiding the suppressed unlink warning that PHPUnit 10 may route through its shutdown error handler when a marker is missing. A loop keeps the three identical operations consistent.

Rejected retaining `@unlink()` because the issue documents why PHPUnit 10 suppression does not reliably protect shutdown callbacks. Did not change the daemon stop call or broader shutdown registration, which are outside this issue.
