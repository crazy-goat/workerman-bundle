# Issue #614 — review findings

Nothing is ever deleted from this file. Each round appends; the status column
records what happened to a finding, including disagreements.

## Round 1 (commit b1ee19b, reviewed against origin/master)

`findings-review.md` did not exist before this round — round 1, nothing to revisit.

### R1-1 | `docs/security.md:434-443`, `src/ConfigLoader.php:123-128` | medium

The narrowed reachability claim is still unreachable. The docs say the warning fires
when "`is_file()` succeeded but `fileperms()`/`fileowner()`/`filegroup()` return
`false`". On POSIX, statting `dir/file` requires search permission on `dir` and all
ancestors — strictly more than statting `dir` — so `is_file()` returning `true`
implies all four stats succeed. Reproduced: dir `0000` → `is_file(dir/f)=false` but
`fileperms(dir)=16384`; parent `0000` → both `false`. The branch is therefore
reachable only in a TOCTOU window, and the retained example "(e.g. on filesystems that
do not report permissions)" remains wrong — `fileperms()` returns `false` only when
`stat()` fails. Issue #614 is half-addressed: the prose went from wrong to narrower
but still describing something an operator will not see.

Status: **open — round 1**. Suggested fix stays inside the docs-only scope: state that
a successful `is_file()` implies the stats succeed, so the branch is a defensive guard
reachable only in a race; drop/qualify the "filesystems that do not report
permissions" example.

### R1-2 | `tests/ConfigLoaderTest.php:513-549` | medium

The new test never asserts its precondition. It warms the cache but never checks the
file exists; the only file check is the negative skip guard at :536. If `warmUp()`
ever stops writing the cache file, the test still goes green and becomes equivalent to
`testLoadFreshThrowsWhenNoConfigAndNoCache` (:706) — it would prove nothing. One line
fixes it: `$this->assertFileExists($cachePath);` between :521 and :529. Mutation
testing would catch this class; it is not in the gate set today.

Status: **open — round 1**.

### R1-3 | `src/ConfigLoader.php:127` | low

The phpdoc quotes `LogicException('Configuration not available')` as if it were the
literal message. The real message (:268-270) continues "no cached config file exists",
which is false in exactly the documented case — the file exists, it is unreachable. The
change thereby documents an error that misdiagnoses a permissions problem as a missing
cache. Coder flagged it out of scope (`findings-coder.md` item 1); a follow-up issue is
warranted, the reword is one line and carries no guard-logic risk.

Status: **open — round 1** (follow-up issue recommended, not a merge blocker).

### R1-4 | `docs/security.md:441-442`, `src/ConfigLoader.php:125-126` | low

"the containing directory cannot be statted (EACCES on the directory)" is imprecise: a
`0000` directory is still stattable; what fails is traversal into it. The test comment
at `tests/ConfigLoaderTest.php:525-527` states the mechanism correctly — the docs are
the loose ones. Suggested: "the containing directory is not searchable (no `x`
permission), so `stat()` on paths inside it fails with EACCES". Matters because
mechanism accuracy is the entire point of this change.

Status: **open — round 1**.

### R1-5 | `tests/ConfigLoaderTest.php:548` | nit

`chmod($cacheDir, 0700)` restores a hard-coded mode rather than the captured original.
Harmless today (`warmUp()` writes under `umask(0077)`), but the same pattern is used by
the sibling permission tests, so a change to the warm-up umask would silently make
several restores wrong.

Status: **open — round 1** (nit, no action required).

### R1-6 | `docs/proof_of_work/0614-configloader-fail-open-warning/code-decision-1.md` | nit

The recorded rationale claims PHPUnit converts `E_USER_WARNING` to a test error by
default. `phpunit.xml` sets no `failOnWarning`, and PHPUnit 10 treats a triggered
`E_USER_WARNING` as a non-fatal issue. The conclusion holds for this test (the
exception is thrown before any warning could fire, and the EACCES path never enters
`validateCacheFilePermissions()`), but the stated reason does not.

Status: **open — round 1** (nit; correct the rationale, or add `failOnWarning="true"`
under a separate issue if the guarantee is actually wanted).

### Verified clean in round 1

- DEC-006 intact: the `src/ConfigLoader.php` diff is docblock-only; `loadFromCache()`,
  `validateCacheFilePermissions()` and `checkCacheFilePermissions()` are unchanged. No
  permission check weakened or removed.
- FAQ-005 untouched; DEC-012 respected (no raw angle-bracket placeholders).
- CHANGELOG entry is under `[Unreleased]` → `### Fixed`, house-style issue link;
  `ChangelogStructureTest` and `MarkdownLinkTest` pass (135 tests, 601 assertions).
- The new test runs (does not skip) as a non-root user, and CI's `ubuntu-latest`
  runner is non-root, so the pin will actually execute in CI.
- `finally` restore covers the skip path; teardown cleans up; suite green
  (`ConfigLoaderTest`: 36 tests, 2 pre-existing root-required skips).

## Round 2 (fixes applied by main session)

### R1-1 — fixed

