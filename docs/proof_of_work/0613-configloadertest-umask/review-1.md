# Review round 1 — umask-independent fixture dirs in ConfigLoaderTest (issue #613)

Commit: `9eaf8a4` (also the two proof-of-work docs `code-decision-1.md`,
`findings-coder.md`). Scope: `tests/ConfigLoaderTest.php`, `CHANGELOG.md`.

## Overall assessment

The code change is correct, minimal and faithful to the established in-repo
pattern (`ConfigLoader::warmUp()`). The umask pin genuinely makes the fixture
dirs umask-independent, the `finally` restore is correct, and no test relies
on the fixture dirs being group/other-accessible. **No functional defect and
no security-regression finding.** The findings below are documentation-accuracy
nits (stale recorded test counts, a slightly overstated rationale, and a
mistaken "three mkdir calls" claim) that do not block the change.

Two things were additionally verified independently:
- Both umask runs are identical (details below).
- Criterion 2 holds: the world-writable-directory tests `chmod` to `0777`
  explicitly and were already umask-independent.

## Verification performed (read-only)

- `git show 9eaf8a4` (stat + full diff of the 4 files).
- `umask 0077 && vendor/bin/phpunit --filter ConfigLoaderTest`
- `umask 0000 && vendor/bin/phpunit --filter ConfigLoaderTest`
  Both: **Tests: 48, Assertions: 112, Skipped: 3**, `OK` (PHPUnit 10.5.63,
  PHP 8.5.9), byte-identical except the timing line. The one PHPUnit runner
  warning ("No code coverage driver available") is an environment warning,
  present in both runs.
- `vendor/bin/phpstan analyse tests/ConfigLoaderTest.php --level=8` — OK, no
  errors.
- `vendor/bin/php-cs-fixer fix --dry-run tests/ConfigLoaderTest.php` — 0 files
  would be fixed.
- `php bin/check-changelog.php` — OK, CHANGELOG structurally valid.
- Read tag indexes of `docs/helpers/faq.md` and `docs/helpers/decisions.md`;
  read the tagged entries (DEC-006, DEC-016, DEC-017, FAQ-005, FAQ-036). No
  documented decision is violated (listed under KB check below).

## Findings

### F-1 (LOW, doc accuracy) — stale recorded test counts in both proof-of-work files
`code-decision-1.md` and `findings-coder.md` both record "44 tests, 100
assertions, 3 skips". The actual current run yields **48 tests / 112
assertions / 3 skips** (ConfigLoaderTest now has 40 `test*` methods plus
additional counted cases). The substantive claim — both umask runs are
identical — holds at the real numbers, so the change is unaffected; only the
recorded counts are stale. Severity: low (proof-of-work documentation
accuracy). Status: OPEN (doc only, non-blocking).
Check that would catch it: a scripted proof-of-work that pastes the actual
PHPUnit summary line back into the markdown instead of hand-typed numbers.

### F-2 (LOW) — rationale overstates that the setUp-created `cache` dir "tripped" the #586 guard
The comment in `setUp()` (tests/ConfigLoaderTest.php:23-27) and the
CHANGELOG bullet both claim that making the setUp-created `cache` and
`config/packages` dirs `0777` tripped the #586 directory-permission guard.
In fact the guard validates `dirname($cachePath)` = `cache/workerman`, which
`warmUp()` creates under its **own** internal `umask(0077)` pin, so it was
`0700` regardless of the process umask both before and after this change.
The setUp-created fixture `cache` dir was never the object the guard checks.
The change is still valuable — it removes world-writable *temp fixtures* from
a class whose whole subject is permission validation, and pins the fixture
modes as intended — so the fix stands. The comment/CHANGELOG would be more
precise framed as "sloppy hygiene / umask-independence", not "guard tripping".
Severity: low (comment/documentation accuracy). Status: OPEN (doc only).
Check that would catch it: tracing the actual validated path
(`dirname($cachePath)`) vs. the fixture dirs — a reviewer discipline, not an
obvious automated check (could be a comment-lint expecting the rationale to
name the exact validated path).

