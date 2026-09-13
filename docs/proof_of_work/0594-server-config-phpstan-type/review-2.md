# Review — round 2 (#594)

Independent read-only review, follow-up to `review-1.md`. Base
`origin/master`, diff `git diff origin/master...HEAD`.

## Round-1 finding status

| Finding | Status | Evidence |
| ------- | ------ | -------- |
| R1-F1 | fixed | `findings-coder.md:6-11` lists all five Rector files; only the last three are tests. Matches Rector output exactly. |
| R1-F2 | fixed | Cites `ServerWorker.php:136-137` and `:185` — the actual `serve_files` / `root_dir` / `static_files` reads. |
| R1-F3 | fixed | Cites `WorkermanBundle.php:53-68`; `reload_strategy?: array{` is at line 53, closing `},` at 68. |
| R1-F4 | answered (rationale corrected by R2-N1) | All four call sites reference the single `ServerConfig` alias; no committed probe artifact. |
| R1-F5 | honest / pre-existing | Branch touches none of the five Rector files; identical complaints on `origin/master`; tracked by #714. |
| R1-F6 | fixed | `ServerWorker.php:29,31,34` name `StaticFilesMiddleware` and `removed in 1.0`, matching `UPGRADE.md:16`. |
| R1-F7 | deliberately not fixed, scope-consistent | No test references `ServerConfig`; scope answer honest, enforcement claim corrected by R2-N2. |

## New findings (since `ff0bb43`)

| # | Location | Severity | Description |
| - | -------- | -------- | ----------- |
| R2-N1 | `code-decision-1.md:47-48`, `findings-review.md` (R1-F4 row) | low | Wrong explanation for the inconclusive probe: `treatPhpDocTypesAsCertain: false` does not suppress `offsetAccess.notFound`; the `?? null` form does. |
| R2-N2 | `findings-review.md` (R1-F7 row) | nit | Overstated "PHPStan is the enforcement for the alias". |

## Non-findings checked and cleared

- `php -l` on `ServerWorker.php` clean; the changed deprecation comment still
  parses and is not rewritten by Rector or php-cs-fixer.
- PHPStan at level 8: `[OK] No errors`.
- The `src/` diff still contains zero non-comment changed lines.
- `CHANGELOG.md` entry matches reality.

**Verdict (round 2):** not clean — 1 low (R2-N1) and 1 nit (R2-N2), both
documentation-only and fixed in this round.
