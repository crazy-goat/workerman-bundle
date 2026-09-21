# Code decision — #680, round 3 (review F6)

Round 2 review raised one new nit (F6): the regression guard covered only
`serve_files`/`root_dir`, not the third deprecated node `static_files`, and
asserted only "deprecat" — not the `StaticFilesMiddleware` replacement hint
that #680's acceptance criteria mention.

## What changed

Split the single guard test into two and widened the coverage:

- `testDeprecatedStaticFileNodeInfoTextsAreDistinct` — keeps the `assertNotSame`
  on `serve_files` vs `root_dir` (the literal #680 defect).
- `testDeprecatedStaticFileNodeInfoNamesDeprecationAndReplacement` — walks a
  new `legacyStaticFileNodeInfos()` helper over all three legacy nodes
  (`serve_files`, `root_dir`, `static_files`) and asserts each `info()` contains
  "deprecat" (case-insensitive) and "StaticFilesMiddleware".
- `legacyStaticFileNodeInfos()` centralises the tree navigation
  (`servers` prototype → children, narrowing `NodeInterface` → `BaseNode` for
  `getInfo()`) so both tests share it.

The `StaticFilesMiddleware` assertion is a substring, not exact prose, so a
future reword of the replacement sentence still passes.

## Alternatives rejected

1. **A `@dataProvider` over the three node names.** Would duplicate the tree
   navigation once per case; a shared private helper is cheaper and keeps the
   distinctness test (which only makes sense for the pair) separate.
2. **Asserting the full sentinel text of each `info()`.** Rejected — it would
   turn a wording change into a test failure and pin prose the issue does not
   require; substring checks on the two required signals are the right
   granularity.
3. **Leaving F6 open as "acceptable".** Rejected: it is part of the issue's
   acceptance criteria, costs ten lines, and is exactly the "write the check"
   the workflow asks for over a knowledge-base entry.

## Uncertainties

- None new. The node-navigation narrowing is the same as round 2.
