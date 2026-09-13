# Findings — review (#594)

## Round 1

| # | file:line | Severity | What is wrong | What happened |
| - | --------- | -------- | ------------- | ------------- |
| R1-F1 | `docs/proof_of_work/0594-server-config-phpstan-type/findings-coder.md` | low | "five test files" claim inaccurate; actual Rector-affected set is 3 test files + `bin/wait-for-ports.php` + `src/DTO/RequestConverter.php`, and only 2 were listed | **fixed** — list corrected to all five real paths |
| R1-F2 | `docs/proof_of_work/0594-server-config-phpstan-type/findings-coder.md` | low | Stale line reference for deprecated-key reads (cited `src/Worker/ServerWorker.php:47-53`) | **fixed** — now `:136-137` and `:185` |
| R1-F3 | `docs/proof_of_work/0594-server-config-phpstan-type/findings-coder.md` | nit | Stale `reload_strategy` line reference (`:62-77`) | **fixed** — now `:53-68` |
| R1-F4 | `docs/proof_of_work/0594-server-config-phpstan-type/code-decision-1.md` | low | Acceptance criterion 3 (temporary key proves propagation) not literally demonstrated | **not a real finding / answered** — a temporary `probe_key` added to the alias was referenced from `configureHandler()` as `$serverConfig['probe_key'] ?? null`; PHPStan reported no error. This proves nothing: the `?? null` form suppresses `offsetAccess.notFound` (round 2 N1 corrected the original, wrong explanation that cited `treatPhpDocTypesAsCertain`). Propagation is instead proven structurally: all four former call sites now reference the one `ServerConfig` alias, so an added key is visible at each. Recorded in `code-decision-1.md`. |
| R1-F5 | `composer.json` (`rector` step) | low | Criterion 7 (`composer lint` passes) cannot go green on the branch | **deliberately not fixed** — pre-existing on `origin/master` (reproduced with the tree stashed) and outside this diff; tracked by [#714](https://github.com/crazy-goat/workerman-bundle/issues/714). This diff adds none of the five Rector complaints. |
| R1-F6 | `src/Worker/ServerWorker.php:29-35` | nit | Deprecation comment named no concrete replacement | **fixed** — comments now read `@deprecated since 0.9.3, removed in 1.0 — use StaticFilesMiddleware instead`, matching `UPGRADE.md:16` |
| R1-F7 | `src/Worker/ServerWorker.php:29-35` | nit | Alias is a hand-maintained copy of `ConfigurationTreeBuilder`; no test guards drift | **deliberately not fixed** — the issue is explicitly scoped to the docblock de-duplication; the runtime-enforced-DTO alternative is called out in the issue itself as a separate, larger change. PHPStan checks the alias's consumers but cannot detect drift between the alias and `ConfigurationTreeBuilder` (the gap this finding names). |

No high or medium findings in round 1.

## Round 2

Round 2 verified all seven round-1 findings and looked only for new issues.
R1-F1, R1-F2, R1-F3 and R1-F6 confirmed **fixed** with evidence; R1-F4, R1-F5
and R1-F7 confirmed honestly dispositioned. Two new documentation findings:

| # | file:line | Severity | What is wrong | What happened |
| - | --------- | -------- | ------------- | ------------- |
| R2-N1 | `code-decision-1.md:47-48`, `findings-review.md` (R1-F4 row) | low | Wrong reason given for why the PHPStan probe was inconclusive: cited `treatPhpDocTypesAsCertain: false`, but the actual suppression is the `?? null` form (`offsetAccess.notFound` is reported for a plain access even under that setting) | **fixed** — rationale corrected in both files |
| R2-N2 | `findings-review.md` (R1-F7 row) | nit | "PHPStan at level 8 is the enforcement for the alias" overstated — PHPStan checks consumers, not alias vs `ConfigurationTreeBuilder` drift | **fixed** — claim narrowed to "checks the alias's consumers but cannot detect drift between the alias and `ConfigurationTreeBuilder`" |

## Round 3

Round 3 verified `211c0e9` and looked for new issues. R2-N1 and R2-N2 confirmed
**fixed** with evidence (R2-N1 empirically re-probed: a plain optional-key access
is flagged `offsetAccess.notFound`, `?? null` is not). No new findings.

**Open findings after round 3: 0.**
