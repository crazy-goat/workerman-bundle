# Review — #625 (allow disabling connection/keepalive timeouts via YAML) — round 3

Branch: `feat/issue-625-allow-disabling-connection-timeout-keepa`
Commits since round 2: `662265b` (data provider conversion) + `2bec127` (PHPStan `@param` phpdoc)
Full diff: `master...HEAD` (11 files, 636 insertions, 10 deletions)

## Earlier findings — revisit pass

| ID | round-2 status | round-3 verdict | evidence |
|----|---------------|-----------------|----------|
| F-1 | fixed (with F-4 residual) | **fixed** | `testConfiguredTreeRejectsNegativeTimeouts` is now a `@dataProvider` test. The data provider `provideNegativeTimeoutOverrides` yields two cases: `connection_timeout => -1` (keepalive at default 30) and `keepalive_timeout => -1` (connection at default 120). Each case independently exercises its node's `min(0)` bound. Verified: 10 tests, 42 assertions pass. The F-4 residual is now resolved (see below). |
| F-2 | fixed | **fixed** | No changes to `tests/RunnerTest.php` since round 2. The Timer::$event reflection capture/restore is unchanged and correct. Full RunnerTest suite was verified passing in round 2 (36 tests, 89 assertions). No regression introduced. |
| F-3 | fixed | **fixed** | No changes to `tests/RunnerTest.php` since round 2. The `$openedStream` guard and `fclose` in `finally` are unchanged and correct. |
| F-4 | fixed | **fixed** | The data provider genuinely exercises each node independently. Case `connection_timeout`: only `connection_timeout` is -1; `keepalive_timeout` uses its default (30). The Processor validates `connection_timeout` first (declared at `ConfigurationTreeBuilder.php:75`) and throws. Case `keepalive_timeout`: only `keepalive_timeout` is -1; `connection_timeout` uses its default (120). The Processor validates `connection_timeout` (120, passes), then validates `keepalive_timeout` (-1, throws). If `->min(0)` is dropped from only `connection_timeout`, case 1 would accept -1 and not throw → `expectException` fails. If dropped from only `keepalive_timeout`, case 2 would accept -1 and not throw → `expectException` fails. Both bounds are independently pinned. |

## F-4 fix verification (specific check requested)

**Does the data provider genuinely exercise each node's bound independently?**

**Yes.** The data provider yields:

1. `'connection_timeout' => [['connection_timeout' => -1]]` — sets `connection_timeout` to -1, leaves `keepalive_timeout` at default (30). Exception is thrown because of `connection_timeout`'s `min(0)`.
2. `'keepalive_timeout' => [['keepalive_timeout' => -1]]` — sets `keepalive_timeout` to -1, leaves `connection_timeout` at default (120). Exception is thrown because of `keepalive_timeout`'s `min(0)` (connection_timeout passes at 120 first).

The test method feeds `[[$override]]` to `Processor::process()`, which wraps the override array as the single config value for the root node. Each child node gets either the provided value or its default. This is the correct way to isolate each node's constraint.

**Independence proof by elimination:**

| Scenario | Case `connection_timeout` | Case `keepalive_timeout` |
|----------|--------------------------|--------------------------|
| Both `min(0)` present | throws (ct: -1 < 0) ✓ | throws (kt: -1 < 0) ✓ |
| Drop `min(0)` from ct only | no throw (ct: -1 accepted, kt: 30 ok) → **fails** ✓ | throws (kt: -1 < 0) ✓ |
| Drop `min(0)` from kt only | throws (ct: -1 < 0) ✓ | no throw (ct: 120 ok, kt: -1 accepted) → **fails** ✓ |
| Drop both `min(0)` | no throw → **fails** ✓ | no throw → **fails** ✓ |

All four regression scenarios are caught. The fix is correct and complete.

## KB entries read (tag-index match)

Files in diff: `tests/DependencyInjection/ConfigurationTreeBuilderTest.php`, `src/DependencyInjection/ConfigurationTreeBuilder.php`

Tags matched: `config`, `tests`, `phpstan`, `timers`, `long-running`

