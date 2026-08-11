# Code Decision — Round 1

## Issue

[#693](https://github.com/crazy-goat/workerman-bundle/issues/693):
CONTRIBUTING.md PHPStan level stale (says 6, is 8) and CI Configuration
omits the pow / pow-reality jobs.

## The stale premise

The issue's second claim — that CI Configuration omits `pow` / `pow-reality`
jobs and that `tests` has `needs: [lint, pow]` — was verified against the
current tree and is **no longer true**. Those jobs were introduced with the
machine-checked proof-of-work work in #686/#687, but PR #697 (commit
`c3facfc`, "process: replace the machine-checked proof of work with four
Markdown files") replaced the machine-checked POW with four plain Markdown
files and **removed** the `pow` / `pow-reality` CI jobs.

Current `.github/workflows/tests.yaml` defines exactly four jobs: `lint`,
`tests`, `benchmark` and the `ci` aggregator (`needs: [lint, tests,
benchmark]`, `if: always()`, benchmark advisory). `tests` has `needs: lint`
only. `docs/workflow.md` (lines ~423-432) already documents the four jobs and
no pow jobs.

So the CI Configuration section's job list was already accurate. Adding pow /
pow-reality documentation would have been wrong and would have re-introduced
the very drift the issue complains about.

## What I did

1. **PHPStan level** (`CONTRIBUTING.md` Code Standards section): the claim
   "Static analysis with PHPStan level 6" was genuinely stale —
   `phpstan.neon.dist` runs `level: 8`. Changed it to `level 8`.
2. **CI Configuration section**: the three existing bullets (Lint, Tests,
   Benchmark) were accurate. I added a fourth bullet for the `ci` aggregator
   job, which the section did omit, and which `docs/workflow.md` already
   documents. This keeps the doc truthful without inventing vanished jobs.

## What I rejected

- Adding pow / pow-reality job documentation per the literal issue text —
  those jobs no longer exist (#697).
- Pinning every CI sentence to the workflow YAML in a test — overkill for a
  prose section that is already accurate; the pin test budget is better spent
  on the level figure that actually went stale.
- Not touching the aggregator at all — leaving the section omitting the job
  that actually gates merges is a real (if small) gap.

## What I was unsure about

Where to pin the level. The issue suggested a test like
`tests/BinDirectoryTest.php`. That file already asserts documentation claims
(`testContributingLinksToBinReadme`), so I placed a small pin test there that
parses the level out of `phpstan.neon.dist` and asserts CONTRIBUTING.md states
the same number. This is the "cannot go stale twice" guard the issue asked
for, without the machinery the pre-#697 build used.
