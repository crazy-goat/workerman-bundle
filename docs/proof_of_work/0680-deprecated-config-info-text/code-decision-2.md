# Code decision — #680, round 2 (review findings)

Round 1 shipped two distinct, deprecation-aware `info()` texts. Review round 1
raised five findings; this round resolves them.

## What changed

- **F1 (low) — missing CHANGELOG entry.** Added a `### Changed` entry under
  `[Unreleased]` referencing #680. `check-changelog.php` checks structure, not
  presence, so only a human/convention catches this.
- **F2 (nit) — misleading `root_dir` wording.** `root_dir` said "served by the
  legacy serve_files static file serving path", which reads as if `serve_files`
  were the path. Both texts reworded:
  - `serve_files`: "Deprecated boolean switch enabling the legacy static file
    serving path configured by root_dir. Configure a StaticFilesMiddleware
    service instead."
  - `root_dir`: "Deprecated path to the public directory served when the legacy
    serve_files switch is enabled. Configure a StaticFilesMiddleware service
    instead."
- **F4 + F5 (nits) — no gate on the defect class.** Added
  `testDeprecatedStaticFileNodeInfoTextsAreDistinctAndMentionDeprecation`: it
  walks the built tree to the `servers` prototype's `serve_files` / `root_dir`
  children and asserts (a) the two `info()` strings differ and (b) each contains
  "deprecat". This locks the exact #680 defect (two nodes sharing one text) and
  couples `info()` to the deprecation signal so a future rewrite cannot drop it
  silently. `info()` and `setDeprecated()` are independent Symfony attributes,
  so a reflective test is the cheapest real gate; per the workflow, the check is
  preferred over a knowledge-base entry.

## Deliberately not changed

- **F3 (nit) — style mismatch with the sibling `static_files` node.** The new
  texts lead with "Deprecated"; the sibling buries it mid-sentence. Kept as is:
  `config:dump-reference` renders `info()` as the description, and leading the
  line with the deprecation is the point — a reader skimming the dump sees it
  first. Consistency with the sibling's phrasing would trade that away.

## Alternatives rejected

1. **Fix F1 by adding a changelog lint rule for entry presence.** Out of scope
   for #680 and the changelog check is intentionally structural only; a
   presence check needs a mapping from changed files to issue numbers that does
   not exist. Recorded as a possible follow-up instead.
2. **Add only `assertNotSame` (F5) and skip the "deprecat" assertion (F4).**
   Rejected: the two findings are the same gate. One test covering distinctness
   *and* the deprecation keyword costs nothing extra and closes the drift class
   the coder flagged too.
3. **Refactor the `info()`/`setDeprecated()` pairing into a shared helper.**
   Overkill for three legacy nodes and would obscure the `setDeprecated()`
   messages, which are deliberately longer than the info lines.

## Uncertainties

- The new test narrows nodes via `ArrayNode` / `PrototypedArrayNode` /
  `BaseNode` because `NodeInterface` does not declare `getInfo()`. If Symfony
  ever changes the tree builder's node classes the test would need updating —
  acceptable for a test that intentionally reaches into the built tree.
