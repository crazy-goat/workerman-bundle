# Issue #614 — review round 2

Branch `docs/issue-614-configloader-fail-open-warning-is-unreac`, commits
`b1ee19b` (round 1) + `5e584b8` (round 2 fixes), reviewed against
`origin/master`.

Scope of the diff: `src/ConfigLoader.php` (docblock only), `docs/security.md`
(one bullet), `tests/ConfigLoaderTest.php` (one new test), `CHANGELOG.md`
(one entry), plus proof-of-work artifacts.

## 1. Earlier findings

`findings-review.md` exists from round 1 with six findings. Each is re-checked
against the current branch below; the round-2 dispositions written by the main
session were verified against the actual code, not taken on trust.

### R1-1 (medium) — **fixed**

`src/ConfigLoader.php:118-141` and `docs/security.md:434-451` now state the
implication explicitly: statting `dir/file` needs search (`x`) on the
containing directory and every ancestor — strictly more than statting `dir` —
so `is_file() === true` implies all four metadata reads succeed. Verified by
reproduction on this host (PHP 8.5.9, APFS):

```
dir 0000 → is_file(dir/f)=false, fileperms(dir)=16384, filegroup(dir)=20, fileperms(f)=false
```

The TOCTOU framing is also accurate, and I checked the one thing that could
have invalidated it — PHP's single-entry stat cache. `is_file($cachePath)`
populates the cache for that path, so a naive reading suggests the later
`fileperms($cachePath)` would be served from cache and never observe the
unlink. It does observe it, for two independent reasons: (a) an in-process
`unlink()` invalidates the cache entry, and (b) even for an external unlink,
the two `$cacheDir` stats in the argument list of
`checkCacheFilePermissions()` (`src/ConfigLoader.php:145-152`) evict the
`$cachePath` entry before it is re-read. Reproduced both orderings — both
yield `fileperms=false, fileowner=false` with the directory stats succeeding,
i.e. exactly the `warn` branch. The claim is correct as written.

The stale "filesystems that do not report permissions" example is gone;
`grep` over tracked `.md`/`.php` finds no remaining occurrence outside the
round-1 review artifact (which is history and must not be edited).

### R1-2 (medium) — **fixed**

`tests/ConfigLoaderTest.php:529` adds `$this->assertFileExists($cachePath);`
between `warmUp()` and the `chmod 0000`. This does prevent the vacuous pass:
if `warmUp()` ever stopped writing the file, the test now fails at the
precondition instead of silently becoming a duplicate of
`testLoadFreshThrowsWhenNoConfigAndNoCache` (:711). Ordering is right — the
assertion is before the chmod, so it runs while the directory is still
searchable. Test executes (does not skip) on this host: 1 test, 3 assertions.

### R1-3 (low) — **still present, deferred (accepted)**

`src/ConfigLoader.php:275-280` still throws `'Configuration not available: no
config has been set via setters and no cached config file exists.'` In the
documented EACCES case the file *does* exist, so the message misdiagnoses a
permissions problem as a missing cache. The phpdoc and `docs/security.md` no
longer reproduce the misleading tail (they quote only the
`"Configuration not available"` prefix), which removes the doc-level
inconsistency this issue was about. Deferring the string change to a separate
issue is defensible: it is user-facing output, two tests assert on the prefix
only (:549, :714, :724) so a reword is safe, but it is out of a docs-only
scope. Keeping this on the record as open until that follow-up issue exists.

### R1-4 (low) — **fixed**

Both `src/ConfigLoader.php:129-132` and `docs/security.md:447-449` now read
"the containing directory is not searchable (no `x` permission), so `stat()`
on paths inside it fails with `EACCES`". That matches the reproduction above
(the `0000` directory is still stattable — `fileperms(dir)` returned `16384`)
and matches the test comment at `tests/ConfigLoaderTest.php:531-533`.

### R1-5 (nit) — **still present, disposition accepted**

