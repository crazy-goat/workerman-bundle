## #753 implementation findings

- The existing `tests/App/bootstrap.php` shutdown callback removed three fixed marker paths unconditionally using `@unlink()`. PHPUnit 10 may route suppressed shutdown warnings through its error handler, where no test case is active. Replacing suppression with an existence check avoids calling `unlink()` for absent marker files.
- Biggest implementation obstacle: the first changelog insertion duplicated the existing `### Fixed` heading in `[Unreleased]`; `composer lint` and the full suite both caught this structural issue. The entry was moved under the existing heading, after which both gates passed.
- The PHPUnit shutdown exception itself depends on an abnormal process termination and was not reproduced directly. The full suite passed with the guard in place.
- No additional out-of-scope bugs identified during this small change.