Reworked both `src/ConfigLoader.php:123-140` and `docs/security.md:434-447` to
state the POSIX implication precisely: statting `dir/file` requires search (`x`)
permission on the containing directory and every ancestor — strictly more than
statting `dir` — so whenever `is_file()` returns `true`, the four metadata reads
(`fileperms`/`filegroup` on the directory, `fileperms`/`fileowner` on the file)
all succeed. The warn branch is now described as a defensive guard reachable
through the public API only in a TOCTOU window (file unlinked between the gate
and the reads) or on filesystems where the POSIX implication does not hold
(ACLs, extended attributes). The stale "filesystems that do not report
permissions" example was removed.

### R1-2 — fixed

Added `$this->assertFileExists($cachePath);` between the `warmUp()` call and
the `chmod 0000` in `tests/ConfigLoaderTest.php:529-532`. If `warmUp()` ever
stops writing the cache file, the test now fails at the precondition instead of
degenerating into a duplicate of `testLoadFreshThrowsWhenNoConfigAndNoCache`.

### R1-3 — not fixed, deferred to a follow-up issue

The `LogicException` message reword ("no cached config file exists" → "no
cached config file could be loaded") is a one-line change with no guard-logic
risk, but it is outside this issue's docs-only scope and changes a user-facing
string. It will be offered as a separate GitHub issue in step 14. The phpdoc no
longer quotes the misleading tail verbatim — it says `LogicException`
("Configuration not available") without reproducing the "no cached config file
exists" wording.

### R1-4 — fixed

Both `src/ConfigLoader.php` and `docs/security.md` now say "the containing
directory is not searchable (no `x` permission), so `stat()` on paths inside it
fails with `EACCES`" instead of "cannot be statted".

### R1-5 — not fixed, consistent with siblings

The hard-coded `0700` restore matches every existing permission test in
`ConfigLoaderTest.php` (`testLoadFromCacheRefusesWorldWritableCacheDirectory`,
`...GroupWritable...`, `...SecureDirectoryAndFilePermissions...`). Capturing the
original mode would be a repo-wide refactor and is out of scope for a docs issue.

### R1-6 — fixed

The incorrect PHPUnit rationale in `code-decision-1.md` is superseded by
`code-decision-2.md`, which records the correct reason the test needs no
explicit error handler: in the EACCES case `validateCacheFilePermissions()` is
never entered (the `is_file()` gate returns `false` first), so no
`E_USER_WARNING` can fire — the PHPUnit `failOnWarning` setting is irrelevant to
this test.

## Round 2 — review verdict (commit 5e584b8, reviewed against origin/master)

The "Round 2 (fixes applied by main session)" section above was written by the
main session. The entries below are the reviewer's independent verification of
each disposition against the code on the branch, plus new findings.

### R1-1 — **fixed** (verified)

`src/ConfigLoader.php:118-141` and `docs/security.md:434-451` state the POSIX
implication correctly. Reproduced on PHP 8.5.9: dir `0000` →
`is_file(dir/f)=false` while `fileperms(dir)=16384`, `filegroup(dir)=20`. Also
verified the TOCTOU claim survives PHP's stat cache: an in-process `unlink()`
invalidates the cached entry, and the two `$cacheDir` stats in the
`checkCacheFilePermissions()` argument list (`src/ConfigLoader.php:145-152`)
evict the `$cachePath` entry before it is re-read — both orderings reproduce
`fileperms=false, fileowner=false` with the directory stats succeeding, i.e.
the `warn` branch. Stale "filesystems that do not report permissions" example
gone from all tracked sources.

### R1-2 — **fixed** (verified)

`tests/ConfigLoaderTest.php:529` — `assertFileExists($cachePath)` sits between
`warmUp()` and the `chmod 0000`, so it runs while the directory is still
searchable and does prevent the vacuous pass. Test executes (does not skip):
1 test, 3 assertions.

### R1-3 — **still present**, deferral accepted

`src/ConfigLoader.php:275-280` still reads "no cached config file exists",
which is false for the EACCES case. Docs no longer reproduce the misleading
tail, so the doc-level inconsistency this issue targeted is resolved. Stays
open on the record until the follow-up issue exists; two tests assert only the
`"Configuration not available"` prefix (:549, :714, :724), so the reword is
low risk when it happens.

### R1-4 — **fixed** (verified)

`src/ConfigLoader.php:129-132` and `docs/security.md:447-449` now say "not
searchable (no `x` permission), so `stat()` on paths inside it fails with
`EACCES`", matching the reproduction and the test comment at
`tests/ConfigLoaderTest.php:531-533`.

### R1-5 — **still present**, disposition accepted (nit)

`tests/ConfigLoaderTest.php:553` hard-codes `chmod($cacheDir, 0700)`,
consistent with :295-296, :330-331, :359-360, :391-392, :450-451, :457. Worth
noting this restore is load-bearing (a `0000` dir would break `tearDown()`)
where the siblings' are not — but it is inside `finally` and covers the skip
path. No action.

### R1-6 — **fixed** (verified)

`code-decision-2.md` rationale is accurate: `phpunit.xml` sets no
`failOnWarning`, PHPUnit is 10.5.63, and the EACCES path never enters
`validateCacheFilePermissions()` because the gate at `src/ConfigLoader.php:88`
returns `false` first. The superseded claim is correctly left in
`code-decision-1.md` rather than rewritten.

### R2-1 | `docs/security.md:447-451` | low | open

"When `is_file()` itself returns `false` … loading falls back to `loadFresh()`
and the caller gets a `LogicException`" is stated unconditionally, but
`getConfig()` (`src/ConfigLoader.php:70-74`) is
`loadFromMemory() ?? loadFromCache() ?? loadFresh()`. In a process that called
the setters (e.g. cache warmup) the in-memory config wins and no exception
occurs. An operator could read the bullet as "a bad cache-dir mode always
fatals". One-clause fix: "…and, when no config was set via setters, the caller
gets a `LogicException`". Same gap, less exposed, in the phpdoc at
`src/ConfigLoader.php:133-137`. No automated check covers this class today.

### R2-2 | `docs/security.md:438-451`, `src/ConfigLoader.php:122-137` | nit | open

The security-guide bullet now names private internals (`loadFromCache()`,
`loadFresh()`) and runs 18 lines. Renaming a private method silently makes a
security doc stale with no CI signal (`MarkdownLinkTest` resolves links, not
symbol names), and the operator takeaway is buried under POSIX stat
semantics. Not worth churn on its own; a convention test that asserts
backticked `identifier()` tokens in `docs/*.md` exist under `src/` would be
the check for this class.

### Verified clean in round 2

- DEC-006 intact — the `src/ConfigLoader.php` diff is still docblock-only;
  `loadFromCache()`, `validateCacheFilePermissions()` and
  `checkCacheFilePermissions()` are byte-identical to master.
- DEC-012 respected — no raw angle-bracket placeholders in the added
  `docs/security.md` / `CHANGELOG.md` lines.
- FAQ-005 untouched and still consistent with the reworked bullet.
- No gate lowered: `composer.json`, `phpunit.xml` and CI config are not in the
  diff; the 80% coverage floor is untouched.
- CHANGELOG entry under `[Unreleased]` → `### Fixed`, house-style issue link.
- `php vendor/bin/phpunit --no-coverage tests/ConfigLoaderTest.php
  tests/ChangelogStructureTest.php tests/MarkdownLinkTest.php` →
  165 tests, 679 assertions, 2 pre-existing skips, OK.
- Minor, not findings: `code-decision-2.md` cites the gate as line 87 (it is
  88); the new CHANGELOG bullet is blank-line separated where siblings are
  adjacent; the new test's name says "Unreadable" where the mechanism is
  "unsearchable" (the body comment is precise).

## Round 3 (R2-1/R2-2 dispositions applied by main session)

### R2-1 — fixed

Both `src/ConfigLoader.php:135-139` and `docs/security.md:448-453` now
qualify the `LogicException` outcome with "when no config was set via setters
(the normal server boot path)" and add that a process that did set config via
setters (e.g. cache warmup) uses the in-memory config and fatals nowhere. The
CHANGELOG entry was updated to match. The unconditional "the caller gets a
`LogicException`" wording is gone.

### R2-2 — not fixed, accepted

The security.md bullet still names `loadFromCache()` / `loadFresh()` and runs
long. Moving the full POSIX derivation into the phpdoc and shortening the
operator-facing bullet would be churn for little gain on a docs-only issue, and
the phpdoc already carries the full derivation. The candidate KB entry
("Docs that name private methods have no staleness gate") is noted for the
retro step — a convention test that asserts backticked `identifier()` tokens in
`docs/*.md` exist under `src/` would be the check for this class, but it is out
of scope here.

### Minor observations — fixed where trivial

- The blank-line separator before the #614 CHANGELOG bullet was removed so it
  is adjacent to its siblings (tight list, matching the surrounding style).
- The `code-decision-2.md` line-87 vs line-88 drift and the test name
  "Unreadable" vs "unsearchable" are left as-is — not worth churn.

## CI failure (escaped defect, step 11)

### CI-1 | `src/Command/BuildPathResolver.php:35`, `src/Phar/SfxSourceResolver.php:88` | medium | fixed

CI lint failed on PR #712 with Rector 2.6.2's `ReturnEarlyIfVariableRector`
flagging two **pre-existing** files (not touched by this issue's docs/test
change). Root cause: there is no committed `composer.lock`, so CI's
`composer install` resolved to the newest Rector (2.6.2), which introduced
the rule. Local Rector was 2.4.5, so `composer lint` passed locally but CI
failed — an environmental drift, not a regression from this PR.

Applied Rector's suggested early-return transformation to both files
(mechanical, safe, covered by `BuildPathResolverTest` and
`SfxSourceResolverTest` — 37 tests, 53 assertions, green). This keeps CI
green without lowering any gate. A committed `composer.lock` would prevent
this class of drift; that is a separate, repo-wide concern (candidate for a
process issue).
