# Issue #614 — coder findings

Out-of-scope observations (not addressed in this change):

1. **`src/ConfigLoader.php` — the `LogicException` message
   (thrown by `loadFresh()`) misleads for the directory-EACCES case.** When
   the cache *file* exists but the directory cannot be statted, the caller
   sees `LogicException("Configuration not available: no config has been set
   via setters and no cached config file exists…")` — the file *does* exist,
   it just cannot be reached. Suggested fix (would need its own issue):
   reword to "…and no cached config file could be loaded", or have
   `loadFromCache()` distinguish "file absent" from "file unreachable" in the
   message.

2. **`tests/ConfigLoaderTest.php` — the reflection tests
   (`testValidateCacheFilePermissionsLogsWarningWhenMetadataIsUnreadable` /
   `...TriggersWarningWhenMetadataUnreadableAndNoLogger`) exercise a broader
   case than their names suggest.** They pass
   `cache/workerman/does-not-exist.php`, and `cache/workerman` itself is never
   created by `setUp` (only `cache/` is), so *all four* metadata reads
   (`fileperms`/`filegroup` on the dir, `fileperms`/`fileowner` on the file)
   return false — verified on this host. The system-level `E_USER_WARNING`
   pairs fine with the unit-level
   `testCheckCacheFilePermissionsWarnsWhenMetadataIsUnreadable`, so nothing is
   defective; the naming just hides that the warn branch fires for any stat
   failure (including absent paths), which is exactly why the `is_file()`
   gate is what separates "warn" from "`LogicException`" in the public API.
   No fix needed.

3. **`src/ConfigLoader.php` (~line 208) — warn message wording for absent
   paths.** `"Cannot verify permissions of the configuration cache file
   \"%s\"; loading it without a permission check"` is emitted for any stat
   failure. Through the public API the gate makes this reachable only when
   the file exists, but when called directly (as the reflection tests do)
   with a nonexistent path the message claims loading "proceeds" — which
   nothing actually does, since the caller is only validating. Minor wording
   nit; not worth an issue on its own.

4. **`docs/security.md` — neighbouring bullets ("Ownership check",
   "World-writable file check") still describe *refusal* conditions without
   stating that they are gated on `is_file()` like the warning is.** A reader
   could assume those checks also run when the directory is unreadable. The
   new "Unreadable metadata" bullet makes the gate explicit for the warning;
   the refusal bullets remain accurate for the only case they can reach.
   Flagging for the docs reviewer; no change needed.

## Obstacles encountered during implementation

- The test precondition (chmod 0000 → `is_file()` false) is host-dependent:
  root bypasses permission checks and some filesystems ignore the mode. The
  skip guard (`if (is_file($cachePath))` after the chmod) is the whole point
  — it makes the test honest instead of silently meaningless.
- The `finally { chmod($cacheDir, 0700); }` is load-bearing: teardown's
  `removeDirectory()` needs the directory readable. A unique `tempDir` per
  test prevents cross-test poisoning either way, but the cleanup would still
  fail without the restore.
- PHPUnit converts `E_USER_WARNING` to a test error by default, so if the
  code under test ever reached the `trigger_error` fallback for this case,
  the new test would fail loudly rather than silently pass — the "NOT a
  warning" half of the pin needs no explicit error handler.