`tests/ConfigLoaderTest.php:553` restores a hard-coded `chmod($cacheDir,
0700)`. Every sibling permission test uses the same hard-coded pattern
(:295-296, :330-331, :359-360, :391-392, :450-451, :457). Note this restore is
load-bearing in a way the siblings' are not — a `0000` directory would break
`tearDown()`'s recursive cleanup — but it is inside `finally`, covers the
skip path, and the suite is green, so the risk is theoretical. No action.

### R1-6 (nit) — **fixed**

`code-decision-2.md` records the corrected rationale and it is accurate:
`phpunit.xml` sets no `failOnWarning` (verified — the file has no such
attribute), PHPUnit 10.5.63 reports a triggered `E_USER_WARNING` as a
non-fatal issue, and in the EACCES case `validateCacheFilePermissions()` is
never entered because the `is_file()` gate at `src/ConfigLoader.php:88`
returns `false` first, so no `E_USER_WARNING` can fire at all. The superseded
claim in `code-decision-1.md` is left in place, which is correct for an
append-only record.

## 2. Verdict

**Approve.** The four findings the round-2 commit set out to fix are genuinely
fixed, verified against the code and by reproducing the underlying filesystem
behaviour rather than by reading the disposition notes. The two deferred ones
have defensible dispositions. `src/ConfigLoader.php` remains docblock-only —
`loadFromCache()`, `validateCacheFilePermissions()` and
`checkCacheFilePermissions()` are byte-identical to master, so DEC-006 is
intact and no gate was lowered (`composer.json` is not in the diff).

Two low/nit findings remain below; neither blocks the PR.

## 3. New findings

### R2-1 | `docs/security.md:447-451` | low

> "When `is_file()` itself returns `false` … loading falls back to
> `loadFresh()` and the caller gets a `LogicException`"

is stated unconditionally, but `getConfig()` (`src/ConfigLoader.php:70-74`) is
`loadFromMemory() ?? loadFromCache() ?? loadFresh()`. In any process that has
called the setters — notably the cache-warmup process itself — the in-memory
config wins and `loadFromCache()` is never reached, so an unsearchable cache
directory produces no exception at all. An operator reading this security
guide could conclude that a bad cache-dir mode always fatals; it only fatals
in a process that did not set config via setters (the normal server boot).
Suggested one-clause fix: "…and, when no config was set via setters, the
caller gets a `LogicException`". The same sentence in the phpdoc
(`src/ConfigLoader.php:133-137`) is less exposed, but has the same gap.

No automated check would catch this class today; it is a prose/precedence
mismatch. The closest plausible gate is the existing convention-test culture
(`ChangelogStructureTest`, `MarkdownLinkTest`) — see the candidate KB entry
below for the check that *would* pay off here.

### R2-2 | `docs/security.md:438-451`, `src/ConfigLoader.php:122-137` | nit

The security-guide bullet now names three private internals
(`loadFromCache()`, `loadFresh()`, and the four `fileperms`/`filegroup`/
`fileowner` calls) and runs 18 lines in an operator-facing document. Two
consequences:

1. Renaming any of those private methods silently makes a *security* doc
   stale, and nothing in the gate set notices — `MarkdownLinkTest` resolves
   links, not symbol names.
2. The operator takeaway (an unsearchable cache dir yields a hard error, not
   the warning) is now the last sentence of a paragraph about POSIX stat
   semantics.

Not worth churn on its own; worth knowing if this bullet is edited again. The
phpdoc is the right home for the full derivation — `docs/security.md` could
carry two sentences and defer.

### Minor observations, no finding

- `code-decision-2.md` cites the `is_file()` gate as
  `src/ConfigLoader.php:87`; it is line 88. Line refs in artifacts drift by
  nature — noted, not actionable.
- The new CHANGELOG bullet is separated from its predecessor by a blank line
  where the surrounding bullets are adjacent (loose vs tight list). The #686
  entry already mixes both styles and `ChangelogStructureTest` passes.
- `testLoadFromCacheFallsThroughToLoadFreshWhenDirectoryIsUnreadable` keeps
  "Unreadable" in its name while the whole point of R1-4 was that the
  directory is *unsearchable*, not unreadable. The body comment is precise;
  renaming a green test for a word is not worth it.

