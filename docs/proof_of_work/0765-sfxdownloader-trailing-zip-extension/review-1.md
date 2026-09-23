# Review round 1 — #765

## Prior findings

No prior review findings; this is the initial round.

## Review

- `src/Phar/SfxDownloader.php`: `str_ends_with($basename, '.zip')` plus `substr($basename, 0, -4)` removes only a terminal suffix and preserves interior `.zip` segments. When no suffix exists, the basename remains unchanged, matching the previous transformation for that case.
- `tests/Phar/SfxDownloaderTest.php`: the regression test creates the correctly derived file and verifies that `locateSfxEntry()` returns it for `my.zip.archive.zip`, without relying on the incorrect fallback candidate.
- The fallback iteration, archive validation, and path composition are unchanged.

## Checks

- Focused PHPUnit test — passed (1 test, 1 assertion).
- PHP syntax checks for both changed PHP files — passed.
- `php bin/check-changelog.php` — passed.
- `git diff --check` — passed.

## Findings

None. No knowledge-base candidate is needed.
