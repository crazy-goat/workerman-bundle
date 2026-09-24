# Review round 1 — #744

## Prior findings

No prior unresolved findings; initial review round.

## Review

- **Budget met, honestly.** `php bin/kb-lint.php` reports `faq.md` 275/300 and `decisions.md` 286/300, 0 warnings, 0 stale. `LINE_BUDGET` was not changed.
- **Promotions have real gates.** Each newly `promoted` entry's `gate=` was confirmed against the tree: `tests/StaticFilesMiddlewareTest.php` (dotted-directory/allowlist cases), `tests/RequestConverterTest.php::testRawHeadMayHaveDuplicates*`, the mask-equality + exhaustive boundary tests, and the `phpstan.neon.dist` / `.php-cs-fixer.dist.php` Finder includes for `bin/`.
- **No rule lost.** Trims removed narrative and restatement only; every entry keeps its `trigger=`, the actionable rule, and any command. The `pkill` command in FAQ-007 and the recovery steps in FAQ-016 survive.
- **Front matter intact.** `kb-lint` validates ids, required keys, index sync and near-duplicates — all clean; the tag index did not change because no id/tag changed.
- **No code changed.** Diff is `docs/helpers/*.md`, `CHANGELOG.md` and the proof-of-work files; docs-only CI will run lint.

## Checks

- `php bin/kb-lint.php` — OK, 60 entries, 0 warning(s), 0 stale.
- `composer lint` — passed (kb-lint clean, check-changelog OK, PHPStan/Rector clean).
- `tests/KnowledgeBase` — passed (43 tests, 1197 assertions).
- `git diff --check` — clean.

## Findings

None. No further knowledge-base candidate: this change is itself KB maintenance.
