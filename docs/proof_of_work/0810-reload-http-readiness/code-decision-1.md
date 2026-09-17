# code-decision-1 — #810: `testReloadDoesNotBreakServer` flaky (cURL 56)

## Approach taken

Replaced the test's TCP port-up readiness inference with **bounded polling for
an actual HTTP 200**, retrying only transient transport errors, using the
existing `CrazyGoat\WorkermanBundle\Util\Wait` helper (same pattern the test
class and bootstrap already use). No production code, CI or gate was touched.

What changed in `tests/WorkermanCommandTest.php`:

- `testReloadDoesNotBreakServer` now ends with
  `assertHttpReadyAfterReload(new Client())` instead of `waitForPortUp` +
  a single un-retried, un-timeout-ed request. The port-up check is dropped:
  the listening socket stays up across a reload (and the bootstrap already
  waits for it before the suite runs), so it carried no signal.
- New private helper `assertHttpReadyAfterReload(Client $client, int $timeoutSeconds = 5)`:
  - Each probe is an HTTP GET `http://127.0.0.1:8888/response_test` with
    **bounded timeouts** (`connect_timeout=0.2`, `timeout=1`) plus
    `http_errors=false`, `allow_redirects=false`.
  - `Wait::until()` gives the whole loop a bounded wall time (5 s default,
    same value the old port-up wait used).
  - Retry allowlist: cURL errno 7 (couldn't connect), 28 (timed out),
    52 (empty reply), 56 (recv/reset failure) — the transient shapes a
    reload can produce. The flake's signature was cURL 56.
  - Fail immediately (no retry) on:
    - a non-200 status (assert inside the condition, message starts with
      "Unexpected HTTP status after reload"),
    - a transport error with a response attached (`hasResponse()`),
    - a transport error whose `errno` is outside the allowlist.
  - If the deadline expires on transient errors, `assertTrue` fails with a
    message carrying the deadline and the last transport error, so a future
    failure is diagnosable instead of a bare timeout.
- Deterministic coverage added on top of the E2E test (all driven through the
  same helper with Guzzle `MockHandler`, so no extra daemon traffic):
  1. `...RetriesTransientTransportFailures` — errno 7 → 28 → 52 → 56 → 200
     succeeds, and asserts the bounded `timeout`/`connect_timeout` options
     actually reach the client.
  2. `...FailsWhenTransportFailurePersists` — deadline 0 exhausts after the
     first failed probe: assertion failure with "last transport error" text,
     and the queued 200 is **not** consumed (proves no post-deadline probe).
  3. `...DoesNotRetryUnexpectedHttpStatus` — a 500 fails immediately and
     leaves the queued 200 unconsumed.
  4. `...DoesNotRetryUnexpectedTransportError` — a `RequestException` with
     errno 8 re-throws as-is (checked with `expectExceptionObject`), so
     unrelated errors are never retried/hidden.

## Rejected options

- **Retry the whole `testStopAndStartViaCli` post-restart request too** — its
  race shape differs (full restart, ports down then up) and the issue scopes
  the fix to `testReloadDoesNotBreakServer`; the helper is ready to reuse
  there if that test ever flakes.
- **Reuse `testReloadHttpReadiness*` mock tests with `@dataProvider`** — four
  cases need different exception expectations and `finally` bookkeeping;
  separate methods keep each failure self-explanatory.
- **`RetryMiddleware` from Guzzle** — it has no transient-error discrimination
  (it would retry the 500), swallows the original exception on a failed retry
  chain, and hides the fact that a timeout policy exists. The explicit loop
  keeps "what retries, what fails now" auditable.
- **Bare `TryAgain`-style throwable for exhaustion** — a custom test-only
  exception adds a type for one call site; `self::assertTrue()` with a
  diagnostic message is the project's existing failure style.
- **Guzzle `connect_timeout` only, no `timeout`** — `Recv failure` mid-response
  (cURL 56) happens after connect; a total-request cap (`timeout=1`) bounds
  the probe in the stuck-response case too.
- **A fixed number of attempts with a sleep between them** — it would be a
  second polling implementation next to `Wait::until` and its total wall time
  would depend on probe duration, not a deadline.
- **Increasing the retry budget (e.g. 15 s)** — worker restarts are fast
  (all ten local reload reps passed in ~0.13 s each); 5 s already absorbs the
  race without masking a real regression.
- **Keeping the old `waitForPortUp` call** — port-up is true the instant the
  probe starts (bootstrap + prior tests guarantee it), so it only added noise.

## Uncertainties

- The retry allowlist is keyed on `getHandlerContext()['errno']` from Guzzle's
  curl handler, so on a host that falls back to Guzzle's non-curl
  `StreamHandler` the errno is absent and transient failures would fail fast
  instead of retrying (correct but less resilient). The CI runner uses the
  curl handler; a message-based fallback would couple the test to Guzzle's
  error-string wording, which is worse.
- Deadline semantics: `Wait::until` checks the deadline between probes, so a
  probe that starts inside the deadline can overshoot it by up to one probe
  (`connect_timeout` 0.2 + `timeout` 1 + backoff ≤ 250 ms → ≤ ~1.5 s worst
  case). Accepted, since the default budget (5 s) is generous relative to
  worker-restart time.
- MockHandler's queue-exhaustion throws `ServerPackagedPayloadException` on a
  5th request in the retry-success test; it can't trigger because the helper
  stops at the queued 200 (and the `assertCount(0, $handler)` assertion would
  catch any imbalance anyway).
- 42 skips and 4 deprecations in `composer test` are pre-existing on master
  (macOS/`pcntl` skips, Symfony header-name deprecations); the composer
  process exited 0, so no gate moved.