## 4. Candidate knowledge-base entries

Proposed only — I do not write to `docs/helpers/`.

**Title:** `is_file()` succeeding implies the directory and file stats succeed
**Tags:** `config-cache`, `permissions`, `security`, `docs`
**Trigger:** documenting or testing a fail-open branch guarded by an
`is_file()` / `file_exists()` gate

On POSIX, statting `dir/file` requires search (`x`) permission on `dir` and
every ancestor — strictly more than statting `dir` itself — so once
`is_file($path)` returns `true`, `fileperms()`/`filegroup()` on the directory
and `fileperms()`/`fileowner()` on the file all succeed. Any "metadata could
not be read" branch behind such a gate is therefore a defensive guard, not an
operator-visible path: it is reachable only in a TOCTOU window (the file is
unlinked between the gate and the reads — PHP's single-entry stat cache does
not mask this, because an in-process `unlink()` invalidates the entry and an
intervening stat of a different path evicts it) or on filesystems where the
POSIX implication does not hold. Documenting such a branch as something an
operator will see is wrong; documenting a `0000` directory as "cannot be
statted" is also wrong — the directory stats fine, traversal into it is what
fails with `EACCES`. Issue #614, rounds 1–2.

**Title:** Docs that name private methods have no staleness gate
**Tags:** `docs`, `markdown`, `tests`
**Trigger:** adding implementation detail (private method or function names)
to a user-facing doc under `docs/`

`MarkdownLinkTest` resolves links and `ChangelogStructureTest` checks
changelog shape, but nothing verifies that a symbol named in prose still
exists in `src/`. A doc that says "`loadFromCache()` gates on
`is_file($cachePath)`" goes stale on rename with no CI signal — and
`docs/security.md` going stale is worse than most. Either keep private
internals in phpdoc (where they sit next to the code) or add a convention
test that greps backticked `identifier()` tokens in `docs/*.md` and asserts
each exists somewhere under `src/`.

## 5. Gaps in validation / checked clean

Checked clean:

- DEC-006 intact — `git diff origin/master -- src/ConfigLoader.php` touches
  only the docblock of `validateCacheFilePermissions()`; no permission check
  weakened, removed or reordered.
- DEC-012 respected — no raw angle-bracket placeholders in the added
  `docs/security.md` or `CHANGELOG.md` lines (grepped the `+` lines).
- FAQ-005 untouched — the ownership/Docker guidance is unchanged and still
  consistent with the reworked bullet.
- No gate lowered: `composer.json`, `phpunit.xml` and CI config are not in the
  diff; the 80% coverage floor is untouched.
- CHANGELOG entry sits under `[Unreleased]` → `### Fixed` (lines 48-157) with
  the house-style issue link.
- Tests run locally:
  `php vendor/bin/phpunit --no-coverage tests/ConfigLoaderTest.php
  tests/ChangelogStructureTest.php tests/MarkdownLinkTest.php` →
  **165 tests, 679 assertions, 2 skipped, OK**. The two skips are the
  pre-existing root-required ones; the new test executes (verified with
  `--testdox --filter FallsThroughToLoadFresh`: 1 test, 3 assertions, OK).
- The `finally` restore covers the `markTestSkipped` path, so a root runner
  cannot leave a `0000` directory behind and break `tearDown()`.

Gaps:

- The `warn` branch itself is still only covered through the pure
  `checkCacheFilePermissions()` unit tests (:559-660); no test exercises the
  TOCTOU path end-to-end. That is the right call — a race is not worth
  simulating — but it means the doc's central claim ("reachable only in a
  TOCTOU window") is verified by reasoning and manual reproduction, not by
  CI. Mutation testing would be the check that catches the *R1-2* class
  (assertion-free preconditions); it is not in the gate set today.
- Nothing verifies that prose in `docs/` still matches code (R2-1, R2-2). The
  convention-test proposed above is the check for that class.
