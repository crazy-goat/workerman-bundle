# Findings — coder — #656

## Obstacles

- UPGRADE.md had no sections for 0.18–0.24; had to mine CHANGELOG.md (6 versions, ~400 lines) to decide what is migration-relevant vs release-note noise. Used BC/security/changed-performance as filter.

## Surprises

- 0.23.0 section in CHANGELOG mixes security hardening (config cache) with code-quality churn — easy to miss the deprecation of withHeader() buried among lint fixes. Backfilled both.

## Bugs / weak spots outside scope

- `docs/proof_of_work/README.md` lists expected proof-of-work kinds but does not pin the heading level for UPGRADE.md — minor, no fix proposed.
- No existing test pins UPGRADE.md section ordering (e.g. descending semver). A future `UpradeGuideTest` could assert `## Upgrading to 0.*` headings are strictly descending, analogous to `ChangelogStructureTest`. Suggested fix: add `tests/UpgradeGuideTest.php` that parses UPGRADE.md headings and asserts descending order and no gaps between 0.18 and 0.25.
