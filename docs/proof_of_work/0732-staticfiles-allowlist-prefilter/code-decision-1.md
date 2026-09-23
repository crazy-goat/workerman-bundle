## Round 1 — #732

Added an early `isAllowlistExtensionMismatch()` guard at the middleware entry point. It only rejects when an allowlist is active and the final basename has a recognizable ordinary extension outside that allowlist. It deliberately declines to decide on extensionless paths, dotfiles, trailing-slash directory requests, backslashes, and leak/residue suffix extensions; those continue through the existing resolver and `isFilePathBlocked()` checks. A clear mismatch returns 404 directly, avoiding the resolver's filesystem probes without passing the request to `$next`.

Rejected a generic extension filter in `isFilePathBlocked()` because that method is called after `realpath()` and thus cannot save the filesystem work. Also rejected treating every unfamiliar extension as a prefilter reject where its classification overlaps existing leak/residue policy; those remain handled by the established security checks.

The focused suite initially exposed that returning `false` from `getPublicPathFile()` would dispatch the request to `$next` (not the middleware's 404 path). The guard was moved to `__invoke()` so the response semantics remain unchanged and explicit.
