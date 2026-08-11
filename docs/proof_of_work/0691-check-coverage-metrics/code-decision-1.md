# Issue #691 — check-coverage.php metrics aggregation — code decision

## Approach taken

Replaced the `foreach` that summed every `//metrics` node with selection of a
single aggregate `<metrics>` node:

1. Try `/coverage/project/metrics` first (the project-level aggregate that
   Clover always emits last and that carries the true, deduplicated totals).
2. Fall back to `//file/metrics` for minimal Clover output that has no
   `<project>` layer.

Then read `statements` / `coveredstatements` straight off that one node and
compute the percentage. Everything else (usage/parse error messages, exit
codes 0/1/2, `Threshold: %.2f%%` line, `OK`/`FAILED` suffix) is untouched, so
the output format is byte-for-byte identical to what CI already parses.

### Why this selection order

- `/coverage/project/metrics` is exactly the aggregate the issue asks for. PHPUnit's
  standard Clover dump always includes a `<metrics>` under `<project>` carrying the
  project totals.
- The `//file/metrics` fallback is defensive: some minimal generators (or the
  `--clover` of older tooling) may omit the `<project>` node entirely, in which
  case a lone file-level metrics is the correct aggregate. If both are absent
  we keep the existing "No … found" behaviour and exit 2.

## What I rejected

- **Keeping the global `//metrics` sum.** The whole point of the issue: with top-level
  functions or a `<package>` layer the class+file+project nodes do not coincide, so the
  "3× cancels out" ratio breaks silently. Rejected outright.
- **`//metrics[@type]` or other attribute-based selection.** PHPUnit's Clover emits
  different subsets of attributes (method nodes lack `statements`), and relying on any
  particular attribute to identify "the" node is fragile. Positional/typing heuristics
  add complexity without benefit over the simple explicit XPath.
- **`xpath('/coverage/project/*/metrics')` etc.** Unneeded: the exact path is stable for
  real PHPUnit output and the fallback covers the degenerate case.

## Uncertainty

- PHPUnit doesn't document a hard guarantee of a project-level `<metrics>` in every
  generator, hence the `//file/metrics` fallback. If a future Clover variant nests
  files under multiple packages, `//file/metrics` picks the *first* file node — acceptable
  degradation that still avoids the broken summed ratio, and the project path is used for
  well-formed files anyway.
- The fixture's `<package>` wrapper is decorative (PHPUnit's own Clover doesn't emit
  `<package>`); it exists only to prove the old bug via divergent counts. The parsing
  logic itself keys on the `<project>` aggregate regardless of packages — which is the point.

## Revision after review round 1

Review (R1-1, low) flagged that the original fallback (`//file/metrics` + `[0]`)
reported only the **first** file's metrics for multi-file Clover without a
`<project>` layer — a silent regression vs the old code, which summed all file
metrics. Revised the fallback to **sum all `/file/metrics` nodes** when the
project aggregate is absent, restoring correct totals for that shape. Added a
second fixture (`clover-files-only.xml`, no project-level metrics) plus two
tests pinning the fallback (converges on 75.00%, and exit 2 when no `<metrics>`
exists anywhere). The `<project>` path is now fully self-contained in the `if`
branch; the fallback is the `else` branch.
