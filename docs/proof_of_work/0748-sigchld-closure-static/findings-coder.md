## #748 implementation findings

- Seven `static function () use (...)` closures sit in free functions in `tests/Fixtures/sigchld_test_runner.php`; none captures `$this`, so the keyword is dead noise.
- Biggest obstacle: none — a mechanical removal. The only care was confirming the closures are in free-function scope (they are passed to `Wait::until()`/inline waits, never bound to an object).
- No additional out-of-scope bugs identified. The issue's optional Rector rule was not pursued, to keep the change scoped and the lint pipeline untouched.
