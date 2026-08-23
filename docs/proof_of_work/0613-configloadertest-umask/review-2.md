# Review round 2 — umask-independent fixture dirs in ConfigLoaderTest (issue #613)

Commits: 9eaf8a4 (change + docs), 810b055 (round-1 answers). Scope:
`tests/ConfigLoaderTest.php`, `CHANGELOG.md`, `docs/proof_of_work/0613-...`.

## Round-1 findings

### F-1 (stale counts) — STILL PRESENT, as a newly-stale set (see N-1)
`findings-coder.md` records 48/112/3, but the actual run of the exact protocol
command (`umask ... && vendor/bin/phpunit --filter ConfigLoaderTest`) is
**52 / 124 / 3** under BOTH umasks (identical). The 48 was correct at round 1
when only two 0613 `.md` files existed; 810b055 added `review-1.md` +
`findings-review.md`, and `--filter` is a substring regex that also matches
cross-class tests whose data providers reference `ConfigLoaderTest.php`
(`MarkdownLinkTest` over each tracked `.md` file, now 4 × 2 = 8 cases, plus
`FinalClassTest` and `TestNamespaceConventionTest`). Class-scoped run
(`phpunit tests/ConfigLoaderTest.php`) is a stable **40 / 96 / 3**. The
identical-results claim holds at the real numbers.

### F-2 (overstated rationale) — FIXED
Comment (`tests/ConfigLoaderTest.php:23-27`) and the CHANGELOG entry are
reframed as umask-independence / hygiene; no "guard-tripping" claim remains.
Accurate — the guard validates `dirname($cachePath)` = `cache/workerman`,
created by `warmUp()` under its own `umask(0077)` pin (0700 regardless), so the
setUp fixture dir was never the guard's object. Code still correct.

### F-3 (three mkdirs) — FIXED
`code-decision-1.md` now states two `mkdir` calls and explains the parent
`$this->tempDir` is created implicitly by the first recursive `mkdir`.

## New finding

### N-1 (LOW, doc accuracy) — recorded counts stale; verification filter not class-scoped
`findings-coder.md:10` records 48/112/3; the actual `--filter` run is 52/124/3.
The substring filter counts cross-class tests fed by data providers, so the
recorded number drifts every time a `.md`/test file mentioning the string is
added. The stable, meaningful number is the class-scoped `phpunit
tests/ConfigLoaderTest.php` = 40/96/3. Both umask runs remain identical, so the
change claim holds. Status OPEN → FIXED below.

## Confirmations
- Both umask runs identical: 52/124/3 (`--filter`) and 40/96/3 (class-scoped).
- PHPStan level 8 clean; php-cs-fixer dry-run clean; `check-changelog.php` OK.
- No test relies on the prior 0755/0777 effective fixture modes (verified by
  grep): every permission branch `chmod`s explicitly before asserting; the
  world-writable branches `chmod(0777)` at lines 330/856/943 and were already
  umask-independent (criterion 2); `tearDown()`'s recursive unlink/rmdir works
  on 0700; `config/packages` is created for hermeticity only.
- umask save / try / finally-restore is exception-safe and correct (effective
  mode `0777 & ~0077 = 0700`, deterministic).
- CHANGELOG entry unique, under `### Tests` within `## [Unreleased]`, passes
  the structural check.

## KB decision check (read-only)
DEC-006/016/017/009, FAQ-005/036 — no violation.

## Conclusion
Code change correct and ready. The only open items are documentation-accuracy
counts (F-1 recurrence + N-1), low severity, non-blocking, resolved by
recording the stable class-scoped count and the reason for the filter drift.