- `decisions.md` → **DEC-003** (`timers,long-running`): one worker-level sweeper supersedes per-connection timers. The diff exposes the runtime's already-existing `0`-disables path via `min(0)`. No violation.
- `decisions.md` → **DEC-014** (`tests`): static cache pattern. Not relevant to this diff — no static caches involved. No violation.
- `faq.md` → **FAQ-013** (`tests,timers`): initialize `Workerman\Timer` with the test event loop. Relevant to RunnerTest changes (round 1), not this round's changes. Consistent.
- `faq.md` → **FAQ-014** (`tests,phpstan`): PHPStan requires `chr()` args provably in 0..255. Not directly relevant, but the `@param array<string, int> $override` phpdoc added in `2bec127` addresses PHPStan's `missingType.iterableValue` — consistent with the repo's approach of typing rather than suppressing.
- `faq.md` → **FAQ-024** (`config`): deprecation node behavior. Not relevant — no deprecations in this diff. No violation.
- `faq.md` → **FAQ-034** (`ci,yaml,tests`): YAML `on:` gotcha. Not relevant to this diff. No violation.

## Test runs

| command | result | summary |
|---------|--------|---------|
| `php vendor/bin/phpunit tests/DependencyInjection/ConfigurationTreeBuilderTest.php` | passed | 10 tests, 42 assertions (1 warning: no coverage driver — pre-existing, not this diff) |
| `php vendor/bin/phpstan analyse tests/DependencyInjection/ConfigurationTreeBuilderTest.php --no-progress` | passed | No errors |

Note: phpunit exits with code 1 due to the "No code coverage driver available" warning. This is a pre-existing environment issue, not related to this diff. All 10 tests and 42 assertions pass.

## Scope check

The two new commits (`662265b` + `2bec127`) touch only:
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php` — converted the reject test to a data provider, added `@param array<string, int>` phpdoc
- `docs/proof_of_work/0625-timeout-disable-yaml/findings-review.md` — review findings (round 2)
- `docs/proof_of_work/0625-timeout-disable-yaml/review-2.md` — round 2 review

No source code (`src/`) changes. No `tests/RunnerTest.php` changes. No config changes. Scope is clean.

## Verdict

**Clean.** All four findings (F-1 through F-4) are fixed. The F-4 fix is correct and complete — the data provider genuinely exercises each node's `min(0)` bound independently, and all regression scenarios (dropping one or both `min(0)` calls) are caught. PHPStan is clean. Tests pass. No new issues found.

## New findings

None. The diff is clean.

## Candidate KB entries

- **Title:** Reject-side config tests must exercise each constrained node independently
- **Tags:** `config`, `tests`
- **Trigger:** writing a `expectException(InvalidConfigurationException)` test that feeds multiple constrained fields through a single `Processor::process()` call.
- **Paragraph:** Symfony's Config `Processor` throws on the first invalid child node, so a single `process()` call with two out-of-range fields only validates the first-declared node's bound. To pin each `->min()`/`->max()` independently, use a `@dataProvider` that yields one field out-of-range at a time (the other fields use their defaults). #625's reject test was initially a single call with both `connection_timeout: -1` and `keepalive_timeout: -1`; only `connection_timeout`'s `min(0)` was exercised because it is declared first. Converting to a data provider with one field negative per case pins both bounds.

## Gaps in validation / areas checked clean

- **Data provider type correctness:** `@return iterable<string, array{array<string, int>}>` matches the yielded structure `['connection_timeout' => [['connection_timeout' => -1]]]`. PHPStan confirms no errors.
- **Default values used as isolation mechanism:** When only one field is in the override array, the other field uses its tree-defined default (120 for connection_timeout, 30 for keepalive_timeout), both positive and valid. This is the correct isolation approach — no need to explicitly set the other field.
- **No source code changes since round 1:** `src/DependencyInjection/ConfigurationTreeBuilder.php` is unchanged since the original feature commit. The `min(0)` constraints on both nodes are intact.
- **No staged files:** `git diff --cached` is empty.
