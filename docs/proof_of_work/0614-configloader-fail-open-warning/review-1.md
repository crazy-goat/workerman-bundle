# Issue #614 — review round 1

Branch `docs/issue-614-configloader-fail-open-warning-is-unreac`, commit `b1ee19b`,
diffed against `origin/master`.

## 0. Earlier findings

`docs/proof_of_work/0614-configloader-fail-open-warning/findings-review.md` does not
exist — this is round 1, nothing to revisit. Not an error. Straight to hunting.

## 1. Knowledge base entries consulted

Tags matched from the diff (`config`, `config-cache`, `permissions`, `docs`,
`security`, `markdown`):

- **FAQ-005** (config-cache, permissions, docker) — cache ownership / warm-as-root.
  Not touched; the change adds no new ownership claim and does not contradict the
  README / security.md worked examples. No violation.
- **FAQ-019** (docs) — docs-claims-vs-runtime-support class of bug. Directly relevant
  as a *class*; see finding R1-1: the new prose is closer to the code than master's
  but still overstates reachability.
- **FAQ-024** (config, deprecation) — not applicable.
- **DEC-006** (security, policy) — "cache directory permissions and ownership are
  checked before `require` (#586, #611)". **Intact.** `git diff origin/master -U0 --
  src/ConfigLoader.php` contains only docblock lines (`*` prefixed): no `+`/`-` line
  outside the comment. `loadFromCache()` (src/ConfigLoader.php:83-95),
  `validateCacheFilePermissions()` (133-158) and `checkCacheFilePermissions()`
  (181-263) are byte-identical to master. Nothing loosened, nothing removed.
- **DEC-012** (docs, markdown) — no raw `<placeholder>` introduced in
  `docs/security.md` or `CHANGELOG.md`. Clean.

## 2. Verdict

**Approve with follow-ups.** The change does what the issue asked for by the cheapest
route: docs aligned to code, no guard logic touched, one behavioural pin added. No
blockers, no security regression, correct CHANGELOG placement.

Two things keep it from being a clean "nothing to say":

- the new reachability claim is *still* not the whole truth (R1-1, medium) — on POSIX
  the fail-open warn branch remains effectively unreachable through the public API
  even in the narrowed form the docs now describe;
- the new test has no assertion that its precondition (a warmed cache file) actually
  exists, so it can degrade into a duplicate of an existing test without anyone
  noticing (R1-2, medium).

Neither needs to block the merge; R1-1 is a two-sentence docs edit that would finish
the job the issue started, and R1-2 is a one-line `assertFileExists`.

## 3. What I verified

| Check | Result |
| --- | --- |
| No guard-logic change | Confirmed — src diff is 100% docblock (`git diff origin/master -U0 -- src/ConfigLoader.php \| grep -vE '^[+-]\s*\*'` yields only the file headers) |
| `is_file()` gate location | `src/ConfigLoader.php:86-88` — gate runs before `validateCacheFilePermissions()` at :92. Docs claim correct |
| `loadFresh()` outcome | `src/ConfigLoader.php:266-272` — unconditional `LogicException`. The docs claim "the caller gets a `LogicException`" holds for every path that reaches it |
| Only production caller of the permission check | `grep -rn checkCacheFilePermissions src/` → only `ConfigLoader`; `validateCacheFilePermissions` is called only from `loadFromCache()`. No second entry point that would falsify the "gated on `is_file()`" claim (Runner reaches it only through `ConfigLoader`) |
| EACCES mechanism | Reproduced: dir `0000` → `is_file(dir/f)` `false`, `fileperms(dir)` `16384` (still succeeds); parent `0000` → both `false`. See R1-1 / R1-4 |
| Test actually runs (does not always skip) | `vendor/bin/phpunit --filter ConfigLoaderTest --display-skipped` → 36 tests, only the two pre-existing root-required tests skip. The new test executes and passes as uid 501 |
| CI will run it | `.github/workflows/tests.yaml` uses `runs-on: ubuntu-latest` with no container/`user:` — tests run as the non-root `runner` user, so the skip guard will not fire on CI |
| `finally` restore | `tests/ConfigLoaderTest.php:547-549` — runs for both the `markTestSkipped` path (skip throws) and the expected-exception path; `tearDown()`'s `removeDirectory()` succeeds (suite is green, no leftover `/tmp/config-loader-test-*`) |
| CHANGELOG placement | Line 156, inside `[Unreleased]` (line 8) → `### Fixed` (line 48), before `## [0.25.0]` (line 159). Issue reference in the house markdown-link form |
| Structure/link gates | `vendor/bin/phpunit --filter 'ChangelogStructureTest\|MarkdownLinkTest'` → OK (135 tests, 601 assertions) |
| Style | Backticks in docblocks are house style (16 files in `src/` do it); line lengths fine; no PSR-12 concern. Nothing to report |

## 4. Findings

### R1-1 | `docs/security.md:434-443`, `src/ConfigLoader.php:123-128` | medium

The narrowed condition is still not reachable. The docs now say the warning fires
"when the cache file is known to exist (`is_file()` succeeded) but
`fileperms()`/`fileowner()`/`filegroup()` return `false`". On POSIX that conjunction
is essentially impossible: statting `dir/file` requires search permission on `dir`
*and* every ancestor, which is strictly stronger than what statting `dir` requires.
Reproduced:

```
dir 0000    → is_file(dir/f) = false, fileperms(dir) = 16384   (dir still stattable)
parent 0000 → is_file(dir/f) = false, fileperms(dir) = false
```

There is no arrangement where `is_file($cachePath)` is `true` and any of the four
stats fail, other than a TOCTOU window (the file is unlinked between the gate and
`fileperms()`). `fileperms()` returns `false` only when `stat()` fails — it never
returns "this filesystem does not report permissions" — so the retained example in
the same bullet, "(e.g. on filesystems that do not report permissions)", is still
inaccurate and is the sentence that originally created the docs-vs-behaviour gap.

Net effect: the change moves the docs from "wrong" to "narrower but still describing
a branch an operator will never see". Issue #614's complaint is half-addressed. The
finishing edit is cheap and stays inside the chosen "docs only" scope: state that on
POSIX a successful `is_file()` implies all four stats succeed, so the fail-open branch
is a defensive guard reachable only in a race (or on a non-POSIX/ACL filesystem where
the implication does not hold), and drop or qualify the "filesystems that do not
report permissions" example.

*Automated check that would catch this class:* none realistically — this is a prose
claim about a conjunction of syscall outcomes. The nearest mechanical proxy is the
existing convention: a documented branch should have a test that reaches it *through
the public API*; the warn branch has only reflection tests
(`tests/ConfigLoaderTest.php:478`, `:503`, `tests/RunnerTest.php:752`), which is
itself the signal that the branch is API-unreachable.

### R1-2 | `tests/ConfigLoaderTest.php:513-549` | medium

The test never asserts that its precondition holds. It warms the cache but never
checks the file was written; the only file check is the *negative* skip guard
`if (is_file($cachePath)) markTestSkipped(...)` at :536. If `warmUp()` ever stops
writing `config.cache.php` (renamed path, changed `ConfigCache` semantics, silent
write failure), the guard passes, `getWorkermanConfig()` throws `LogicException`
because there simply is no cache — and the test goes green while proving nothing.
In that state it is byte-for-byte equivalent to the existing
`testLoadFreshThrowsWhenNoConfigAndNoCache` (:706-714), which already passes with no
cache at all.

One line fixes it: `$this->assertFileExists($cachePath);` between the `warmUp()` call
(:521) and the `chmod` (:529). Optionally also assert the file is loadable again after
the `finally` restore, which would prove the fall-through was caused by the EACCES and
not by an absent cache.

*Automated check that would catch this class:* mutation testing (neuter `warmUp()`'s
write and see that no test fails). Not currently in the gate set; a review-level catch
today.

### R1-3 | `src/ConfigLoader.php:127` | low

The phpdoc quotes the outcome as `LogicException('Configuration not available')`,
which reads as the literal message. The real message (`src/ConfigLoader.php:268-270`)
continues "…: no config has been set via setters and no cached config file exists" —
which is factually wrong in exactly the case being documented: the cached config file
*does* exist, it is merely unreachable. The change therefore documents, as intended
behaviour, an error message that misdiagnoses a permissions problem as a missing
cache. The coder flagged this as out of scope (`findings-coder.md` item 1) and that is
a defensible scope call, but it deserves a follow-up issue rather than only a
proof-of-work note: rewording to "no cached config file could be loaded" is a one-line
change with no guard-logic risk, and it is the part of #614 an operator actually
feels.

### R1-4 | `docs/security.md:441-442`, `src/ConfigLoader.php:125-126` | low

Imprecise mechanism wording: "the containing directory cannot be statted (EACCES on
the directory)". With the directory at `0000` the directory *is* stattable
(`fileperms($cacheDir)` returns a value, see R1-1); what fails is *traversal into* it,
so `stat()` on a path inside returns EACCES. The test comment at
`tests/ConfigLoaderTest.php:525-527` gets this right ("makes stat() on a path inside
it fail with EACCES") — the docs are the loose ones. Suggested wording: "the
containing directory is not searchable (no `x` permission), so `stat()` on paths
inside it fails with EACCES". Worth fixing because this change's whole purpose is
mechanism accuracy.

### R1-5 | `tests/ConfigLoaderTest.php:548` | nit

`chmod($cacheDir, 0700)` restores a hard-coded mode instead of the mode captured
before the test. Harmless today (`warmUp()` writes under `umask(0077)`, so the
directory is already `0700`), and the directory is torn down immediately after. Only
worth noting because the sibling permission tests use the same hard-coded-restore
pattern, so if the warm-up umask ever changes, several tests silently start restoring
the wrong mode.

### R1-6 | `docs/proof_of_work/0614-configloader-fail-open-warning/code-decision-1.md` | nit

The recorded rationale says "PHPUnit converts `E_USER_WARNING` to a test error by
default, so … the 'NOT a warning' half of the pin needs no explicit error handler."
`phpunit.xml` sets no `failOnWarning`, and PHPUnit 10 reports a triggered
`E_USER_WARNING` as a non-fatal issue rather than failing the test. The conclusion is
still correct for this test (the exception is thrown long before any warning could
fire, and in the EACCES case `validateCacheFilePermissions()` is never entered), but
the stated reason is not. Recording a wrong reason is how a future change ends up
relying on a guarantee that does not exist.

## 5. Candidate knowledge-base entries (proposed, not written)

**FAQ candidate — "A successful `is_file()` implies its directory stats succeed, so
`ConfigLoader`'s fail-open warn branch is API-unreachable"**
tags: `config-cache`, `permissions`, `docs`
trigger: "documenting or testing the ConfigLoader cache-permission fail-open branch"

`ConfigLoader::loadFromCache()` gates on `is_file($cachePath)` before
`validateCacheFilePermissions()` runs. On POSIX, statting `dir/file` needs search
permission on `dir` and every ancestor — strictly more than statting `dir` — so
whenever `is_file()` returns `true`, `fileperms()`/`filegroup()` on the directory and
`fileperms()`/`fileowner()` on the file all succeed. Verified: a `0000` directory
leaves `fileperms($dir)` working while `is_file($dir/$f)` returns `false`. The
consequence is that the `warn` branch of `checkCacheFilePermissions()` is reachable
through the public API only in a TOCTOU window, and every test that exercises it
(`ConfigLoaderTest::testValidateCacheFilePermissions*`, `RunnerTest`) must call the
private method by reflection. Keep the branch (it is a defensive guard and unit-tested
in isolation), but do not describe it in prose as something an operator will observe —
that claim is what issue #614 was filed against.

**Process candidate — "A behavioural pin that depends on `chmod` must assert its
precondition, not only guard it"**
tags: `tests`, `permissions`
trigger: "writing a test that chmods a fixture to force a failure path"

A test of the form "warm a fixture → `chmod 0000` → assert the failure path" passes
both when the chmod worked and when the fixture was never created, because the failure
path is the same. Assert the fixture exists (`assertFileExists`) before the `chmod`, so
the test fails loudly instead of degenerating into a duplicate of the "nothing was ever
written" test. See `ConfigLoaderTest::testLoadFromCacheFallsThroughToLoadFreshWhenDirectoryIsUnreadable`
(#614) and finding R1-2.

## 6. Gaps in validation / areas checked clean

**Clean:**

- No guard logic, no control flow, no permission check touched — DEC-006 verified
  intact by diff inspection, not by assumption.
- CHANGELOG section, ordering, issue-link form; `ChangelogStructureTest` and
  `MarkdownLinkTest` green.
- Test cleanup is correct: `finally` covers the skip path, teardown succeeds, no
  cross-test pollution (unique `tempDir` per test, and the stat cache holds one entry
  which `chmod($cacheDir, …)` displaces anyway).
- The skip guard is honest — it skips exactly when the host cannot reproduce the
  precondition (root, or a filesystem that ignores the mode), and it will not fire on
  the project's CI runners.
- No `<placeholder>` regression (DEC-012), no phpdoc style deviation.

**Gaps nothing currently covers:**

- No check ties a documented behaviour to a test that reaches it through the public
  API. That is precisely the gap #614 reports, and after this change the docs still
  describe a branch only reflection can reach (R1-1).
- No mutation coverage, so a test whose precondition silently stops holding (R1-2)
  stays green.
- The `LogicException` message is not asserted in full anywhere; both tests match the
  substring `'Configuration not available'`, so the misleading tail (R1-3) is
  unpinned and can drift either way.
