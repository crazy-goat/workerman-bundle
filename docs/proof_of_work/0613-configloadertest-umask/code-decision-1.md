# Code decision 1 — umask-independent fixture dirs in ConfigLoaderTest

Issue #613. Scope: `tests/ConfigLoaderTest.php`, `CHANGELOG.md`.

## Problem

`ConfigLoaderTest::setUp()` created its fixture dirs with
`mkdir($path, 0777, true)`. The `0777` is **masked by the process umask**,
so the effective modes depended on how PHPUnit was launched. Under a
permissive umask (e.g. a container `umask 0000`, flagged in
`docs/security.md`) the `config/packages` and `cache` dirs came out `0777`
world-writable — sloppy hygiene for a class whose whole subject is permission
validation, and inconsistent with the restrictive posture the tests otherwise
enforce.

Two `mkdir` calls are inside `setUp()` (`config/packages` and `cache`, both
recursive). The parent `$this->tempDir` is created implicitly as the top of the
first recursive `mkdir(..., true)`, not by its own call. Both were
umask-dependent.

## Approach taken

Pin the umask around the `mkdir` calls in `setUp()`, **not** `chmod` after
creation — the same save/restore pattern `ConfigLoader::warmUp()`
(`src/ConfigLoader.php` ~50-60) already uses:

```php
$previousUmask = umask(0077);
try {
    mkdir($this->tempDir . '/config/packages', 0777, true);
    mkdir($this->tempDir . '/cache', 0777, true);
} finally {
    umask($previousUmask);
}
```

The `mkdir(0777)` call is left unchanged; pinning `umask(0077)` makes the
*effective* mode `0700` deterministically, regardless of the process umask.
With `umask(0077)` and `umask(0000)` the suite now produces identical
results (verified, below).

### What I rejected, and why

- **`chmod(...)` after `mkdir(...)`** — a caller's umask can veto even an
  explicit `chmod`. Not only is it not umask-independent, it foregoes the
  already-proven in-repo pattern for exactly this shape of problem
  (`warmUp()`). It also leaves a window (however small) with an unscoped
  default-umask dir. The umask-pin is the correct tool because it makes
  `mkdir`'s own mode argument the single source of truth, which is the
  whole point of the criterion.
- **Changing the `mkdir` mode argument to `0755`** — considered, but I
  deliberately kept `0777` so the pinned umask yields `0700`, mirroring
  `warmUp()`'s effective result and *not* silently loosening the intended
  0700 posture to a hypothetical 0755. The minimal, faithful change is to
  pin the umask and leave the mode argument alone.

## Does anything depend on the previous 0755 effective modes?

No. Every test that exercises a specific permission branch `chmod`s the
relevant path explicitly *before* the guarded call:

- World-writable dir branches: `chmod($cacheDir, 0777)` at former lines
  ~319, ~845, ~932 — always an explicit `0777`, so they were already
  umask-independent and satisfy criterion 2 with **no change**.
- Other branches `chmod` explicitly to `0770`, `0750`, `0700`, `0000`,
  `0644`, `0666`, `0600` before asserting.
- `tearDown()` removes the temp tree with `removeDirectory()` (recursive
  unlink/rmdir), which works on any mode the owning user holds — `0700` is
  fine. No code path reads the fixture dirs' modes as a precondition.

The guard's subject, `$this->tempDir . '/cache/workerman'`, is created by
`warmUp()` itself under its own `umask(0077)`, so it was already
umask-independent.

## Anything uncertain

None material. One cosmetic note: the sets of **skipped** tests are
identical in both runs (3 skips, all pre-existing root-privilege skips —
`chgrp` to a foreign group, `chown` to another user ×2). No new skips were
introduced by the change.
