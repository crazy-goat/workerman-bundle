# Review — Round 1

Branch `docs/issue-693-contributing-md-phpstan-level-stale-says`, issue #693.

## Earlier findings

None — `findings-review.md` did not exist before this round (round 1).

## Verdict

**Approve** — one `nit` (cosmetic typo), no behavior defects.

## New findings

| ID | file:line | description | severity |
| --- | --- | --- | --- |
| N1 | `docs/proof_of_work/0693-contributing-md-phpstan-level/findings-coder.md:13` | Typo "GitHhub" in the closing paragraph | nit |

Resolution: fixed (N1) — typo corrected to "GitHub".

## Checked clean

- **CI job structure vs doc claims.** `.github/workflows/tests.yaml` defines
  exactly four jobs — `lint`, `tests`, `benchmark`, `ci` (`needs: [lint,
  tests, benchmark]`, `if: always()`, checks `lint`/`tests` only, benchmark
  advisory). The new CONTRIBUTING.md `ci` bullet matches exactly. The Tests
  bullet's matrix (PHP 8.2–8.5 × Symfony 6.4–8.0) and coverage-threshold-on-
  8.2/6.4-leg claims match the YAML. `docs/workflow.md` (lines ~421–431)
  documents the same four jobs, so the `docs/workflow.md` pointer is accurate.
  `pow`/`pow-reality` jobs genuinely absent (removed by #697); not re-adding
  them is correct.
- **Test correctness.** `testContributingPhpstanLevelMatchesConfig` matches the
  file's existing doc-assertion style. Regex `/level:\s*(\d+)/` captures the
  single `level: 8` in `phpstan.neon.dist`. `assertNotNull` +
  `assertStringContainsString` give clear failure messages. PHPStan 2.2.2 at
  level 8 reports no errors; the test passes under PHPUnit.
- **Changelog format.** Entry under `### Fixed` in `[Unreleased]`, Keep-a-
  Changelog compliant, references #697 and #693. `tests/ChangelogStructureTest.php`
  passes.
- **Gate integrity.** Purely additive (new pin test + doc + changelog). No gate
  lowered or weakened.
- **DEC-012.** No raw angle-bracket placeholders in new PoW files,
  CONTRIBUTING.md, or the changelog entry.

## Candidate knowledge-base entries

1. **"Doc figures that drift get pinned by a test against their single source
   of truth"** — tags `docs`, `tests`, `phpstan`, `policy`; trigger: editing a
   prose figure in CONTRIBUTING.md/README that mirrors a config value. The pin
   test pattern generalizes (coverage floor in `composer.json` is the same
   class of risk, currently unpinned).
2. **"Issue premise can be stale by the time it is picked up — verify against
   the current tree first"** — tags `process`, `docs`, `ci`; trigger: an issue
   claims a doc omits/misstates what CI/workflow history may have already
   changed. Acting on the literal #693 text would have re-documented vanished
   jobs.

(Proposals only — per DEC-009, only the main session writes to
`docs/helpers/`.)

## Validation gaps

None material; the full suite (`composer test`) and `composer lint` were run
in step 3 and passed.
