# Review — round 2 — #656

## Scope

Re-review after F1+F2 fix in `UPGRADE.md` 0.22 section. Round 1 findings: F1 medium (invalid YAML example), F2 low (phrasing), F3 nit (0.24 folding).

## Checks

- Re-ran `composer lint` — OK (php-cs-fixer, phpstan, rector, kb-lint, check-changelog).
- Re-ran `vendor/bin/phpunit tests/MarkdownLinkTest.php` — 419 tests OK.
- Verified `UPGRADE.md` 0.22 section: service-arg phrasing correct, no invalid YAML block, note "There is no YAML equivalent" accurate vs `ConfigurationTreeBuilder` (only `allowed_extensions`).
- F3 accepted (intentional folding per `code-decision-1.md`).

## Findings

No open findings — clean round.

## Verdict

Clean. Ready for merge.
