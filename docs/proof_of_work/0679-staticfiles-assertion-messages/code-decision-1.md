# Code Decision — Round 1

## Issue

#679 — assertion message strings in `tests/StaticFilesMiddlewareTest.php`
still use snake_case `follow_symlinks` after the #591 docs fix (PR #678)
standardized on the real constructor argument name `$followSymlinks`.

## Approach

Renamed the four assertion message strings from `follow_symlinks` to
`followSymlinks` to match the actual constructor parameter name. The
messages are failure-output only (cosmetic), but they kept the confusing
snake_case name alive where a developer would see it on a failing test.

## What was rejected

- Rewording to "follow symlinks" (space-separated prose): rejected because
  `$followSymlinks` is the exact identifier used in the constructor and in
  the documentation; matching it verbatim is least surprising.
- Touching `CHANGELOG.md:766`: rejected — that is a released 0.22.0 entry
  and released changelog entries are immutable history (per the issue note).

## Uncertainties

None. The change is mechanical: four string literals, no logic touched.
