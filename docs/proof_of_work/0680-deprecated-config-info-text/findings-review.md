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

## Round 2

Adjudication of round-1 findings against the current tree, plus new findings.

- `CHANGELOG.md:8` ([Unreleased]) | No changelog entry for #680 (round-1 F1). | low | **fixed** — `CHANGELOG.md:12-18` adds a `### Changed` entry under `[Unreleased]` referencing #680 and describing the distinct/deprecation-aware texts and the regression test. `php bin/check-changelog.php` → OK; DEC-021 ([Unreleased] only, no released-entry edits) respected.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:140` | `root_dir` info read as if `serve_files` were the path (round-1 F2). | nit | **fixed** — current `:140` reads "Deprecated path to the public directory served when the legacy serve_files switch is enabled…"; `serve_files` is now explicitly a "switch" at `:135`. Ambiguity gone.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:135,140` vs `:150` | Style mismatch with sibling `static_files` info (round-1 F3). | nit | **still present — deliberate non-fix accepted** — texts still lead with "Deprecated" while `:150` buries it mid-sentence. Rationale in `code-decision-2.md` (leading word is what a `config:dump-reference` skimmer sees first) is coherent for a cosmetic nit; no behavioural impact. No further action.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:135,140` | Nothing links `info()` to `setDeprecated()`; deprecation wording can drift (round-1 F4). | nit | **fixed (for the #680 nodes)** — `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:306-341` asserts each of `serve_files`/`root_dir` `info()` contains "deprecat". Residual: the guard does not cover the third deprecated node `static_files` — see new F6.
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:256` | No test fails if the two texts regress to identical (round-1 F5). | nit | **fixed** — `assertNotSame` at `:335`; mutation test (re-merging both `info()` strings) fails the suite with "two strings are not identical" (verified in an out-of-tree copy).

### New findings — round 2

- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:306-341` | The new guard is narrower than the defect class it locks: it covers only `serve_files`/`root_dir`, not the third deprecated node `static_files` (`ConfigurationTreeBuilder.php:150`), whose `info()` also carries the only in-tree "deprecated" signal; and it asserts only the "deprecat" substring, not the `StaticFilesMiddleware` replacement hint that is part of #680's acceptance criteria. A rewrite could drop `static_files`' deprecation mention or the replacement hint and this test would still pass. | nit | **fixed** — the guard is now split: `testDeprecatedStaticFileNodeInfoTextsAreDistinct` asserts `serve_files` vs `root_dir` distinctness, and `testDeprecatedStaticFileNodeInfoNamesDeprecationAndReplacement` walks a `legacyStaticFileNodeInfos()` helper over all three legacy nodes (`serve_files`, `root_dir`, `static_files`), asserting each `info()` contains "deprecat" *and* "StaticFilesMiddleware".

### Coder findings (findings-coder.md) re-adjudication — round 2

- `findings-coder.md` #1 (`info()`/`setDeprecated()` drift) | now partially gated for the two #680 nodes; `static_files` still ungated | nit | mirrored as new F6, still open.
- `findings-coder.md` #2 (`serve_files`/`root_dir` independently settable) | pre-existing, out of scope for a string/test-only change | — | not a real finding against this diff (unchanged from round 1).
- `findings-coder.md` #3 (long `info()` lines) | consistent with the file; no linter rule violated | — | not a real finding (unchanged from round 1).

## Round 3

Adjudication of every open item from rounds 1–2 against the current tree
(`159f824`), then new findings.

- `CHANGELOG.md:12-18` ([Unreleased]) | No changelog entry for #680 (round-1 F1). | low | **still fixed** — `### Changed` entry under `[Unreleased]` names #680, the distinct/type-accurate texts and the regression test; `php bin/check-changelog.php` OK; DEC-021 respected. No change this round.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:140` | `root_dir` info read as if `serve_files` were the path (round-1 F2). | nit | **still fixed** — `:140` reads "Deprecated path to the public directory served when the legacy serve_files switch is enabled…" and `:135` calls `serve_files` a "boolean switch". Source is byte-identical to round 2.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:135,140` vs `:150` | Style mismatch with sibling `static_files` info (round-1 F3). | nit | **still present — accepted non-fix, not a real defect** — texts still lead with "Deprecated" while `:150` buries it mid-sentence; rationale in `code-decision-2.md`/`code-decision-3.md` is sound for a cosmetic nit, no behavioural or test impact. Not re-opened.
- `src/DependencyInjection/ConfigurationTreeBuilder.php:135,140` | Nothing links `info()` to `setDeprecated()` (round-1 F4). | nit | **fixed, and no longer partial** — `testDeprecatedStaticFileNodeInfoNamesDeprecationAndReplacement` (`tests/DependencyInjection/ConfigurationTreeBuilderTest.php:321-335`) now gates all three legacy nodes on both the deprecation and replacement keyword. The round-2 residual is closed by the same test (see F6).
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:304-313` | No test fails if `serve_files`/`root_dir` regress to identical text (round-1 F5). | nit | **still fixed** — `assertNotSame` at `:308-312`; mutation M4 (re-merge the two `info()` texts in a scratch copy) fails with "serve_files and root_dir must not share identical info() text".
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:321-365` | Round-2 F6: guard covered only `serve_files`/`root_dir` and only "deprecat", missing `static_files` and the `StaticFilesMiddleware` replacement hint. | nit | **fixed** — the guard is split and widened: `legacyStaticFileNodeInfos()` resolves `serve_files`, `root_dir`, `static_files` (fail-loud `missing config node %s` on the `?? null` branch), and the names test asserts `deprecat` (case-insensitive) plus `StaticFilesMiddleware` on each. Mutation-verified in an out-of-tree copy: M1 remove `StaticFilesMiddleware` from `serve_files` → FAIL; M2 same for `static_files` → FAIL; M3 remove "deprecated" from `static_files` → FAIL; M5 remove `StaticFilesMiddleware` from `root_dir` → FAIL.

### Coder findings (findings-coder.md) adjudication — round 3

- `findings-coder.md` #1 (`info()`/`setDeprecated()` drift) | now gated for all three legacy nodes on both keywords | nit | **fixed / closed** — no residual.
- `findings-coder.md` #2 (`serve_files`/`root_dir` independently settable) | pre-existing, out of scope for a string/test-only change | — | not a real finding against this diff (unchanged from rounds 1–2).
- `findings-coder.md` #3 (long `info()` lines) | consistent with the file; no linter rule violated | — | not a real finding (unchanged).

### New findings — round 3

None. No high/medium/low/nit finding survives review of the current diff. All
round-1 and round-2 items are `fixed` or accepted non-fixes, and
`findings-coder.md` #1 is closed.
