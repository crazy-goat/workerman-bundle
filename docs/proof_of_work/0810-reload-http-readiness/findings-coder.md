# Findings — coder (#810)

## Obstacles

- **Guzzle exception taxonomy splits the flake's signature.** The issue's
  cURL 52/56 race arrives as *two different exception types*: Guzzle's
  `CurlFactory` (`src/Handler/CurlFactory.php:1082-1088, 1139-1141`) builds
  `ConnectException` for errno 28/7/52 (incl. `CURLE_GOT_NOTHING`) but a plain
  `RequestException` for everything else — cURL 56 "Recv failure" included.
  A first mental model of "retry `ConnectException` only" would have silently
  left the flake in place. The helper therefore catches both types and keys
  the retry decision on `getHandlerContext()['errno']` (allowlist 7, 28, 52,
  56) plus a `hasResponse()` guard. Recorded because any future reader will
  hit the same trap.

- **HTTP 200 success case not e2e-observable in mocks.** The retry-success
  mock test asserts queue exhaustion (`assertCount(0, $handler)`) and the
  timeout options reaching the client (`getLastOptions()`), but the real
  200-path is only exercised by the E2E test (and it passed 10/10 sequential
  reload runs locally, ~0.13 s each — see code-decision-1 for exact checks).

## Biggest problem

Making the flake-fix testable without introducing its own nondeterminism:
the failure being fixed appears only under a race, so the deterministic
coverage had to come from `MockHandler` sequencing against the same helper the
E2E test uses, including the "deadline exhausted but must not probe again"
case (queue-not-drained assertion in a `finally`). Deadline-0 works only
because `Wait::until` checks the deadline between probes (1-based probe
count), not after — verified against `src/Util/Wait.php:83-97` before relying
on it.

## Bugs / weak spots noticed (outside this issue's scope)

- **`tests/WorkermanCommandTest.php:45` (`testStopAndStartViaCli`), line 76:**
  the post-restart HTTP request is a single un-retried shot with **no
  `timeout`/`connect_timeout` option**. Same race shape as this issue's flake
  (a fresh `start -d` can accept TCP before the worker finishes booting), and
  an unbounded request duration. Suggested fix: reuse
  `assertHttpReadyAfterReload(new Client())` after `waitForPortUp` (the
  helper is client-parameterized for exactly this). Deliberately not done
  here — the issue scopes the change to the reload test and asks for no
  unrelated fixes.

- **`tests/WorkermanCommandTest.php:53` (`waitForPortDown` semantics):**
  `Wait::until` returns `false` when the condition never becomes true, and
  `waitForPortDown`'s condition becomes true when the port is *down*, so a
  `false` return actually means "port still up". That is used correctly here,
  but the polarity is inverted relative to `waitForPortUp` (true = desired
  state reached) — a future reader can easily "fix" it wrongly. Suggested
  fix: either document the inversion on `waitForPortDown`'s docblock or make
  both helpers return "observed desired state" consistently.

- **`tests/App/bootstrap.php:49-53` (`workerman_stop`):** shutdown stop uses
  `shell_exec` with no timeout or output capture; on a wedged master
  (FAQ-007's grpc/macOS shapes) the suite can hang at exit. Suggested fix:
  `proc_open` with a deadline + SIGKILL escalation, mirroring what
  `ProcessInspector`/`killOrphanedIntermediateFork()` already do in
  production code (out of scope here; noted for the retro).

- **`tests/WorkermanCommandTest.php:90` (original line, now removed):** the
  flake reproduced only on CI, and the old failure mode (single request, no
  timeouts) was shared by every other raw `$client->request()` call in this
  file — see the `testStopAndStartViaCli` bullet above; consolidating on the
  helper would remove the whole class of unbounded probes.

- **Pre-existing, not from this change:** `composer lint` reports
  `docs/helpers/faq.md` (409 lines over budget vs. 300) and
  `docs/helpers/decisions.md` (310 lines over budget) as kb-lint warnings
  (exit still 0). Not actionable by this branch (docs/helpers is single-writer
  and #826/#596 are blocked), but the budget warnings are real and will keep
  growing — the main session may want to promote or split entries.

## Test results

- `vendor/bin/phpunit --no-coverage tests/WorkermanCommandTest.php` — 11 tests,
  37 assertions, OK (was 7 tests before).
- 10/10 sequential real reload runs
  (`--filter '^.*::testReloadDoesNotBreakServer$'`), all OK in ~0.12–0.14 s.
- `composer lint` — clean (php-cs-fixer 0 fixable, phpstan level 8 OK, rector
  dry-run OK, kb-lint OK with the two pre-existing budget warnings,
  check-changelog OK, check-exception-usage OK).
- `COMPOSER_PROCESS_TIMEOUT=1800 composer test` — 2699 tests, 18294
  assertions, OK (exit 0); 4 deprecations + 42 skips pre-existing on master.
- Repeated real reload-test runs were executed strictly sequentially; the
  full suite was not run concurrently with any other daemon suite.
