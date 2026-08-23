# Findings — obstacles, surprises, bugs and weak spots (issue #613)

Work done in a fresh worktree on branch `test/issue-613-configloadertest-setup-creates-temp-dirs`.

## Confirmation of both umask runs

- `umask 0077 && vendor/bin/phpunit --filter ConfigLoaderTest`
- `umask 0000 && vendor/bin/phpunit --filter ConfigLoaderTest`

Both: **Tests: 48, Assertions: 112, Skipped: 3**, OK (PHPUnit 10.5.63,
PHP 8.5.9). The 3 skips are byte-identical in both runs and are all
pre-existing root-privilege skips (a foreign-group `chgrp`, a foreign-user
`chown` ×2) — none introduced by this change. The "1 PHPUnit test runner
warning / No code coverage driver" is an environment warning, present in
both runs, unrelated to the change.

## Obstacles / surprises

- The repo's note on proof-of-work: the four expected proof-of-work files —
  code-decision-1.md, findings-coder.md (and the review/decisions pair
  written by other roles) — live under `docs/proof_of_work/0613-.../`. This
  task only required the two named coder files, so I wrote exactly those.
  The directory already existed (empty) on the branch.

## Weak spots / possible improvements (outside this issue's scope)

1. **`tests/ConfigLoaderTest.php` setUp/tearDown do not restore the umask
   around anything but mkdir — but the fixture-env handling is the only
   umask-sensitive block.** No further umask sensitivity was found in this
   file. However, the **whole test file depends on `warmUp()`'s internal
   `umask(0077)` pin** to keep `cache/workerman` 0700 (see
   `src/ConfigLoader.php` warmUp around lines 50-60). If that pin were ever
   relaxed, `testLoadFromCacheRefusesWorldWritableCacheFile` /
   `...CacheDirectory` and friends would start leaking world-writable cache
   dirs under umask 0000. Consider a future test asserting warmUp keeps the
   produced dir non-world-writable, as a guard against regressing the
   production `warmUp()` pin. (Not changed here — out of scope, src/ frozen.)

2. **Related umask-sensitive fixture creation may exist elsewhere in the
   suite.** This issue covered only `ConfigLoaderTest`. A repo-wide audit of
   `mkdir(..., 0777/0666/0775/0664, true)` calls in `tests/` for the same
   umask-masked-mode footgun would be worthwhile — especially in the
   `tests/App/` fixture bootstraps, which create `var/cache`/`var/log`
   trees that the runner reuses. If any of those are masked to world-writable
   under umask 0000, the full suite could behave differently in containers.
   Not modified here (this issue scoped to ConfigLoaderTest only).

3. **A one-line nit on the fixture env var handling** (`setUp`/`tearDown`,
   ~lines 36-46 and 50-60 of the test file): the `getenv()`/`putenv()`
   sequence is correct and well-commented, and it is shared intention with
   `ConfigCacheGuardConfig`'s lazy resolution (KB FAQ-036). No bug — just
   noting it was reviewed and left intact.

4. **The two `chmod($cacheDir, 0777)` trust-variant tests** (`ProceedsWithWarning...`,
   ~line 845) chmod the same directory the setUp now creates at 0700; the
   explicit `0777` there is exactly what criterion 2 asks for (intent
   visible, umask-independent). Confirmed no change needed.

No functional bugs were found in the production code during this task.
