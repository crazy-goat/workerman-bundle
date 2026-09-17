# code-decision-1 — #810: reload HTTP readiness

> Correction note (round 2, R1-1/R1-2): this document originally overstated
> the deadline guarantee, misdescribed Guzzle retry/queue behavior, and called
> skips/deprecations baseline-confirmed without evidence. Those statements are
> corrected below; the original record remains in commit `0e03035`.

## Approach taken

Replace TCP port-up plus one unbounded post-reload request with an actual
HTTP 200 probe through a private `assertHttpReadyAfterReload()` test helper.
The listening socket can remain open while workers restart, so an open port
alone does not establish HTTP readiness. Command success/output assertions
remain unchanged. No production code, dependencies, CI or gates changed.

The helper uses existing `Util\Wait::until()` with its default backoff and a
5-second **soft** polling budget. Each request has `connect_timeout=0.2` and
`timeout=1`; `http_errors=false` exposes unexpected statuses to the assertion,
and `allow_redirects=false` prevents a redirect from becoming a misleading 200.

Only cURL errno 7 (connect failure), 28 (timeout), 52 (empty reply), and 56
(receive/reset failure) are retried. Both `ConnectException` and
`RequestException` must be caught because Guzzle maps 52 and 56 differently.
Exceptions carrying an HTTP response, unknown/missing errno, and unexpected
HTTP statuses fail immediately. Exhaustion after a failed probe produces an
assertion containing the polling budget and last transport error.

Four mock tests exercise the same helper as the E2E test:

1. Errors 7 → 28 → 52 → 56 → HTTP 200: executes both the helper's status and
   readiness assertions, checks queue consumption and bounded request options.
2. Zero budget plus a failed probe: verifies the first failure observes
   exhaustion and no second queued probe is consumed. This does **not** prove
   that a positive-budget loop never starts a probe after expiry.
3. HTTP 500 followed by a queued 200: fails on 500 without retrying.
4. Unexpected errno 8 followed by a queued 200: propagates the error without
   retrying.

## Rejected options

- Keep TCP polling or add a fixed sleep: neither observes HTTP readiness.
- Retry the whole PHPUnit test: would repeat command execution and mask
  unrelated assertion failures rather than handle just transient transport.
- Guzzle `RetryMiddleware`: viable with a caller-supplied decider inspecting
  response/error, and it preserves a rejection when retry is declined.
  Rejected for simplicity: the existing `Wait` helper already supplies the
  backoff/soft-budget policy, with failure assertions visible in one place.
  The original claims that middleware necessarily retries 500 and swallows
  exceptions were false.
- Custom polling infrastructure or test-only exhaustion exception: unnecessary
  for one call site; reuse `Wait` and PHPUnit assertions instead.
- `connect_timeout` alone: does not bound a response stalled after connection;
  total `timeout` is also required.
- Expand the fix to stop/start testing: outside #810. No equivalent restart
  race was established by these runs.
- A strict-deadline refactor or timing-sensitive test: not required for this
  E2E readiness check; retain the documented `Wait` semantics.

## Limitations and corrected details

- `Wait.php:86-96` evaluates a condition, checks the deadline after failure,
  then sleeps. A sleep started before expiry can finish after it; the next
  probe can start after expiry and a successful late probe is accepted.
  As documented at `src/Util/Wait.php:16-20`, the normal bound is the 5-second
  budget plus up to 250 ms backoff plus the final 1-second request, with
  scheduling overhead. Guzzle's total timeout **includes** connection time;
  the 0.2-second connection timeout must not be added again.
- Retry classification is curl-handler-specific. Missing errno (for example
  from a non-curl handler) fails fast rather than retrying. A message-string
  fallback was not introduced.
- The retry-success mock queues five entries: the fifth request receives
  HTTP 200. A sixth would throw `OutOfBoundsException('Mock queue is empty')`
  (`vendor/guzzlehttp/guzzle/src/Handler/MockHandler.php:79-83`), not the
  nonexistent exception named in the original proof.
- Ten local reload repetitions succeeded, but these observations do not prove
  a worst-case restart duration or that all workers have changed generation.
- Full-suite results and their tracked-Markdown-dependent counts are recorded
  in `findings-coder.md`. Four deprecations and 42 skips were observed; their
  baseline status and individual causes were not independently verified.
