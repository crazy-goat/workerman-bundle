# Findings — review (#594)

## Round 1

| # | file:line | Severity | What is wrong | What happened |
| - | --------- | -------- | ------------- | ------------- |
| R1-F1 | `docs/proof_of_work/0594-server-config-phpstan-type/findings-coder.md` | low | "five test files" claim inaccurate; actual Rector-affected set is 3 test files + `bin/wait-for-ports.php` + `src/DTO/RequestConverter.php`, and only 2 were listed | **fixed** — list corrected to all five real paths |
| R1-F2 | `docs/proof_of_work/0594-server-config-phpstan-type/findings-coder.md` | low | Stale line reference for deprecated-key reads (cited `src/Worker/ServerWorker.php:47-53`) | **fixed** — now `:136-137` and `:185` |
| R1-F3 | `docs/proof_of_work/0594-server-config-phpstan-type/findings-coder.md` | nit | Stale `reload_strategy` line reference (`:62-77`) | **fixed** — now `:53-68` |
| R1-F4 | `docs/proof_of_work/0594-server-config-phpstan-type/code-decision-1.md` | low | Acceptance criterion 3 (temporary key proves propagation) not literally demonstrated | **not a real finding / answered** — a temporary `probe_key` added to the alias was referenced from `configureHandler()`; PHPStan reported no error, but `treatPhpDocTypesAsCertain: false` also reports no error for a deliberately unknown key, so PHPStan cannot serve as the demonstration here. Propagation is instead proven structurally: all four former call sites now reference the one `ServerConfig` alias, so an added key is visible at each. Recorded in `code-decision-1.md`. |
| R1-F5 | `composer.json` (`rector` step) | low | Criterion 7 (`composer lint` passes) cannot go green on the branch | **deliberately not fixed** — pre-existing on `origin/master` (reproduced with the tree stashed) and outside this diff; tracked by [#714](https://github.com/crazy-goat/workerman-bundle/issues/714). This diff adds none of the five Rector complaints. |
| R1-F6 | `src/Worker/ServerWorker.php:29-35` | nit | Deprecation comment named no concrete replacement | **fixed** — comments now read `@deprecated since 0.9.3, removed in 1.0 — use StaticFilesMiddleware instead`, matching `UPGRADE.md:16` |
| R1-F7 | `src/Worker/ServerWorker.php:29-35` | nit | Alias is a hand-maintained copy of `ConfigurationTreeBuilder`; no test guards drift | **deliberately not fixed** — the issue is explicitly scoped to the docblock de-duplication; the runtime-enforced-DTO alternative is called out in the issue itself as a separate, larger change. PHPStan at level 8 is the enforcement for the alias. |

No high or medium findings in round 1.
