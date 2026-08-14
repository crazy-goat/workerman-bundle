# Issue #614 — ConfigLoader fail-open warning is unreachable — code decision

## Approach taken

Aligned the docs with the code — the cheapest of the two options the issue
offers. No guard logic was touched.

1. **`src/ConfigLoader.php`** — added a clarifying sentence to
   `validateCacheFilePermissions()`'s phpdoc: the fail-open warning only
   applies when the cache file is known to exist (`is_file()` already
   answered true); when `is_file()` itself cannot be answered (containing
   directory not stattable, EACCES), `loadFromCache()` falls through to
   `loadFresh()` and the caller gets `LogicException('Configuration not
   available')`, not the warning.
2. **`docs/security.md`** — reworked the "Unreadable metadata" bullet to the
   same effect, keeping its original structure (warning behaviour first, then
   the reachability qualification). It now states explicitly that the warning
   fires when `is_file()` succeeded but `fileperms()`/`fileowner()`/
   `filegroup()` return `false`, and that directory-EACCES leads to the
   `loadFresh()`/`LogicException` path without a warning.
3. **`tests/ConfigLoaderTest.php`** — added
   `testLoadFromCacheFallsThroughToLoadFreshWhenDirectoryIsUnreadable`, which
   pins the behaviour through the public API: warm the cache, `chmod 0000` the
   containing cache directory, assert `getWorkermanConfig()` throws
   `LogicException` with message "Configuration not available" — not a
   warning, not a `RuntimeException`. Restoration of the directory to `0700`
   happens in a `finally` so teardown's `removeDirectory()` can always clean
   up, and the test skips itself when the host's filesystem ignores the chmod
   (guard: `is_file()` still returns `true` after `chmod 0000`).
4. **`CHANGELOG.md`** — entry under `[Unreleased] → Fixed` with the `#614`
   reference in the house-style markdown-link form.

## What I rejected

- **Adding `is_dir()` / `is_readable()` checks before the `is_file()` gate**
  (the issue's second option, making the warning genuinely reachable for the
  directory-EACCES case). That is more code and more edge cases (what should
  the warned branch do when the file is `require`d and the directory is
  unusable? the `require` would still fail), and the issue itself marks it as
  "only worth it if the team wants the warning genuinely reachable". Refusing
  loudly on an unreadable directory is defensible security behaviour, and
  DEC-006 (security hardening from #582–#586 must stay intact) favours not
  loosening the failure path.
- **Changing the test that already proves the warning via reflection**
  (`testValidateCacheFilePermissionsLogsWarningWhenMetadataIsUnreadable` /
  `...TriggersWarningWhenMetadataUnreadableAndNoLogger`). They are correct:
  they call `validateCacheFilePermissions()` directly, bypassing the
  `is_file()` gate, which is exactly where the warn branch remains reachable.
  Kept untouched; the new test documents the gate instead.
- **Using bare `(#614)` in the changelog** (the task's suggested text):
  `ChangelogStructureTest` accepts both forms, but every recent entry uses the
  markdown link, so I matched the house style.

## Uncertainty

- `chmod 0000` on a directory does not reliably make `is_file()` return false
  on every host: root bypasses permission checks, some filesystems ignore the
  mode, and file-based ACLs can override it. Hence the skip guard inside the
  test — if the environment cannot reproduce the precondition, the test says
  so instead of failing.
- The PHP stat cache is a theoretical concern for the `is_file()` guard in the
  test; it is not a real one here because each test gets a unique `tempDir`
  (so no earlier test in the process can have statted the path) and `warmUp`'s
  `file_put_contents` does not populate the stat cache. If a future refactor
  makes paths shared across tests, a `clearstatcache()` after the chmod would
  be needed.
