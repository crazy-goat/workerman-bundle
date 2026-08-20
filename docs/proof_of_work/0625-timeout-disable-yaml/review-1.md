# Review — #625 (allow disabling connection/keepalive timeouts via YAML) — round 1

Branch: `feat/issue-625-allow-disabling-connection-timeout-keepa`
Commit: `66b06af` (vs `master`)

## Earlier findings

`findings-review.md` did not exist before this round — this is round 1.
The coder's own notes live in `findings-coder.md`; its open items are
revisited here:

- **Coder obstacle 1** (filtered `phpunit` shutdown fatal in
  `tests/App/bootstrap.php`): **not a real finding for this change** —
  pre-existing harness fragility. Reproduced neither this round nor
  during the coder's full `composer test` runs. Filtered runs of
  `ZeroTimeouts|ConfigurationTreeBuilder` and `RunnerTest`/`ServerWorkerTest`
  all exited cleanly (see Commands). Left as a pre-existing nit in
  `findings-coder.md` issue 3, not caused by this diff.
- **Coder obstacle 2** (`Worker::$logFile` / `$outputStream` empty in unit
  context): **handled** — the new `testConfigWithZeroTimeoutsDisablesSweeper`
  sets `Worker::$logFile` to a tmp file and opens a `php://memory` stream for
  `Worker::$outputStream` before invoking `onWorkerStart` (which calls
  `Worker::log` → `file_put_contents`). Mirrors `ServerWorkerTest::setUp`.
  See F-3 for the only residual (the stream is never `fclose`'d).
- **Coder obstacle 3** (`min(0)` rejects negatives while the runtime
  tolerates them): **not a real finding** — the config tree being stricter
  than the runtime for negatives is the safer direction and is documented in
  `code-decision-1.md`. See F-1 for the related *test* gap.
- **Coder issue 1** (stale "Timers are per-connection" bullet in
  `docs/security.md`): **fixed** — rewritten to "Timeouts share one
  worker-level sweeper…", consistent with DEC-003. Verified by grep: no
  remaining "per-connection" / "minimum of 1" claim in `docs/`, `README.md`,
  or `CHANGELOG.md`.
- **Coder issue 2** (README feature list does not advertise "0 disables"):
  **not a real finding** — the note lives in the config table + `security.md`
  + `info()` strings, which is the right place. No action.
- **Coder issue 4** (`Runner::createWorkers` `?? 120`/`?? 30` fallbacks are
  dead for the bundle path): **not a real finding** — intentional, keeps
  `Runner` usable with hand-built arrays (which `RunnerTest` does).

## KB entries read (tag-index match)

- `decisions.md` → **DEC-003** (`timers,long-running`): one worker-level
  sweeper supersedes per-connection timers. The diff is **consistent** —
  `min(0)` exposes the runtime's already-existing `0`-disables path, and the
  `security.md` bullet rewrite restates DEC-003. No violation.
- `faq.md` → **FAQ-013** (`tests,timers`): initialize `Workerman\Timer` with
  the test event loop before calling `onWorkerStart`. The new `RunnerTest`
  does `Timer::init($eventLoop)` before `$onWorkerStart($w)`. **Consistent.**
  FAQ-013's note that `Select::stop()` calls `deleteAllTimer()` does not
  apply here — the test asserts `getTimerCount()` *before* running the loop,
  and expects `0` (no timer armed), so there is no stop/run-for-N concern.

## Verdict

The change is **correct and low-risk**. `min(1)→min(0)` aligns the config
tree with the runtime's `0`-disables semantics already established by #555
(DEC-003). The three doc surfaces — README config table, `security.md`
Connection Timeouts + Security Considerations, and the `info()` strings —
together present a consistent story: `0` disables the bundle's timeout
enforcement, and with both timeouts `0` no sweeper is armed at all (neither
bundle nor Workerman's HTTP layer closes slow/idle connections on its own).
The `CHANGELOG.md` #555 Unreleased note was updated to drop the now-stale
"YAML configuration still enforces a minimum of 1 second" sentence.

The new `RunnerTest` flow-through test is robust: no socket binding
(`Worker::run()` is never called, so port `8085` cannot flake), `Worker`
static state is saved/restored, the tmp dir is cleaned in `finally`, and
`Timer::init` precedes `onWorkerStart` per FAQ-013. All affected suites pass
locally. **No high or medium findings.** Three low/nit items below, the most
actionable being F-1 (a missing reject-side test for the loosened bound).

## New findings

| ID | file:line | severity | description |
|----|-----------|----------|-------------|
| F-1 | tests/DependencyInjection/ConfigurationTreeBuilderTest.php (new `testConfiguredTreeAcceptsZeroTimeouts`, ~L129) | low | No test pins that **negative** timeout values are still rejected after `min(1)→min(0)`. The new test only confirms `0` is accepted; a future edit that drops `->min(0)` (or widens it) would let negatives pass silently into a runtime that treats them as "disabled", eroding the config-level guard with no test failure. A `expectException(InvalidConfigurationException::class)` test for `connection_timeout: -1` would match the existing `testConfiguredTreeValidatesRequiredServerName` pattern and catch that class. An automated check should catch this. |
| F-2 | tests/RunnerTest.php:461,520 | nit | `Timer::init($eventLoop)` binds `Workerman\Timer::$event` to the `Select` loop, but the `finally` only calls `Timer::delAll()` — `Timer::$event` is never restored to the saved state. `saveWorkerState`/`restoreWorkerState` covers `Worker::$globalEvent` but not `Timer::$event`, so Timer stays bound to a stopped `Select` loop for any later test that doesn't re-init. No failure observed (full `RunnerTest` + `ServerWorkerTest` suites pass), and this matches the existing pattern in `ServerWorkerTest`'s timer tests, so it is a latent state-leak rather than a flake today. |
| F-3 | tests/RunnerTest.php:471-472 | nit | The `fopen('php://memory', 'w')` stream assigned to `Worker::$outputStream` is never `fclose`'d; `restoreWorkerState` orphans it. Collected at EOT, harmless, but a `fclose` in `finally` (only when the saved state was `null`) would be tidier. |

## Candidate KB entries

- **Title:** Config-tree `->min()`/`->max()` bounds must be pinned by both an
  accept and a reject test
- **Tags:** `config`, `tests`
- **Trigger:** loosening or tightening a Symfony Config `->min()`/`->max()`
  constraint on a node whose value flows into runtime behavior.
- **Paragraph:** When changing a `->min(N)`/`->max(N)` bound on a config
  node, add one test asserting the new boundary value is accepted and one
  asserting the just-out-of-range value still raises
  `InvalidConfigurationException`. #625 loosened `connection_timeout`/
  `keepalive_timeout` from `min(1)` to `min(0)` and pinned the accept side
  (`0`) but not the reject side (`-1`); a later edit that drops `->min(0)`
  would let negatives pass silently into a runtime that treats them as
  "disabled," eroding the config-level guard with no test failure. The
  reject-side test should mirror the existing
  `testConfiguredTreeValidatesRequiredServerName` pattern.

## Gaps in validation / areas checked clean

- **Type correctness:** `->min(0)` on an `integerNode` is type-correct;
  `0` is a valid int and the runtime's `int $timeout` / `$timeout > 0`
  checks are type-safe. No float/string coercion introduced.
- **Error handling (RunnerTest fixture):** `Worker::$logFile` set to a tmp
  path (writable, created via `mkdir(..., 0700, true)`); `Worker::$outputStream`
  guarded with `=== null` check and `fopen === false` guard. `onWorkerStart`'s
  `Worker::log` and `configureHandler` both succeed against the mocks
  (`container->get` stubbed for `workerman.http_request_handler`; middlewares
  `[]` so no other `get` call). `StaticFileHandlerInterface` mock stubs
  `withStaticFileConfig`/`withRootDirectory` via `onlyMethods` — returns null,
  no expectation needed. Verified by a passing run.
- **PSR-12 / lint scope:** `composer lint` (php-cs-fixer, PHPStan L8, Rector)
  covers `src/` and `tests/`; not re-derived here. No style issue visible in
  the diff that would hide a correctness or maintainability problem.
- **Outdated docs:** grep across `docs/`, `README.md`, `CHANGELOG.md`,
  `src/` for "minimum of 1", "enforces a minimum", "per-connection" (in
  timeout context) — no stale claim remains for `connection_timeout`/
  `keepalive_timeout`. The remaining `->min(1)` calls in
  `ConfigurationTreeBuilder.php` (L49 `cache_warmup_timeout`, L131
  `body_size_cap`) are unrelated and still correct.
- **Flake risk of the flow-through test:** low. No socket bind, no real
  time-based assertion (the test never runs the event loop, so the
  FAQ-013 second-boundary / sweep-interval flake class does not apply).
  `uniqid()` tmp dir avoids cross-run collisions; `removeDir` is recursive
  and idempotent (`is_dir` guard). Worker static state fully restored.
- **Mixed-case (one 0, one > 0):** covered at the runtime layer by
  `ServerWorkerTest::testKeepaliveTimeoutZeroKeepsOnlySweeperTimer` (5, 0)
  and `testZeroTimeoutsDoNotArmSweeper` (0, 0); the new `RunnerTest` adds an
  integration-level both-zero path. No additional mixed-case config test
  needed — the tree passes values through unchanged.

## Commands run

| command | result | summary |
|---------|--------|---------|
| `php vendor/bin/phpunit --filter 'ZeroTimeouts\|ConfigurationTreeBuilder'` | passed | 14 tests, 49 assertions, no shutdown fatal |
| `php vendor/bin/phpunit --filter 'testConfigWithZeroTimeoutsDisablesSweeper' tests/RunnerTest.php` | passed | 1 test, 3 assertions |
| `php vendor/bin/phpunit tests/RunnerTest.php` | passed | 36 tests, 89 assertions — no state-leak into neighbours |
| `php vendor/bin/phpunit tests/ServerWorkerTest.php` | passed | 36 tests, 123 assertions — timer tests still green after the new RunnerTest |
