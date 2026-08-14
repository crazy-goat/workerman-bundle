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
