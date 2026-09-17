# Code decision — issue #714, round 1

## Outcome: blocked; no implementation committed or pushed

The requested endpoint was a verified lock-file fix, then stop without a PR or merge. The request explicitly says to report a blocker if broader changes are needed or checks fail. A lock-only change cannot preserve the current Symfony CI matrix, so no production/configuration change was made. `.gitignore`, `.gitattributes`, `composer.json`, the original ignored `composer.lock`, and `vendor/` were left unchanged. No changelog entry claims an unimplemented fix.

## Evidence and approach

- Read issue #714 via `gh issue view`, then the helpers tag indexes and relevant CI, PHP 8.2, lint, test-daemon and changelog guidance (no KB edits).
- The original ignored lock has 78 packages and valid content hash: `composer validate --strict` exits 0. Unlike the local installed Symfony 8.1 dependencies, this lock contains Symfony 7.4. Its Symfony package metadata permits PHP 8.2. A copied-project dry-run with `config.platform.php=8.2.0` also exits 0. This is dependency-metadata verification, **not** an actual PHP 8.2 execution test; only PHP 8.5.10 was used.
- CI's lint and benchmark jobs install without rewriting constraints. Its regular and scheduled tests jobs instead rewrite Symfony requirements to their matrix version, but still run `composer install`. With a lock, install does not resolve those changed requirements. The exact equivalent Symfony 6.4 rewrite in a temporary copied project produces install exit 4 and lists eight incompatible locked Symfony 7.4 packages. A single lock cannot satisfy both Symfony 6.4 and 8.0 matrix requirements.
- Saved a byte-identical backup of the original lock under `/private/var/folders/8p/4pn2b47136gf1q589b3qp8m40000gn/T/opencode/issue-714.cIms2u/composer.lock`. SHA-256: `1b6bc7e971988fc2540fa1396e872e896bc96f197321a1f2cf84102f8cdf4db0`. No regeneration was performed. Temporary probe manifests and logs are in that directory.

## Rejected approaches

- Merely remove the ignore rule and add the existing lock: demonstrably breaks matrix installs.
- Regenerate a different single lock without changing CI: cannot solve disjoint matrix constraints.
- Change all jobs to unrestricted `composer update`: would restore lint dependency drift, defeating the issue.
- Ignore platform requirements, narrow the matrix, or lower lint/security gates: not acceptable.
- Change `.gitattributes`: it does not exclude the lock and needs no change.

## Next decision needed

Authorize the necessary workflow change alongside lock tracking: keep lint/benchmark on deterministic `composer install`, but resolve the matrix's rewritten Symfony requirements before testing (evaluate a Symfony-targeted update with required transitive dependencies versus a full matrix-only update). Pin this distinction in workflow regression tests. Verify every distinct matrix constraint/platform combination, then actually install the chosen lock and run canonical gates against its tools. Neither matrix update strategy has been implemented or verified here.

See `findings-coder.md` for exact verification results, largest obstacle, and adjacent findings.
