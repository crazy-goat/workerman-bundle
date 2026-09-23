# Review round 1 — #732

## Prior findings

`findings-review.md` contained the review scope/instruction for this initial round, not prior unresolved defects. The coder's recorded early draft issue (returning false to `$next`) is fixed: `__invoke()` now returns 404 directly for a clear allowlist mismatch.

## Security and behavior review

- `isAllowlistExtensionMismatch()` only runs when configured allowlist is non-empty.
- It examines only `basename($path)`, respecting FAQ-004's final-component requirement. Dotted directory names do not determine the extension decision.
- Empty paths, trailing-slash paths, backslash/NUL-containing paths, `%00` paths, dotfiles, extensionless basenames, and leak/residue extensions are not prefiltered; they continue through the original resolver and component checks. This avoids weakening the existing input rejection, dotfile, and blocked-file behavior.
- For a clear ordinary-extension mismatch, the middleware returns 404 before `resolveRealPath()`, so it avoids `is_link()`/`realpath()` filesystem probes while preserving the prior blocked response and not delegating to `$next`.
- Allowlisted and otherwise ambiguous inputs are unchanged; no fast path serves any file or skips the post-resolution `isFilePathBlocked()` check. This satisfies DEC-013's no-false-negative/fail-open principle.

## Checks

- `StaticFilesMiddlewareTest` — passed (124 tests, 252 assertions), including NUL and `%00` bypass cases.
- `composer lint` — passed (existing FAQ/decisions budget warnings only).
- Changelog structure check and `git diff --check` — passed.

## Findings

None. No knowledge-base candidate is needed: existing FAQ-004 and DEC-013 cover the relevant invariants and were followed.
