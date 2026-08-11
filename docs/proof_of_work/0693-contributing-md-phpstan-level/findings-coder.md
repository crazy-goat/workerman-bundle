# Findings — Coder

## Stale issue premise: pow / pow-reality CI jobs no longer exist

- **Issue #693** claims CONTRIBUTING.md's CI Configuration section omits
  `pow` / `pow-reality` jobs and that `tests` has `needs: [lint, pow]`.
- **Verified against current tree:** those jobs were removed by PR #697
  (commit `c3facfc`), which replaced the machine-checked proof of work with
  four Markdown files. `.github/workflows/tests.yaml` now defines `lint`,
  `tests`, `benchmark` and `ci`.
- **Action:** did not add pow/pow-reality docs (would be wrong). Fixed only
  the genuinely stale PHPStan level claim and added the missing `ci`
  aggregator bullet. A follow-up GitHub issue could note that #693's CI half
  is already resolved by the time anyone picks it up.

## Other observations

- `docs/workflow.md` and CONTRIBUTING.md's "Required Status Checks" section
  both cite the `ci` aggregator, but CONTRIBUTING.md's "CI Configuration"
  section did not list it. Now aligned.
- The coverage floor figure "80%" appears in CONTRIBUTING.md twice (Required
  Status Checks and CI Configuration). It is not pinned by a test; if the
  floor is ever changed in `composer.json`, the docs can drift again. Low
  severity; would need a `composer.json` + doc pin test to fully close.
