# Review round 1 — #730

## Prior findings

No prior unresolved findings; initial review round.

## Review

- **Decision matches the code.** `MiddlewareDispatcher` runs `$middlewares[0]` first (outermost); `withRootDirectory()` uses `$this->middlewares[] = new StaticFilesMiddleware(...)`, so the static layer is last/innermost. DEC-022 and the code comment describe exactly that.
- **Consequence stated correctly.** User middleware runs before the static layer and can short-circuit/authenticate/decorate static responses; hoisting would remove those hooks. The entry tells a future editor not to reorder without superseding the decision, and cross-references DEC-018's dispatch semantics.
- **No behaviour change.** The diff is a comment, a knowledge-base entry plus its regenerated tag index, and a CHANGELOG line. No test or runtime logic changed.
- **Knowledge-base hygiene.** `php bin/kb-lint.php --fix` regenerated the DEC tag index and reports 0 stale; the `decisions.md` over-budget warning is pre-existing (#744) and slightly worsened by the new entry, noted in `findings-coder.md`.

## Checks

- `php bin/kb-lint.php` — OK, 60 entries, 0 stale (2 pre-existing budget warnings).
- `composer lint` — passed (php-cs-fixer, PHPStan level 8, Rector, kb-lint, check-changelog, check-exception-usage).
- `git diff --check` — clean.

## Findings

None. No further knowledge-base candidate: DEC-022 is the entry itself.
