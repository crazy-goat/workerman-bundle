# Round 1 — review

- **Agent:** `review`
- **Issue:** #662
- **Branch:** `fix/issue-662-inotifymonitorwatcher-mask-omits-in-move`

> Recorded originally as a PR comment ([comment 5250254408](https://github.com/crazy-goat/workerman-bundle/pull/689#issuecomment-5250254408)),
> moved here verbatim — see `code-decision-1.md`.


I have all the information I need. Let me compile the review.

## Review Report

### 1. Ledger Walk

The findings ledger (`docs/proof_of_work/current/findings.md`) is **empty** — it contains only the header row. No open findings to walk.

### 2. Knowledge Base Compliance

Relevant tags from the index: `inotify` (FAQ-006), `tests` (FAQ-006/010/011/014), `docker` (FAQ-005).

- **FAQ-006** (testing inotify failure without exhausting watch limits): The new tests use real `rename()` operations with `@requires extension inotify`, consistent with the documented pattern. No violation.
- No decisions (`DEC-*`) reference inotify, file monitoring, or the reboot subsystem. No violations found.

### 3. Verdict

The change is **clean and correct**. It adds `IN_MOVED_FROM` to the watch mask, handles `IN_MOVED_FROM|IN_ISDIR` by forgetting the moved subtree's bookkeeping, and lets non-directory `IN_MOVED_FROM` events fall through to the pattern-matching reload path. The two new tests exercise both acceptance criteria (directory drop + re-watch, and file-moved-out reload trigger).

### 4. Findings

No findings. The change is clean.

Key areas checked:
- **Prefix-safety**: `forgetWatchedTree` uses `str_starts_with($watchedPath, $path . '/')` — correctly avoids matching sibling directories with a common name prefix (e.g., `/tmp/foo/sub` vs `/tmp/foo/subbar`).
- **IN_IGNORED interplay**: Correct — when a directory is moved (not deleted), the kernel watch follows the inode and no `IN_IGNORED` fires. `forgetWatchedTree` is the only cleanup path for this case. When a directory is deleted normally, `IN_IGNORED` still fires and the existing handler cleans up. No double-cleanup risk.
- **Move-back re-watch**: `forgetWatchedTree` removes entries from `watchedPaths`, so when `IN_MOVED_TO|IN_ISDIR` fires, `watchDirTree` → `watchDir` finds `watchedPaths[$path]` unset and re-establishes the watch. The surviving kernel wd is re-mapped to the new path correctly.
- **Intra-tree rename (MOVED_FROM before MOVED_TO)**: Events are ordered. MOVED_FORGET forgets the old path; MOVED_TO re-watches the new path. The existing `testMovingWatchedDirectoryKeepsMapsConsistent` test covers this and remains valid.
- **`forgetWatchedTree` coverage**: Cleans `pathByWd`, `watchedPaths`, and `loggedWatchFailures` — all three bookkeeping structures. Consistent with the `IN_IGNORED` handler which also cleans all three.
- **Iteration safety**: `foreach ($this->pathByWd as ...)` with `unset` inside is safe in PHP (foreach iterates over a copy).
- **Type correctness**: `isFlagSet($event['mask'], IN_MOVED_FROM | IN_ISDIR)` correctly requires both flags. Non-directory `IN_MOVED_FROM` (no `IN_ISDIR`) falls through to `checkPattern`.
- **Test quality**: Both new tests use real filesystem operations with `@requires extension inotify`, consistent with existing test conventions. `testMovedOutDirectoryIsDroppedAndRewatchedOnReturn` verifies map cleanup + re-watch with `assertMapsConsistent`. `testMovedOutMatchingFileTriggersReload` verifies the reload trigger for file moves. Both clean up outside temp dirs in `finally` blocks.
- **CHANGELOG**: Accurate — describes the mask change, the inode-follows-move behavior, the stale-entry cleanup, the re-watch-on-return, and the file-moved-out reload. Issue #662 is correctly referenced.

### 5. Candidate Knowledge Base Entries

**Candidate: "IN_MOVED_FROM handling: kernel watches follow moved inodes, so IN_IGNORED never fires"**
- Tags: `inotify`, `tests`
- Trigger: "changing InotifyMonitorWatcher event handling or watch mask"
- Paragraph: When a watched directory is moved (renamed or relocated), the Linux inotify kernel watch follows the inode to the new location — no `IN_IGNORED` event fires for the old path. Without `IN_MOVED_FROM` in the watch mask, stale entries linger in `pathByWd`/`watchedPaths` until the external directory is deleted. The fix (#662) adds `IN_MOVED_FROM` to the mask and handles `IN_MOVED_FROM|IN_ISDIR` by calling `forgetWatchedTree()` which removes the moved directory and all its watched children (prefix-safe via `str_starts_with($path, $path . '/')`). Non-directory `IN_MOVED_FROM` (file moved out) falls through to `checkPattern()` and triggers a reload like a deletion. When the directory is moved back, `IN_MOVED_TO|IN_ISDIR` triggers `watchDirTree()` which re-establishes watches — `watchDir()` finds `watchedPaths[$path]` unset (cleaned by forget) and calls `inotify_add_watch()` which returns the surviving kernel wd.

### 6. Gaps / Areas Checked Clean

- No test for intra-tree rename *with children* under the new code path (existing `testMovingWatchedDirectoryKeepsMapsConsistent` tests a childless directory). The logic is correct by inspection, but an automated test would strengthen confidence. **Low value — not a finding.**
- No test for the `loggedWatchFailures` cleanup in `forgetWatchedTree`. The logic mirrors the `IN_IGNORED` handler which is tested. **Not a finding.**
- All other areas checked clean — no correctness, validation, type, or documentation issues.
