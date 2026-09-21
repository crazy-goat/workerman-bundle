# Code decision — issue #680, round 1

## Approach

Gave `serve_files` and `root_dir` (in
`src/DependencyInjection/ConfigurationTreeBuilder.php`, inside
`configureServersNode()`) their own `info()` strings, both distinct and type
accurate, and both mentioning the 0.9.3 deprecation and the
`StaticFilesMiddleware` replacement:

- `serve_files` (boolean): *"Deprecated boolean switch for the legacy static
  file serving path configured via root_dir. Configure a StaticFilesMiddleware
  service instead."*
- `root_dir` (scalar path): *"Deprecated path to the public directory served
  by the legacy serve_files static file serving path. Configure a
  StaticFilesMiddleware service instead."*

The wording matches the sibling `static_files` node (line ~150), which already
says "deprecated ... static file serving path" and points at
`StaticFilesMiddleware`. Both texts use `Configure a StaticFilesMiddleware
service instead` so they echo `setDeprecated()` while staying shorter than the
full deprecation message (info is a one-line summary; the detailed message is
already carried by `setDeprecated()`).

No `setDeprecated()`, default, type, or other node was touched. Long lines are
already the norm in this file (several `info()` calls exceed 200 chars), so the
new lines need no wrapping.

## Rejected alternatives

- **Reuse one shared string for both nodes.** That is the bug: `root_dir` is a
  scalar path, so a boolean phrasing is wrong, and distinct types deserve
  distinct descriptions.
- **Verbatim-copy the full `setDeprecated()` message into `info()`.** The
  message is ~250 chars and duplicates upgrade mechanics better placed at the
  deprecation point; `config:dump-reference` already renders the deprecation
  separately. The `static_files` precedent keeps `info()` short and
  deprecation-aware.
- **Remove the `info()` calls entirely** (rely on the deprecation notice).
  Loses the type description and departs from every other node in the tree.
- **Add a test pinning the new strings.** No existing test asserts `info()`
  text, and the issue explicitly says not to invent one unless needed. `info()`
  is documentation, not behavior; a string-pinning test would be brittle.

## Uncertainties

- The exact phrasing is a judgement call; the issue's suggested text
  ("Path to the public directory for static file serving (deprecated — configure
  a StaticFilesMiddleware service instead)") would also satisfy it. I kept the
  sentence structure of the neighbouring `static_files` info for consistency
  rather than the parenthesised style.
- I could not find a `config:dump-reference` snapshot test in the repo, so
  nothing pins the old or new text. Verified by grepping `tests/` for
  `Should current worker` and `dump-reference` — no matches.
