# Findings — coder (#810)

> Round-2 correction note: R1-1/R1-2 identified factual errors in the original
> proof (commit `0e03035`). The corrected round-1 account follows, with explicit
> retractions; round-2 dispositions are appended below. Reviewer files are
> preserved verbatim.

## Round 1 — obstacles and biggest problem (corrected)

The biggest challenge was testing a transport race deterministically without
hiding real failures. Guzzle's installed
`vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:1082-1088,1139-1141`
classifies errno 7/28/52 as `ConnectException`, but 56 as `RequestException`.
Catching only connection exceptions would miss the reported reset. The helper
catches both with a narrow errno allowlist and refuses response-bearing errors.

`MockHandler` sequences exercise the actual helper, including its HTTP 200
status assertion and final readiness assertion
(`tests/WorkermanCommandTest.php:186,191`). Queue consumption and request-option
assertions complement that coverage. Only the daemon/network interaction is
E2E-only; the earlier claim that mocks did not exercise the success path was
false.

The zero-budget mock proves that the first failed probe observes exhaustion
and no second probe runs in that case. It does not prove a strict deadline:
`src/Util/Wait.php:86-96` checks expiry after a failed condition, then sleeps.
The sleep may cross expiry before another probe starts, and late success is
accepted. The documented soft bound is budget plus one backoff sleep plus one
final request (here approximately 5 + 0.25 + 1 seconds, plus scheduling
overhead). Connection time is included in the total request timeout.

## Outside-scope observations and retractions

- `tests/WorkermanCommandTest.php:51,54,82`: the initial and post-restart
  requests in `testStopAndStartViaCli` have no explicit timeout. Suggested
  improvement: add bounded request options; investigate HTTP readiness polling
  if a restart race is demonstrated. This PR did not establish that restart
  has the same race as reload. No unrelated fix made. The original claim
  about *every* raw request was false: the stopped-server request at line 63
  already has a 1-second timeout.
- **Retracted — not a real bug:** `waitForPortDown` polarity
  (`tests/WorkermanCommandTest.php:309-335`). Both port helpers return true
  when their desired state is observed, false on unsuccessful exhaustion.
  No inversion exists and no fix or issue should be proposed.
- `tests/App/bootstrap.php:55-61`: shutdown invokes `shell_exec` without an
  outer subprocess timeout. Its normal stop path already has process-wait
  handling; a shutdown hang was not reproduced or proven here. This is only
  an investigation candidate: establish a reachable unbounded path before
  considering a bounded subprocess wrapper. Retract the original unconditional
  hang claim and automatic SIGKILL recommendation.
- Existing kb-lint warnings: `docs/helpers/faq.md` has 409 budgeted lines and
  `docs/helpers/decisions.md` 310, each against a 300-line budget. These are
  totals, not the number of excess lines. Suggested improvement: main-session
  promotion/pruning of entries. No helpers edits made.

## Round-1 checks and evidence provenance

- `vendor/bin/phpunit --no-coverage tests/WorkermanCommandTest.php`: 11 tests,
  37 assertions, OK (includes all four new mock tests).
- Ten sequential real runs of
  `vendor/bin/phpunit --no-coverage --filter '^.*::testReloadDoesNotBreakServer$' tests/WorkermanCommandTest.php`:
  10/10 passed, each 1 test / 4 assertions.
- `composer lint`: exit 0, with the existing two kb-lint budget warnings.
- `COMPOSER_PROCESS_TIMEOUT=1800 composer test`: original tool output was
  **2699 tests / 18294 assertions**, 4 deprecations, 42 skips, exit 0. This
  was the implemented working tree **before the two coder proof files were
  tracked**, not a master baseline. At committed HEAD `0e03035`, the reviewer
  and the round-2 coder rerun observed **2703 / 18306**.
- The count difference is explained by `tests/MarkdownLinkTest.php:35-74,309-317`:
  two tests per tracked Markdown file from `git ls-files '*.md'`. Adding the
  two coder proof files adds four test cases. It is not evidence that the mock
  tests were missing from the original run. Round 2 independently checked the
  Markdown-only suite at 307 tracked files: **615 tests / 1988 assertions**.
- Skips/deprecations were observed, not independently baseline-reproduced;
  the original attribution to master and specific causes is withdrawn.
- All daemon test runs were serialized; no concurrent suites or platform bypass.

## Round 2 — dispositions of review findings

- **R1-1: addressed in coder proof.** Corrected both documents to describe
  the soft deadline and the limited zero-budget evidence. No `Wait` refactor
  or implementation changes. Review files retain their original open status
  for the reviewer to reconcile independently.
- **R1-2: addressed in coder proof, with suite-count clarification.** Retracted
  port-down inversion and mock-success claims. Corrected middleware decider
  behavior and queue exhaustion type/count after reading dependency source.
  Independently reproduced the review's 2703/18306 summary before tracking
  review files, while preserving the real 2699/18294 earlier result with its
  tracked-file context. Did not edit the reviewer's count interpretation.

Biggest round-2 obstacle: separating actual defects in my prose from the
suite-count discrepancy. File-driven test discovery meant both recorded
summaries were valid for different Git index states; treating either as a
baseline without checking discovery would introduce another false claim.
No additional confirmed runtime bugs were discovered.

### Helper candidate (proposal only)

- Title: Guzzle reload errors cross exception families.
- Tags: `tests`, `daemon`, `http`, `mocks`.
- Trigger: adding HTTP readiness polling after reload.
- Paragraph: Guzzle curl errno 52 uses `ConnectException`, while errno 56 uses
  `RequestException`. Handle both with a narrow transient-error allowlist,
  preserve unexpected responses/errors, and bound requests plus polling.
  Exercise recovery and exhaustion through the shared helper with mocks.
  `Wait` has a soft deadline, so zero-budget coverage is not a strict timing
  guarantee.

### Final round-2 checks

- Exact real reload test rerun: 1 test / 4 assertions, exit 0, 0.128 s.
- After staging all five proof documents (310 tracked Markdown files),
  `vendor/bin/phpunit --no-coverage tests/MarkdownLinkTest.php`: 621 tests /
  2006 assertions, exit 0.
- `set -o pipefail; COMPOSER_PROCESS_TIMEOUT=1800 composer test 2>&1 | tail -12`:
  **2709 tests / 18324 assertions**, 4 deprecations, 42 skips, exit 0,
  63.224 seconds. The six-case increase over 2703 comes from the two review
  files plus `code-decision-2.md` (three additional tracked Markdown files).
- All above daemon runs sequential. `git diff --cached --check` passed.
- Changed this round: corrected `code-decision-1.md`, `findings-coder.md`,
  added `code-decision-2.md`, committed unchanged reviewer files
  `review-1.md` and `findings-review.md`. No implementation files changed.
