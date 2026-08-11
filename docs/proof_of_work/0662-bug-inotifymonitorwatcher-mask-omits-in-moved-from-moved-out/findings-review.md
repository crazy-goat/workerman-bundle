# Findings — review (#662)

## Round 1

**No findings.** The review reported the change clean and named what it
checked, which is the part worth keeping — "no findings" with no list of what
was looked at is indistinguishable from not looking.

Checked clean:

| Area | What was verified |
| --- | --- |
| prefix-safety | `forgetWatchedTree()` uses `str_starts_with($watchedPath, $path . '/')`, so forgetting `/tmp/foo/sub` does not also forget `/tmp/foo/subbar` |
| `IN_IGNORED` interplay | a moved directory's kernel watch follows the inode and fires no `IN_IGNORED`, so `forgetWatchedTree()` is the only cleanup path for that case; a deleted directory still goes through the existing `IN_IGNORED` handler. No double cleanup |
| move-back re-watch | `forgetWatchedTree()` clears `watchedPaths`, so `IN_MOVED_TO\|IN_ISDIR` → `watchDirTree()` → `watchDir()` finds the entry unset and re-establishes the watch; the surviving kernel `wd` is re-mapped to the new path |
| intra-tree rename | `MOVED_FROM` precedes `MOVED_TO`; the old path is forgotten, the new one re-watched. `testMovingWatchedDirectoryKeepsMapsConsistent` still covers this |
| bookkeeping completeness | `forgetWatchedTree()` cleans all three structures — `pathByWd`, `watchedPaths`, `loggedWatchFailures` — matching the `IN_IGNORED` handler |
| iteration safety | `foreach ($this->pathByWd as …)` with `unset()` inside is safe; `foreach` iterates a copy |
| type correctness | `isFlagSet($event['mask'], IN_MOVED_FROM \| IN_ISDIR)` requires both flags, so a non-directory `IN_MOVED_FROM` correctly falls through to `checkPattern()` |
| knowledge base | FAQ-006 (`inotify`), FAQ-005 (`docker`), FAQ-010/011/014 (`tests`) reviewed; no violations. No `DEC-*` entry covers inotify or the reboot subsystem |
| CHANGELOG | accurate and references #662 |

Gaps the review raised and explicitly did **not** count as findings:

- No test for an intra-tree rename *with children* under the new code path —
  `testMovingWatchedDirectoryKeepsMapsConsistent` covers a childless directory.
  Correct by inspection; a test would only strengthen confidence.
- No test for the `loggedWatchFailures` cleanup inside `forgetWatchedTree()`.
  The logic mirrors the `IN_IGNORED` handler, which is tested.

## Candidate knowledge-base entry

**"`IN_MOVED_FROM` handling: kernel watches follow moved inodes, so
`IN_IGNORED` never fires"** — tags `inotify`, `tests`; trigger: "changing
`InotifyMonitorWatcher` event handling or watch mask".

When a watched directory is moved, the Linux inotify watch follows the inode to
the new location and no `IN_IGNORED` fires for the old path. Without
`IN_MOVED_FROM` in the mask, stale entries linger in `pathByWd`/`watchedPaths`
until the directory is deleted. #662 adds the flag and handles
`IN_MOVED_FROM|IN_ISDIR` by forgetting the moved subtree prefix-safely; a
non-directory `IN_MOVED_FROM` falls through to `checkPattern()` and reloads like
a deletion.