### F-3 (NIT) — "Three mkdir calls are inside setUp()" is inaccurate
`code-decision-1.md` ("What I rejected" / "Problem") says three `mkdir`
calls are in `setUp()`. There are exactly two (`config/packages` and
`cache`; the parent `$this->tempDir` is created implicitly as the top of the
first recursive `mkdir(..., true)`, not by its own call). Severity: nit.
Status: OPEN (doc only).

## Confirmations for the review requirements

### Criterion 1 — effective modes umask-independent
`$previousUmask = umask(0077)` returns the prior value; `try { mkdir(0777,
true) ×2 } finally { umask($previousUmask) }` restores it. Effective mode =
`0777 & ~0077 = 0700`, deterministic regardless of process umask. Correct.
No other umask-sensitive block is changed; setUp also unset the trust env var,
unchanged. The `cache` dir's contents (`cache/workerman`) are created by
`warmUp()` under its own pin and are unaffected by setUp.

### No reliance on the previous `0755` effective modes (0755→0700)
Grepped the whole file for mode dependencies. Every test that exercises a
permission branch `chmod`s the relevant path explicitly *before* the guarded
call: `0777` (lines 330, 856, 943), `0770` (365, 394, 426), `0750`/`0700`
(489/496), `0000` (594, restored 613), `0644`/`0666`/`0600` (281/308/329/458/
490/855/906). Nothing reads the setUp fixture dir modes as a precondition.
`tearDown()`'s recursive unlink/rmdir works on any mode the owner holds.
`config/packages` is not referenced by any test (created for hermeticity only).

### Criterion 2 — world-writable-directory tests already explicit
- `testLoadFromCacheRefusesWorldWritableCacheDirectory` — `chmod($cacheDir, 0777)` at line 330 before the guarded `getWorkermanConfig()` call. ✓
- `testLoadFromCacheProceedsWithWarningForWorldWritableDirectoryWhenTrustSet` — `chmod($cacheDir, 0777)` at line 856. ✓
- `testLoadFromCacheTriggersWarningViaErrorLogWhenTrustSetAndNoLogger` — `chmod(dirname($cachePath), 0777)` at line 943. ✓
No change needed; already umask-independent via explicit chmod.

### Consistency with production ConfigLoader::warmUp() (~line 55)
Test setUp now uses the same `umask(0077)` save/restore pattern as
`warmUp()`'s cache-write pin. Consistent; matches the coder's intent.

### CHANGELOG entry
Correctly placed at the end of the `### Tests` block under `## [Unreleased]`
(line ~284-296), references #613, does not duplicate an existing #613 entry
(only occurrence is this one), and passes `bin/check-changelog.php`
(issue reference present, unique subheadings, structural rules).

### PHPStan / PSR-12 / lint
PHPStan level 8: clean. php-cs-fixer dry-run: clean. No type issues with
`$previousUmask`/`umask()`.

### Missing tests
None. The change is test-harness plumbing; the 3 root-privilege skips are
pre-existing and unchanged. A future protective assertion that warmUp keeps
the produced dir non-world-writable (so the production pin can't regress
silently) was already proposed as a weak spot in `findings-coder.md` (out of
scope for this issue) and is worth recording — see candidate KB entry below.

## KB decision check (read-only)
- DEC-006 (security hardening stays intact): change touches tests only, makes
  fixtures *more* restrictive (0700), no production hardening loosened. No
  violation.
- DEC-016 (opt-outs in env, fail-closed): not touched. No violation.
- DEC-017 (error_log, not trigger_error): not touched. No violation.
- FAQ-005 / FAQ-036: informational, not violated by the change.
- DEC-009 (main session only KB writer): I read the KB, did not write it.

No documented decision is violated by this diff.

## Conclusion
The code change is correct and ready; the review is **not formally "clean"**
only because of three documentation-accuracy findings (F-1 stale counts, F-2
overstated rationale, F-3 "three mkdir calls") — all LOW/NIT, none blocking,
none requiring a code change. Both umask runs are confirmed identical at the
real counts (48/112/3).
