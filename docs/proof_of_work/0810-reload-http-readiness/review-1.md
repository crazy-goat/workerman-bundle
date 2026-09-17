# Critical review 1 — #810 reload HTTP readiness

## Scope and trigger

- Reviewed branch `test/issue-810-testreloaddoesnotbreakserver-flaky-singl`, HEAD `0e03035`, against `origin/master` (`a5c03fb`). Read issue #810: replace the single post-reload request that races worker restart/cURL 56 with retry or HTTP readiness.
- Critical-review trigger: **more than 200 changed lines** (293 additions, 4 deletions including proof documents), plus daemon reload/process-supervision E2E behavior. No production `src/Http`, public API, signal policy, CI workflow, dependency, or gate changes.
- Full diff reviewed: `tests/WorkermanCommandTest.php`, `CHANGELOG.md`, `code-decision-1.md`, `findings-coder.md`.
- `findings-review.md` did not exist when review began. No previous findings to reconcile.
- Loaded helper tag indexes and relevant testing/daemon/process/lint/coverage/changelog entries. In particular FAQ-007/009/010/011/030/032/037/039 and DEC-007/008/009/021 informed review. No documented-decision violation found. All PHPUnit runs were serialized (FAQ-039). No helpers edited.

## Verdict

**Implementation looks sound for #810; two low-severity proof-document corrections remain.** No high/medium findings. No production behavior or gates need changing. Do not promote the coder's purported port-down inversion as a new issue.

## Findings

### R1-1 — low — Proof overstates deadline guarantees

Locations: `code-decision-1.md:39-41,84-88`; `findings-coder.md:27-31`; related assertion message `tests/WorkermanCommandTest.php:132`.

The zero-budget case proves one immediate failed evaluation followed by exhaustion; it does **not** prove the general claim “no post-deadline probe.” `src/Util/Wait.php:86-96` evaluates the condition, then checks the deadline, then sleeps. A sleep begun before expiry can finish after it, and another probe is evaluated before any deadline check. A successful late probe is accepted. The proof's explanation that only a probe *starting inside* the deadline can overshoot is therefore inaccurate.

Evidence: a read-only `Wait::until` characterization with a 1-second budget and 1100-ms sleep returned true with probe offsets `[0.000113, 1.103160]` seconds. This uses an enlarged sleep only to make the same control flow observable without a timing race; the default 250-ms sleep has the same semantics. `Wait.php:16-20` already documents this soft bound correctly. Guzzle's total `timeout` includes connection time; it need not be added to `connect_timeout`. Normal-operation bound here is approximately 5 seconds + up to 250 ms sleep + 1 second final request (plus scheduling overhead), not an exact 5-second deadline.

Requested correction: document the soft bound and narrow the zero-timeout proof to “no second attempt after the first failure observes exhaustion.” Retaining the existing `Wait` semantics is reasonable for this E2E flake fix; no production refactor or tighter timing gate is requested.

Automated check: a controlled-clock/sleeper characterization of `Wait` would distinguish strict versus soft deadlines. The current zero-budget test cannot. Do not add another timing-sensitive assertion merely to enforce an inaccurate proof claim.

### R1-2 — low — Committed coder evidence contains demonstrably false claims

Locations: `findings-coder.md:16-20,45-52,83-84`; `code-decision-1.md:57-60,89-92`.

These are proof-document defects, not runtime defects:

- **Port-down “inversion”: not a real finding.** `tests/WorkermanCommandTest.php:324-335` returns true when the socket is down, exactly the desired-state semantics of `waitForPortUp():309-321`. Both return false on expiry. Do not change either helper based on this claim.
- **Mock success is observable.** The mock's 200 traverses the actual shared helper's status assertion at test-file line 186 and final readiness assertion at line 191. Queue exhaustion complements that coverage. It is not true that only the real E2E covers this path.
- **RetryMiddleware is mischaracterized.** Installed Guzzle `RetryMiddleware.php:34-43,84-115` accepts a caller-provided decider inspecting response/error; it does not inherently retry 500, and a declined retry returns the rejection reason. Keeping the explicit `Wait` loop is a good simplicity/reuse choice, but the stated alternative's alleged limitations are false.
- **Mock queue exception/type/count is wrong.** `MockHandler.php:79-83` throws `OutOfBoundsException`, not `ServerPackagedPayloadException`. The success test has five queued entries; its fifth request is the successful 200, not queue exhaustion (a sixth request would throw).
- The reported full-suite count is not reproducible for this HEAD: review observed **2703 tests / 18306 assertions**, not 2699 / 18294. The difference is exactly the four added mock tests and their 12 assertions. Relabel the old evidence if it was a baseline run rather than claiming it validates this implementation.

Requested correction: correct/remove these claims before the proof is used for retro or downstream issue filing. Preserve the actual independently verified cURL taxonomy observation.

Automated check: ordinary lint does not verify prose facts. A dependency-source cross-check/characterization establishes middleware and queue behavior; preserving the full suite's raw final summary catches misattributed test counts. No recurring implementation defect requiring a new repository gate was identified.

