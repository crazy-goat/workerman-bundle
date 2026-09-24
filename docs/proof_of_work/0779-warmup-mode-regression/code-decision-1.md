## Round 1 — #779

Added `testWarmUpKeepsCacheArtifactsNonWorldWritableUnderPermissiveUmask` to `tests/ConfigLoaderTest.php`: it runs a real `warmUp()` while the process umask is `0000`, then asserts both the produced `cache/workerman` directory and `config.cache.php` have the world-writable bit clear (`& 0o002 === 0`). Forcing `umask(0000)` is what makes it a genuine guard: `ConfigLoader::warmUp()` pins `umask(0077)` only around the cache write, and the existing `testLoadFromCacheRefusesWorldWritable...` cases `chmod()` paths before calling the guarded loader, so they pass by construction and would not catch a relaxed pin.

Rejected asserting a specific mode (`0700`/`0600`): the parent fixture directory and platform umask interaction make the exact bits less portable, while the security property under test is precisely "not world-writable". The umask is restored in a `finally`.
