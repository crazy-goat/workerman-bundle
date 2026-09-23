## #732 implementation findings

- Biggest implementation obstacle: the first attempt placed the prefilter inside path resolution and returned `false`, which means “not handled” and dispatches to `$next`; this violated existing allowlist behavior. Moving the explicit mismatch guard to `__invoke()` makes a clear mismatch return 404 before any filesystem resolution.
- FAQ-004 and DEC-013 are directly relevant: rules for extensions belong to the final basename, while paths whose intent is ambiguous must stay on the established path. Added tests pin allowlisted, non-allowlisted, dotfile, extensionless, trailing-slash and dotted-directory cases; the middleware suite passed (124 tests).
- No additional out-of-scope bugs identified during this issue.
