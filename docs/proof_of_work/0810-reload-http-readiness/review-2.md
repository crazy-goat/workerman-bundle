# Critical review 2 — #810 reload HTTP readiness

## Scope, trigger and reading order

Reviewed HEAD `5daf0f0`, specifically commits `b349eb8` (round-1 reviewer records) and `5daf0f0` (coder proof corrections), against previously reviewed `0e03035`. Read `findings-review.md` **first**, then helper tag indexes and relevant entries (DEC-009, DEC-012, DEC-021, FAQ-039), then the full incremental diff and `code-decision-2.md`.

Critical-review trigger remains the overall branch's >200 changed lines and daemon reload E2E review context. This round changes only proof Markdown; the implementation, production source, tests, dependencies, CHANGELOG, workflows, and quality gates are unchanged. Verified by `git diff 0e03035..HEAD` scoped to those paths. Reviewer records committed by `b349eb8` were not changed by `5daf0f0`.

## Previous findings — explicit dispositions

### R1-1 — fixed

`code-decision-1.md:16-19,32-34,61-67` and `findings-coder.md:24-30` now correctly describe the **soft** polling deadline: a sleep may cross expiry, another probe may start afterward, and late success is accepted. The zero-budget case is limited to exhaustion following the first failure, not a guarantee about every positive-budget attempt. The final bound includes backoff and total request duration without double-counting connection time. `code-decision-2.md:12-17` records the same decision. This matches the already traced `Wait.php:83-97` behavior. No runtime refactor or timing-sensitive regression test is needed for this documentation correction.

### R1-2 — fixed, with a reviewer correction on test counts

- Port-down inversion explicitly retracted in `findings-coder.md:41-44`; it remains **not a real runtime finding**. Both helpers return true upon observing their desired state.
- Mock success-path coverage corrected at `findings-coder.md:17-22` and `code-decision-1.md:30-31`.
- RetryMiddleware's caller-supplied decider and preservation of declined rejection corrected at `code-decision-1.md:44-49`.
- Five queued entries culminating in HTTP 200, with a sixth request throwing `OutOfBoundsException`, corrected at `code-decision-1.md:71-74`.
- Unsupported baseline attribution of skips/deprecations withdrawn at `findings-coder.md:74-75`. Other outside-scope claims now distinguish observed source from unproven failure hypotheses.

**The suite-count discrepancy portion of R1-2 was not a real finding of missing implementation validation. I retract my round-1 inference that the four-test/twelve-assertion difference demonstrated the added mock tests were absent from the original full run.** `MarkdownLinkTest.php:35-74,309-317` derives cases from `git ls-files '*.md'` and applies two test methods per file. The original focused 11-test run already included all four mock tests. Two newly tracked coder proof documents explain four additional full-suite cases; three subsequent proof files explain six more. `code-decision-2.md:26-30,40-50` and `findings-coder.md:64-73,112-118` now preserve the different run/index contexts rather than relabeling the earlier result as a master baseline.

Independent verification: current index has 310 tracked Markdown files, and the Markdown-only run reports **621 tests / 2006 assertions** (two cases per file plus its standalone slug test). This agrees with the new rationale and recorded result. A dynamic suite total changing with tracked files is not itself a defect. Historical tool logs were not independently rerun at every historical index state; the discovery source, commit file additions, and current result corroborate the explanation.

## New-issue review and verdict

Read all corrected proof text and new decision record. Corrections match the implementation and dependency behavior established in round 1; no new unsupported runtime claim requires action. No documented helper decision violation, public-interface change, signal/shutdown change, gate reduction, or scope expansion found. The previously accepted readiness implementation remains unchanged: bounded requests, narrow errno allowlist across both Guzzle exception families, immediate failure for unexpected HTTP status/response-bearing errors, and diagnostic exhaustion.

**No open findings. No new high, medium, low, or nit findings. Round 2 is clean.**

## Verification and reused evidence

All local PHPUnit invocations were sequential; no competing daemon suite was launched.

- Compared incremental file scope and reviewer-file preservation with Git: proof-only corrections, no implementation changes.
- `vendor/bin/phpunit --no-coverage tests/MarkdownLinkTest.php`: observed successful summary, **621 tests / 2006 assertions**.
- `vendor/bin/phpunit --no-coverage tests/WorkermanCommandTest.php`: observed successful summary, **11 tests / 37 assertions**.
- `composer lint`: output reached the final successful exception-usage check; existing two kb-lint budget warnings only. These round-2 commands were piped to `tail` without pipefail, so their shell exit statuses are not claimed as independent upstream exit evidence; the successful summaries/final Composer script execution are the evidence.
- Reused the unpiped round-1 `composer lint` exit-0 result (PHPStan level 8, style/Rector clean), full `composer test` exit-0 result (**2703 / 18306** at that index), Wait tests (**12 / 30**), and ten successful sequential real reload repetitions for the unchanged implementation. No need to rerun the full daemon suite merely for corrected proof text. The coder also records a later pipefail-protected full-suite pass (**2709 / 18324**) at the now-current tracked-file state; this is reported coder evidence, not a new reviewer run.
- No coverage, PHPStan, lint, or CI gate changes. No commits, pushes, or helpers edits by reviewer.

## Helper candidates

The round-1 Guzzle exception-family/soft-deadline proposal remains appropriate; no duplicate entry proposed. Additional optional candidate:

- **Title:** Tracked Markdown changes alter PHPUnit suite totals.
- **Tags:** `tests`, `docs`, `process`.
- **Trigger:** reconciling PHPUnit counts before and after staging proof documents.
- **Paragraph:** `MarkdownLinkTest` discovers files through `git ls-files '*.md'` and creates two data-provider cases per tracked document. Staging proof records can therefore change total test/assertion counts even when implementation and test PHP are identical. Record index/file context with results, inspect discovery before inferring omitted regression tests, and distinguish an observed successful summary from an exit code hidden by a pipeline lacking pipefail.
