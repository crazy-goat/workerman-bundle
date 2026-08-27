# Review — Round 1

## Scope
Diff: 8 PHP files + 2 docs (CHANGELOG, UPGRADE) + 2 proof files. Branch `perf/issue-562-needspeakmemory-is-false-in-every-shippe` vs `master`. Checked against `docs/helpers` tag index (tags: http, memory, policy, coverage, docs).

## Helpers read
- `faq.md` index — no entry directly about `needsPeakMemory` (FAQ-017 triage, FAQ-010 coverage, FAQ-015 lint). Verified `bin/pick-issue.php` scoring and `composer lint` gates.
- `decisions.md` DEC-007 (coverage floor), DEC-014 (static cache), DEC-001 (single-send). None conflict with removal.

## Findings reviewed (prior rounds)
No prior `findings-review.md` entries — first round.

## New findings

| # | File:Line | Severity | Description | Evidence |
|---|---|---|---|---|
| R1-1 | `src/Reboot/Strategy/RebootStrategyInterface.php:44-45` | nit | Extra blank line between `shouldReboot()` and `}` (`44: ` + `45: }`). Not a lint error today (php-cs-fixer 0 fixable) but slightly noisy. | `cat -A` shows blank line; fixer left it. |
| R1-2 | `UPGRADE.md:33` | low | Migration heading `## Upgrading to 0.28` assumes next release is 0.28.0 (current lowest milestone). If milestone rolls to 0.29 before merge, heading needs bump. | `php bin/pick-issue.php` output: `0.28.0 (17 open) <-- picked`. Acceptable now, note for maintainer. |
| R1-3 | `CHANGELOG.md:12` | nit | Entry under `### Removed` uses long single-bullet paragraph; previous `Removed` entries do not exist to compare style, but `Fixed` entries use multi-line wrapped bullets. Not a structural error (`bin/check-changelog.php` OK). | `composer lint` check-changelog OK. |

No `high` or `medium` findings. Type correctness, error handling, security, PSR-12, test coverage — all pass.

- **Type correctness:** All 5 strategy classes implement interface with single method; `StackRebootStrategy` no longer references `needsPeakMemory`; `HttpRequestHandler` constructor no longer queries strategy. PHPStan level 8: 0 errors.
- **Error handling:** No new error paths; `HttpRequestHandler::__invoke` now unconditional pipeline→send→terminate→reboot, same as before minus dead branch.
- **PSR-12 / cs-fixer:** `composer lint` fixer 0 fixable.
- **Tests:** `RebootStrategyTest` 36 pass, `HttpRequestHandlerTest` 67 pass (3 gating tests deliberately removed, mocks updated). Full suite 2501 tests 1 pre-existing PharReadOnly failure.
- **Security:** Removal of `memory_reset_peak_usage` reduces per-request syscall, no new input handling.
- **Docs:** CHANGELOG structural check OK, UPGRADE migration shows before/after and peak self-reset snippet.

## Verdict
**Clean with nits** — no blocking findings. Nits are style/versioning, not correctness. Ready for `composer lint` + `composer test` gate and PR open.

## Candidate knowledge-base entries
None — this is a straight dead-code removal; the reasoning (GC incompatibility with peak) is captured in `code-decision-1.md` and UPGRADE.md, no reusable pattern beyond this issue.
