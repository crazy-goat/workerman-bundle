# Findings — Coder (#710)

## Surprises / obstacles

- The issue body cites CONTRIBUTING.md lines ~78 and ~148, but in the current
  tree the two occurrences are at lines 86 and 264. Line drift only; both
  occurrences carry the exact phrase `line-coverage threshold`, which is why
  the test keys on the phrase rather than on line numbers. No action needed,
  but worth noting that issue line numbers age.

- No blocker: the single-file PHPUnit run does not boot the daemon (plain
  `TestCase`), so FAQ-039's daemon-stop side effect does not apply to this
  test file.

## Weak spots noticed (mostly out of scope)

1. **Regex is duplicated, not shared** — `tests/BinDirectoryTest.php` (new
   test) and `tests/CoverageCiGateTest.php:69` (`testComposerCoverageCheckDefinesNonZeroThreshold`)
   both parse the same `coverage:check` shell string with near-identical
   regexes. If the script form changes (e.g. `check-coverage.php` renamed or
   arguments reordered), both tests must be updated together and one can be
   forgotten.
   *Suggested fix:* extract a small private/static helper (or a shared test
   trait) that returns the parsed threshold, and have both tests call it.
   Low priority; the duplication is currently two lines.

2. **`CoverageCiGateTest` asserts the script list count is exactly 1**
   (`tests/CoverageCiGateTest.php:60-62`), but the new test iterates to find
   the threshold. That is intentional (so the new test does not duplicate the
   count invariant), but it means the new test would silently accept a
   *second* `coverage:check` line whose threshold disagrees, while
   `CoverageCiGateTest` still rejects the count. Acceptable — the two tests
   together are sound.

3. **The prose display convention is implicit** — `CONTRIBUTING.md` writes
   `80%`, `CoverageCiGateTest` writes `80.0`, and `bin/check-coverage.php`
   accepts either. Nothing documents that the doc form drops `.0` except this
   new test's comment. *Suggested fix:* if the threshold ever grows a decimal,
   add a one-line note in CONTRIBUTING.md near the figure; otherwise no action.

4. **Out of scope, pre-existing:** `bin/check-coverage.php` reads
   `$argv[2]` and defaults to `0.0` when absent. A typo that drops the
   argument would make the coverage gate pass unconditionally instead of
   failing. The new test guards the `composer.json` script, but a direct
   `php bin/check-coverage.php var/coverage.xml` invocation (e.g. by an
   operator, or a future workflow edit — `testThresholdIsDefinedOnlyInComposerScript`
   forbids CI doing so) would silently use `0.0`. *Suggested fix:* make
   `bin/check-coverage.php` exit non-zero when `$argv[2]` is missing, or
   require the threshold explicitly.
