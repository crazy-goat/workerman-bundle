# Review round 1 — #779

## Prior findings

No prior unresolved findings; initial review round.

## Review

- **Guards the right thing.** `ConfigLoader::warmUp()` (`src/ConfigLoader.php:55-60`) pins `umask(0077)` only around `$this->cache->write(...)`. The new test sets the process umask to `0000`, so if the pin were removed/relaxed the created `cache/workerman` dir would be 0777 and the file 0666 — the assertion `fileperms($path) & 0o002 === 0` fails. The pre-existing `testLoadFromCacheRefusesWorldWritable…` cases `chmod()` first and call the loader, so they cannot catch this.
- **Portable assertion.** World-writable bit, not an exact mode; the parent fixture dir's mode is irrelevant.
- **Hygiene.** `umask()` is restored in `finally`; test is unprivileged, so it runs in the normal matrix.
- **No production change.**

## Checks

- `vendor/bin/phpunit --no-coverage tests/ConfigLoaderTest.php` — passed (41 tests, 3 expected root-only skips locally).
- Focused run of the new test — passed (1 test, 6 assertions).
- `composer lint` — passed (PHPStan/Rector/kb-lint/changelog/exception-usage clean).
- `git diff --check` — clean.

## Findings

None. No knowledge-base candidate: DEC-006/DEC-016 already state the permission policy; the test is the gate.