## Behavioral trace and risk assessment

- Command path remains `WorkermanCommand::handleReload` → `ServerManager::reload` → verified master PID → SIGUSR1 (non-graceful default). The success output still means signal sent, not restart complete. This PR changes only the post-signal observation, not identity checks, forking, shutdown, or escalation.
- Fixed local URL and side-effect-free GET; no user-controlled URL/header input or new security boundary. HTTP 200 is now observed directly rather than inferred from a listening socket inherited across worker reloads.
- Both Guzzle exception families are required: installed `CurlFactory.php:1082-1088,1139-1141` maps errno 7/28/52 to `ConnectException`, 56 to `RequestException`; these are sibling transfer exceptions. The mocks faithfully exercise both. No `ConnectException`-only hole remains.
- Strict errno allowlist `[7,28,52,56]` plus response guard does not swallow arbitrary throwables, unrelated transport/config errors, or a `RequestException` carrying a response. Missing errno fails fast. Non-200 response asserts immediately. Redirect following is disabled, so 3xx cannot become an eventual misleading 200.
- Transient errors may recover, but persistent transient errors reach an assertion failure with the last error. Each real request has a total 1-second timeout and 0.2-second connection timeout. The loop backs off rather than busy-spinning. This is bounded under the documented soft-deadline semantics.
- Deterministic tests cover the required reset/recovery sequence, immediate rejection of 500 and unknown errno, and zero-budget exhaustion with the next queue entry unconsumed. The mock-success case sleeps for nominally 150 ms total; no tight elapsed-time assertion is introduced. Optional further coverage: response-attached errno-56 and 302 followed by queued 200, plus absent errno. These would pin the defensive response/redirect guards but are not observed implementation failures.
- A first successful response need not establish all workers have changed generation; this was already true of the old test and #810 requests successful post-reload HTTP, not generation verification. Scope expansion is unnecessary here.
- No touched public production signatures, resources with new ownership obligations, suppression annotations, or PHP >8.2 syntax. Existing PHPUnit bootstrap cleanup remains responsible for the shared daemon on both pass/failure. Changed lint/style passes; coverage scope still includes production `src/` only.
- Coder's outside-scope raw post-restart request timeout observation is real (`tests/WorkermanCommandTest.php:82` has no timeout). The asserted equivalence of every startup race is not established by this PR. Bootstrap's `shell_exec` lacks an outer timeout, but the normal stop path already waits through `ProcessInspector`; an inevitable shutdown hang is not established. Neither is a new defect introduced here.

## Checks performed (sequential daemon suites)

Environment: macOS, PHP 8.5.10, curl and grpc loaded; PHPUnit 10.5.64. Verified no competing PHPUnit process before starting checks.

1. `composer lint` — exit 0; php-cs-fixer 0 fixable files, PHPStan **level 8** no errors, Rector clean, changelog/exception checks clean. Existing kb-lint line-budget warnings (409 and 310 budgeted lines), no helpers diff.
2. `vendor/bin/phpunit --no-coverage tests/WorkermanCommandTest.php` — **11 tests, 37 assertions**, exit 0.
3. Ten sequential `vendor/bin/phpunit --no-coverage --filter '^.*::testReloadDoesNotBreakServer$' tests/WorkermanCommandTest.php` runs — **10/10 passed**, each 1 test / 4 assertions, ~0.110–0.130 seconds.
4. `vendor/bin/phpunit --no-coverage tests/Util/WaitTest.php` — **12 tests, 30 assertions**, exit 0.
5. Read-only non-daemon `Wait` deadline characterization described in R1-1.
6. `COMPOSER_PROCESS_TIMEOUT=1800 composer test` — **2703 tests, 18306 assertions, 4 deprecations, 42 skips**, exit 0, 64.216 seconds PHPUnit time. Full suite completed before any further daemon suite; no competing runs.

No coverage floor, lint rules, or PHPStan level lowered. Coverage instrumentation and PHP 8.2 were not run locally; no new production source is changed and syntax is compatible with 8.2. Deprecations/skips were observed, not independently baseline-reproduced in this review. No workflow files changed (FAQ-032 sweep condition not applicable; full suite nevertheless run).

## Candidate helper entry (proposal only)

Title: **Guzzle reload transport failures cross exception families**

Tags: `tests`, `daemon`, `http`, `mocks`

Trigger: adding HTTP readiness polling or mocking cURL failures after reload.

Paragraph: Guzzle's curl handler classifies errno 7/28/52 as `ConnectException`, while receive/reset error 56 is a `RequestException`; catching only connection exceptions misses the observed reload flake. Use a narrow errno allowlist, preserve errors with HTTP responses and unexpected statuses, and bound both individual requests and the polling loop. Mock both exception families, success, and permanent failure through the same readiness helper. Shared `Wait` has a soft deadline: a final sleep and probe can cross expiry, so a zero-timeout test proves exhaustion after the first failure, not a strict no-post-deadline-probe guarantee.
