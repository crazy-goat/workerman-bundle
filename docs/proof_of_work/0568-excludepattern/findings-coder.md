# Findings — coder — issue #568

## Changed files

- `src/Phar/PcreLimitGuard.php` — **new**. Per-pass PCRE limit scope guard
  (`enter()` / `exit()`, idempotent, restore-on-throw safe).
- `src/Phar/ExcludePattern.php` — `matches()` simplified to bare
  `@preg_match` + `false`-result safety net (ini save/restore removed).
  `BACKTRACK_LIMIT` / `RECURSION_LIMIT` widened `private` → `public` so the
  guard references a single source of truth. Class docblock updated to
  describe the new guard arrangement. Constructor probe compile unchanged.
- `src/Phar/PharBuilder.php` — `build()` wraps `buildFromIterator()` +
  `setStub()` + `stopBuffering()` in `PcreLimitGuard::enter()` / `exit()`
  via try/finally.
- `tests/Phar/PcreLimitGuardTest.php` — **new**, 5 tests covering enter/exit,
  restore-on-throw, idempotency.
- `tests/Phar/PharBuilderTest.php` — added
  `testBuildRestoresPcreLimitsWhenPassThrows` (forces a throw inside the
  guarded pass via an unreadable file; asserts limits restored).
- `tests/Phar/ExcludePatternTest.php` — renamed
  `testMatchesAppliesBacktrackLimitGuardPerCall` →
  `testMatchesCompletesQuicklyUnderBacktrackLimit` with updated comments
  (assertions unchanged, still hold).
- `CHANGELOG.md` — entry under `[Unreleased]` → `### Changed`.

## Biggest problem

The existing test `ExcludePatternTest::testMatchesAppliesBacktrackLimitGuardPerCall`
was written specifically to verify the *per-call* ini_set behaviour being
removed by this issue ("Verify that matches() temporarily raises
pcre.backtrack_limit around preg_match()"). The issue's acceptance criteria
say the #334 ReDoS regression test must pass unmodified — and it does pass
unmodified, but only because PHP's default `pcre.backtrack_limit` (1 000 000)
happens to equal `ExcludePattern::BACKTRACK_LIMIT`, so removing the per-call
set leaves the limit at the same bounded value and the "restored afterwards"
assertion still holds (the value is never changed).

This is a latent fragility: the test's *premise* (per-call raise/restore) is
now false even though its *assertions* still pass. I renamed it to
`testMatchesCompletesQuicklyUnderBacktrackLimit` and rewrote the comments to
describe the real arrangement (limits established per-pass by the guard;
outside a pass the defaults match the ceilings), while keeping the
assertions — they still guard the ReDoS safety net (completes < 1 s, ini
value left untouched). The dedicated `PcreLimitGuardTest` now carries the
restore-on-throw contract that the old test could not.

A secondary concern: designing the restore-on-throw *integration* test at
the `PharBuilder` level. `PharBuilder` is `final readonly` and constructs its
own iterator internally, so the guarded block (`buildFromIterator` +
`setStub` + `stopBuffering`) cannot be injected with a throwing iterator.
The only deterministic way to make `buildFromIterator()` throw without
modifying production code is an unreadable source file (chmod 000), which
required a root-skip guard (root bypasses file permissions). The guard unit
test is the load-bearing restore-on-throw proof; the integration test is
belt-and-suspenders and depends on Phar's error message containing
"unreadable" (see Uncertainties in code-decision-1.md).

## Discovered bugs / places to improve (incl. out of scope)

1. **`tests/Phar/SfxDownloaderTest.php:1254` (`FailingUnlinkStreamWrapper`) —
   dynamic property deprecation.** Running `tests/Phar/` triggers 4 PHP
   deprecations: `Creation of dynamic property
   …FailingUnlinkStreamWrapper::$context is deprecated` (PHP 8.2+). The
   stream-wrapper stub class declares no `$context` property, so when PHP's
   stream layer assigns the context onto the wrapper instance (reported at
   `src/Phar/SfxDownloader.php:49`, the call site) it warns. Fix: add
   `#[\AllowDynamicProperties]` above `final class FailingUnlinkStreamWrapper`,
   or declare `public $context;` on the stub. Out of scope for #568
   (pre-existing, surfaced only because I ran the full `tests/Phar/` suite),
   but it adds deprecation noise to CI output.

2. **`tests/Phar/PharBuilderTest.php` tearDown uses `GLOB_BRACE` which is
   unavailable on Alpine/musl PHP builds.** Line 23:
   `glob($this->tempDir . '/**/*', GLOB_BRACE)`. `GLOB_BRACE` is a glibc
   extension and is **not defined** on PHP builds against musl libc (Alpine,
   distroless). On those builds the constant is undefined → PHP warning + the
   glob returns false → tearDown silently skips file cleanup → temp dirs
   leak. Also `GLOB_BRACE` adds no value here (the pattern has no braces).
   Suggested fix: drop the `GLOB_BRACE` flag, or replace the whole glob with
   the `RecursiveIteratorIterator` already used in `removeDirectory()` below
   it (which is portable and already handles the cleanup correctly — the
   glob appears redundant). Out of scope for #568 but a real portability bug.

3. **`ExcludePattern` constructor still does its own per-pattern
   `ini_set`/restore around the probe compile** (`src/Phar/ExcludePattern.php:73-91`).
   This is intentional (construction-time validation must be self-contained
   and not depend on a guard being active) and runs once per pattern, so it
   is not hot. Not a bug — noted here only because a future reader might
   wonder why the constructor still touches ini when `matches()` no longer
   does. No change recommended.

4. **`PharBuilder::isExcluded()` (`src/Phar/PharBuilder.php:172-191`) runs up
   to 4 anchored `preg_match` calls per file.** The issue explicitly notes
   this is ~1.2 ms across a 10k-file build and immaterial — no change
   proposed. Recorded for completeness so it is not re-discovered as a "new"
   finding in a future sweep.
