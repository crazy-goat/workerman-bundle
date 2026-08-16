# Findings — review round 1 (#558)

## FR-001 | tests/PollingMonitorWatcherTest.php, src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php | Unmerged merge-conflict markers in working tree | medium

The working tree has unresolved merge conflicts (`<<<<<<< Updated upstream` / `=======` / `>>>>>>> Stashed changes`) in two files outside this PR's scope — leftovers from `git stash pop` on an unrelated branch (`perf/polling-monitor-sharded-scan`). The committed diff (`origin/master...HEAD`) is clean and does not include these files, so the PR itself is unaffected. However, the conflict markers (12 in the test, 6 in the source) cause PHP parse errors that block `composer lint` and `composer test` locally. A reviewer or CI re-run on this checkout would see hard failures unrelated to #558. Resolution: `git checkout HEAD -- src/Reboot/FileMonitorWatcher/PollingMonitorWatcher.php tests/PollingMonitorWatcherTest.php` (or `git stash drop`). Not a code defect in the PR; flagged because it blocks local verification.

## FR-002 | docs/proof_of_work/0558-staticfiles-realpath-eviction/code-decision-1.md:12, findings-coder.md:7 | Stale source line numbers in proof-of-work docs | low

Both proof-of-work files cite `src/Middleware/StaticFilesMiddleware.php:215-225` for `cacheStore()` and `:229-236` for `getRealPathCache()`. The actual lines in the current source are `:289-307` and `:324-330` respectively. The numbers appear to reference an earlier revision (pre-#607 merge or a different branch state). The claims about the code are correct — only the line references are wrong. No automated check catches this; a doc-lint rule that verifies `file:line` references against the cited file would.

## FR-003 | docs/proof_of_work/0558-staticfiles-realpath-eviction/code-decision-1.md:44 | Wrong test root-directory path in proof-of-work docs | low

`code-decision-1.md` states the fixed test root is `tests/App/data/public`. The actual `setUp()` uses `__DIR__ . '/data/public'` (`tests/StaticFilesMiddlewareTest.php:18`), which resolves to `tests/data/public` — there is no `App/` segment. Minor factual inaccuracy in the design rationale paragraph; does not affect code correctness.

## FR-004 | CHANGELOG.md:57 | "flat" overstates 100k-entry behavior | nit

The CHANGELOG says the eviction is "flat where `array_shift()` grows linearly with cache size (49.4 → 2.6 µs/op at 100k entries)". The 2.6 µs/op at 100k entries is ~13× the 0.205 µs/op at `CACHE_MAX_SIZE` (1024) — not flat in absolute terms. The growth is dominated by the hash-table insert (amortized O(1) with resize spikes), not the eviction itself (`unset` + `array_key_first` are truly O(1)). The `findings-coder.md` is more precise: "The growth is dominated by the hash-table insert of the new entry (possible bucket resize), not by the eviction." The production cache is capped at 1024, so the 100k figure is a theoretical comparison only. The key claim (O(1) eviction vs O(n) `array_shift`) is correct; the phrasing could be clearer. No action required for approval.

## FR-005 | tests/StaticFilesMiddlewareTest.php:413-466 | LRU test does not reset the process-wide cache after itself | low

The new `testEvictionRemovesLeastRecentlyUsedEntry()` fills the process-wide static `getRealPathCache()` with 1024 entries (all `/lru-pad-*.css` and `/lru-evictor.css`) and leaves them there. No existing test asserts a small absolute cache count (the boundedness test uses `assertLessThanOrEqual` and relative comparisons; the TTL test checks its own entry's presence/timestamp), so no current test breaks. The coder acknowledged this in `findings-coder.md`. A future test that asserts `assertSame(1, count($cache))` would be flaky if run after this test. The `getRealPathCache()` static has no public reset method (unlike the `HeaderNameNormalizer::resetCache()` pattern in DEC-014). Latent risk, not a current defect.

---

## Round 1 → 2 outcomes

- **FR-001 — fixed (working tree).** The conflict markers came from the
  review agent's own `git stash` / `git stash pop` sequence during round 1
  (see `code-decision-2.md`): the no-op `git stash` created nothing, and the
  pop applied the *pre-existing* stash@`{0}` (WIP on
  `perf/polling-monitor-sharded-scan`, an unrelated #559 branch) into the
  tree. Main session reverted the two files to HEAD and verified the stash
  entry is preserved — the #559 WIP is untouched, nothing entered this PR.
  `git status` is clean; `composer lint`/`composer test` unblocked.
- **FR-002 — fixed.** `code-decision-1.md` and `findings-coder.md` now cite
  the current lines (`StaticFilesMiddleware.php:293-307` for `cacheStore()`)
  and the extracted `StaticFilesRealPathCache` location.
- **FR-003 — fixed.** `tests/App/data/public` → `tests/data/public` in
  `code-decision-1.md`.
- **FR-004 — fixed.** CHANGELOG wording now distinguishes "eviction stays
  O(1)" from the hash-table-insert growth at 100k entries.
- **FR-005 — fixed.** Storage extracted into `@internal`
  `src/Middleware/StaticFilesRealPathCache` per DEC-014 (final readonly class
  cannot own a static): `::all()` by-reference view, `::cache()` /
  `::resetCache()` test affordances; `setUp()` resets the cache; the
  `getRealPathCache()` reflection sites migrated to the affordance. Behavior
  is unchanged — keys, cap, TTL, eviction identical (full middleware suite
  green; see `code-decision-2.md`).
