# code-decision-2 — #810 proof corrections (R1-1/R1-2)

## Plan and scope

Read the round-1 review, verify the challenged claims against source, correct
only coder-authored proof documents with an explicit correction note, and
append dispositions. Preserve and commit reviewer files unchanged. No change
to implementation, CHANGELOG, production code, dependencies, gates or helpers.

## Decisions

- Accept R1-1: `Wait` evaluates a condition, checks expiry after failure, then
  sleeps. A sleep can cross expiry before a final probe starts, and late
  success is accepted. The soft bound includes the final sleep and request;
  the total request timeout already includes connection time. Narrow the
  zero-budget proof to first-failure exhaustion. Do not add a timing-sensitive
  test or refactor production waiting to defend an incorrect prose claim.
- Accept the factual corrections in R1-2: both port helpers have consistent
  desired-state semantics; mock HTTP 200 reaches the real helper assertions;
  Guzzle retry middleware accepts a caller-defined decider and preserves
  declined rejections; five queued entries end with success and a sixth
  request would throw `OutOfBoundsException`.
- Retain the explicit `Wait` loop for reuse/simplicity, not because of alleged
  limitations of Guzzle middleware. Retract unsupported claims about restart
  races, guaranteed shutdown hangs, and baseline deprecations/skips.
- Preserve the actual first-run suite result with precise provenance instead
  of relabeling it a master baseline. It ran before the two coder proof files
  were tracked. `MarkdownLinkTest` runs two tests per tracked `.md` file, which
  explains the four-test increase after committing them. The four mock tests
  were already present in the original 11-test focused run.

## Independent verification

- Read `src/Util/Wait.php:16-20,83-97`, the test's port helpers at
  `tests/WorkermanCommandTest.php:309-335`, and the shared status/readiness
  assertions at lines 186/191.
- Read installed `vendor/guzzlehttp/guzzle/src/RetryMiddleware.php:34-43,84-115`
  and `Handler/MockHandler.php:79-83`: confirmed configurable retry decisions,
  preserved declined rejection, and empty-queue exception.
- Before committing review files, `git ls-files '*.md'` returned 307 files;
  `vendor/bin/phpunit --no-coverage tests/MarkdownLinkTest.php` passed with
  **615 tests / 1988 assertions**. Source at
  `tests/MarkdownLinkTest.php:35-74,309-317` confirms tracked-file discovery.
- At that same index state (HEAD `0e03035`, review files untracked), reran
  `COMPOSER_PROCESS_TIMEOUT=1800 composer test`: PHPUnit reported
  **2703 tests / 18306 assertions, 4 deprecations, 42 skips**, 63.852 seconds,
  and the final stop command completed. This matches the reviewer result.
  Output was piped to `tail` without pipefail, so the shell status alone is
  not standalone Composer-exit evidence; the successful PHPUnit summary and
  execution of the final stop command are the observed evidence.
- Committed reviewer files verbatim as `b349eb8`; its pre-push lint passed.
  The final staged proof documents are checked again before the next push;
  results are appended to `findings-coder.md`.

## Transparency

Original inaccurate text remains recoverable at `0e03035`; correction notes
in the revised files identify the retractions. Reviewer findings remain
unchanged for independent round-2 disposition. No new runtime bugs proven.
