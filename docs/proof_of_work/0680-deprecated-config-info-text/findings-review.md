# Findings — review record (#680)

Format: `file:line | what is wrong | severity | what happened to it`.
This file is append-only across rounds; nothing is ever deleted.

## Round 1

- `CHANGELOG.md:8` ([Unreleased]) | No changelog entry for #680. `docs/workflow.md:362-370` step 8 requires an `[Unreleased]` entry with the issue reference, and every recent implementation commit (`c932d77`, `b67ef8f`, `dde5076`, `669b131`, `bcaca12`) adds one — including the #594 docblock-only change (`CHANGELOG.md:42`). `check-changelog.php` checks structure, not presence, so nothing automated catches it. | low | **fixed** — `### Changed` entry under `[Unreleased]` referencing #680 added.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:140` | `root_dir` info "…served by the legacy serve_files static file serving path" reads as if `serve_files` were the path; `serve_files` is the boolean switch. Clearer: "…enabled by serve_files". | nit | **fixed** — `root_dir` info now reads "…served when the legacy serve_files switch is enabled"; `serve_files` reads "…enabling the legacy static file serving path configured by root_dir".
- `src/DependencyInjection/ConfigurationTreeBuilder.php:135,140` vs `:150` | Style mismatch with the sibling `static_files` info: sibling uses "the deprecated serve_files/root_dir static file serving path"; the new texts lead with "Deprecated …" and name only one sibling each. Cosmetic. | nit | **deliberately not fixed** — leading "Deprecated" is intentional: `config:dump-reference` renders `info()` as a description and the first words are what a reader skimming the dump sees; matching `static_files`' mid-sentence "the deprecated … path" would bury the deprecation. The two legacy nodes naming each other (rather than both siblings) is also deliberate — each points at its actual companion.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:135,140` | Nothing links `info()` to `setDeprecated()`, so deprecation wording can drift silently (also raised by the coder as findings-coder.md #1). Acceptable for a docs-string change; the proposed guard is a reflective test asserting each legacy node's `info()` contains "deprecat". | nit | **fixed** — `testDeprecatedStaticFileNodeInfoTextsAreDistinctAndMentionDeprecation` asserts both legacy nodes' `info()` contain "deprecat" (ignoring case).
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:256` | No test fails if `serve_files` and `root_dir` regress to identical `info()` text — the exact defect of #680. A one-line `assertNotSame` would lock it. Acceptable to leave to the same follow-up as the drift guard. | nit | **fixed** — the same test asserts `assertNotSame` between the two `info()` texts, so re-merging them fails the suite.

## Coder findings (findings-coder.md) adjudication

- `findings-coder.md` #1 (`info()`/`setDeprecated()` drift) | still present by design; not a defect of this diff | nit | mirrored above (F4)
- `findings-coder.md` #2 (`serve_files`/`root_dir` independently settable) | pre-existing, out of scope for a string-only change | — | not a real finding against this diff
- `findings-coder.md` #3 (long `info()` lines) | consistent with the file; no linter rule violated | — | not a real finding
