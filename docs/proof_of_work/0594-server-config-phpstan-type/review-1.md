# Review — round 1 (#594)

Independent read-only review of `git diff origin/master...HEAD`
(branch `chore/issue-594-server-config-array-shape-is-duplicated`).

## Verification performed

- **Diff is docblock-only.** Filtering the `src/` diff for non-comment,
  non-blank lines yields zero executable lines changed.
- **Semantic identity.** The three inline `@param` shapes on `origin/master`'s
  `ServerWorker` and the nested `servers?: list<array{...}>` in
  `WorkermanBundle` were extracted, normalized and compared with the new alias:
  identical (11 keys, same order, optionality, nullability, `static_files`
  nesting). Only delta: three added `@deprecated` comments.
- **PHPStan.** `vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`
  at level 8; the `@phpstan-import-type` FQCN resolves.
- **Tooling does not rewrite the comments.** `rector --dry-run` → OK;
  `php-cs-fixer --dry-run` → no files changed; the inline comments survive.
- **Propagation.** `__construct` (`ServerWorker.php:43`), `configureHandler`
  (`~:175`), `createSslContext` (`~:195`) all use `@param ServerConfig`;
  `WorkermanBundle.php:52` uses `servers?: list<ServerConfig>`. All four former
  sites dereference the single alias.
- **Acceptance criteria.** 1, 2, 4, 5, 6, 8 met. 3 and 7 discussed in the
  findings below.

## Findings

| # | Location | Severity | Description |
| - | -------- | -------- | ----------- |
| R1-F1 | `docs/proof_of_work/0594-.../findings-coder.md` | low | "five test files" inaccurate — 2 of the 5 Rector files are not tests; only 2 listed |
| R1-F2 | `docs/proof_of_work/0594-.../findings-coder.md` | low | deprecated-key read line refs were stale (47-53 → 136-137, 185) |
| R1-F3 | `docs/proof_of_work/0594-.../findings-coder.md` | nit | `reload_strategy` line ref wrong (62-77 → 53-68) |
| R1-F4 | `docs/proof_of_work/0594-.../code-decision-1.md` | low | acceptance criterion 3 not literally demonstrated by a temporary key |
| R1-F5 | `composer.json` / branch | low | criterion 7 (`composer lint` passes) unmet due to pre-existing Rector drift (#714) |
| R1-F6 | `src/Worker/ServerWorker.php:29-35` | nit | deprecation comment named no concrete replacement; `UPGRADE.md:16` names `StaticFilesMiddleware` |
| R1-F7 | `src/Worker/ServerWorker.php:29-35` | nit | alias remains a hand-maintained copy of `ConfigurationTreeBuilder`; no test enforces it |

**Verdict (round 1):** no high or medium findings. 3 documentation-inaccuracy
low/nits, 2 acceptance-criteria gaps (one pre-existing, one structural), 2 nits.

All findings are answered in `findings-review.md`.
