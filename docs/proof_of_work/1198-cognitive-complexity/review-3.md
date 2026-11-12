# #1198 stage 2: review round 3 (review-critical)

Scope: `git diff origin/master...HEAD` on `refactor/issue-1198-cognitive-complexity-40` at `4f47c55`. Changed files: `src/Worker/ServerWorker.php`, `bin/kb-lint.php`, `tests/ServerWorkerTest.php`, `phpstan.neon.dist`, `tests/CognitiveComplexityGateTest.php`, `CONTRIBUTING.md`, `CHANGELOG.md`, and the coder's proof-of-work files.

**Verdict: no blocking findings.** Both refactors preserve behaviour, and I verified that independently. There are two small test-strength findings (R3-1 low, R3-2 nit). Neither blocks merging stage 2.

## Trigger (step 0)

Two of the review-critical criteria apply:

- **More than 200 changed lines.** The diff is 600 insertions and 225 deletions.
- **Process supervision.** `ServerWorker` wires the Workerman child-process callbacks: `onWorkerStart` with the restart backoff `beforeStart`, `onWorkerReload` with `forget`, the idle-connection sweeper timer and the per-connection `errorHandler`.

`src/Http` and the public interfaces are not touched.

## Knowledge base (step 1)

I read only the entries whose tags match the diff:

- **FAQ-023 (closures, gc).** The `errorHandler` closure captures `$connection` and is stored on that same connection. That is the same cycle as before; it is not new, and no closure now captures itself or a sibling by reference.
- **FAQ-013 (tests, timers).** The new sweeper test runs the loop for 2.2 s at a 1 s interval and uses the existing helper that sets up the timer, as the entry asks.
- **DEC-003 (timers).** There is still one persistent sweeper per worker, still based on `lastActivity` and `requestCompleted`. The decision is respected.
- **FAQ-031, FAQ-014, FAQ-029, DEC-008, DEC-020.** Read; none is violated.

I found no violation of a documented decision.

## Earlier findings (step 2)

All earlier rows belong to stage 1 (rounds 1 and 2):

- R1-1: **fixed**, as recorded in round 2.
- R1-2: **fixed**, as recorded in round 2.
- R2-1: **fixed**. `CognitiveComplexityGateTest` still pins the `ignoreErrors` list and forbids `excludePaths`, and lint passes with the new limit.
- R2-2: **fixed**. Unchanged by this diff.
- R2-3: **still present** (optional nit). `tests/PollingMonitorWatcherTest.php` is not touched.

## 1. `ServerWorker` (old and new compared side by side)

| Invariant | Old | New | Same? |
|---|---|---|---|
| Callback assignment order | `onClose`, `onConnect`, `onWorkerReload`, `onWorkerStart` | same, `:76-81` | yes |
| `onClose` | non-static closure literal in `__construct` | unchanged | yes |
| `onConnect` binding and captures | non-static, bound to `$this`, `use ($bodySizeCap)` | returned by the instance method `connectHandler()`, so still bound, `use ($bodySizeCap)` (`:86`) | yes; the static variable keeps the name `bodySizeCap` |
| `errorHandler` | non-static closure created inside `onConnect`, `use ($connection)`, so bound | built by `connectionErrorHandler($connection)` on each connect, bound, `use ($connection)` | yes; same message, same `close()` |
| `onWorkerReload` | `forget(sprintf('server:%s:%s:%d', name, (string)(listen ?? ''), $worker->id))` | `forget(restartBackoffKey(...))`, same format and arguments | yes |
| `onWorkerStart` captures | `$connectionTimeout, $kernelFactory, $keepaliveTimeout, $serverConfig` | same four, `:128` | yes |
| `onWorkerStart` step order | `requestClass`, arm the sweeper if a timeout > 0, compute `rootDir`, `beforeStart(key)`, log, create and boot the kernel, `configureHandler`, `onMessage` | same, `:129-149` | yes |
| Sweeper timer | interval `max(1, intdiv(min(>0 timeouts), 4))`, `static` closure, persistent (`[], true`) | same, `:184-198` | yes |
| Sweeper per connection | loop body with `continue` | `private static sweepConnection()` with `return` in the same branch order; `$now` is still taken once per tick | yes |
| `onMessage` | `try { handler } finally { resetHeaders }`, then mark `lastActivity` and `requestCompleted` | same, in `messageHandler()`, bound, `use ($handler)` | yes |
| Exceptions | no catch except the `finally` | no `try`/`catch` added | yes |

Notes:

- **`self::sweepConnection()` from a static closure.** The closure is declared in class scope, so `self::` resolves to `ServerWorker` and the private static method is reachable. The closure still captures no `$this`.
- **The native `?int` on `connectHandler()`.** The old code passed `$serverConfig['body_size_cap'] ?? null` untyped, and with `strict_types` a non-int now fails when the worker is constructed rather than on the first connection. The config tree declares `integerNode('body_size_cap')->min(1)->defaultNull()`, and the old code's assignment to `TcpConnection::$maxPackageSize` (`public int`) would also have thrown under `strict_types`. Valid configurations behave the same, so this is an observation, not a finding.
- **`readonly` class.** No new properties were added. The helpers are methods, so `readonly` is not affected.

### Mutation check of the new and strengthened tests

I made each change in a throwaway linked worktree with its own `composer install`, so the autoloader pointed at the mutated `src/`.

| Mutant | Test | Result |
|---|---|---|
| M1: remove the `!is_int($lastActivity)` early return | `testSweeperLeavesConnectionsWithoutContextOrLastActivityOpen` | **killed**: status 16 instead of 2 |
| M2: remove the `!$connection->context instanceof \stdClass` early return | same test | **survives** (R3-1) |
| M3: remove `$connection->maxPackageSize = $bodySizeCap` | `testOnConnectSetsBodySizeCap` | **killed**: 1048576 instead of 8192 |
| M4: remove the `armIdleConnectionSweeper()` call | the new test alone | **survives** (R3-2); the other sweeper and timeout tests kill it |

Why M2 survives: `TcpConnection::$context` is `?stdClass`, so the only value that is not a `stdClass` is `null`. With a `null` context, the `?->` and `??` reads in the guard-free path all fall back, `getSendBufferQueueSize()` is 0, and `$lastActivity` is `null`. The `is_int` guard then returns before anything is written to the context. The `instanceof` guard only matters when the send buffer is not empty, because then `$connection->context->lastBytesWritten = …` would throw. The test therefore executes that statement but does not check what it does, while code-decision-3 says the test checks the skip.

## 2. `bin/kb-lint.php`: differential run

I compared `origin/master:bin/kb-lint.php` with HEAD as copies run from the same place, using a harness of my own that is not committed.

- **Inputs:**
  - 300 knowledge-base roots, made from the real `faq.md` and `decisions.md` with 0–25 random line operations each. The operations insert or replace lines with headings `#` to `####`, `###nospace`, indented headings, fences, index start and end markers, `## Tag index`, valid, malformed, duplicate-key, `=x`, bare-key, unterminated-quote and `"-->"` front matter, promoted entries, non-ASCII text and a lone `\r`. Some files use CRLF, some have no trailing newline, and some roots miss a file.
  - Edge roots: an empty file, `"\n"` only, a heading only, an unclosed index, the real files, and a root without `docs/helpers`.
- **Modes:** default, `--json`, `--fix` and `--fix --json`, each on a fresh copy of the root.
- **Compared:** stdout, stderr and the exit code, with the root path normalised, and `diff -r` of the tree after the run.
- **Result:** 1,224 runs, **0 differences**.
- **What the corpus reached:** every parser and validator message, including the overlap/duplicate warnings and the stale report. The exit codes were 0 for three roots and 1 for the rest, and `--fix` really rewrote trees.
- **Directed case:** one more root covered unknown key, bad date, uppercase, empty and leading-dash tags, a repeated tag, negative hits, unknown status, a bad id prefix, a promoted entry without a gate and over-long, a duplicate id, a quoted value containing `=`, and a fenced index-end marker. Output and the `--fix` tree were identical in all three modes.

Code reading agrees with the run:

- `readQuotedFrontMatterValue()`'s `strpos` matches the old scan loop.
- `$pair['error'] ?? frontMatterPairError(...)` keeps the old check order: malformed or unterminated, then duplicate, then `-->`.
- When the heading regex does not match, the parser still keeps the current entry open, as before.
- The integer index `currentEntry` replaces the reference alias. That also removes the old leftover reference on the last entry's `body`, which had no visible effect.
- `withoutFrontMatterLine` through `array_map` keeps the list keys.

The class inside a script is acceptable: the script only ever runs as a subprocess, and `kb-lint` is now at or below 13 per function.

## 3. `tests/ServerWorkerTest.php`

The diff only adds lines; no existing assertion was removed.

- `testOnConnectSetsBodySizeCap` now calls the callback and checks that the default value (1048576 on an instance created without its constructor) became 8192. M3 shows the check is real.
- The new sweeper test is meaningful for the `lastActivity` half (M1). See R3-1 and R3-2 for the rest.

## 4. The number 40 is the same everywhere

| Place | Value |
|---|---|
| `phpstan.neon.dist:7` | `function: 40` (`class: 150` unchanged) |
| `tests/CognitiveComplexityGateTest.php:18` | `FUNCTION_LIMIT_CEILING = 40` |
| `CONTRIBUTING.md:320` | "currently 40 per function or method and 150 per class" |
| `CHANGELOG.md` | the one existing `(#1198)` line, `[Unreleased] → Changed`, says "40 per function or method and 150 per class" |

`bin/check-changelog.php` passes. The class ceiling was not raised, so no gate was lowered.

## Checks run

- `LINT_SKIP_AUDIT=1 composer lint`: **all checks passed**. That covers composer validate, PHP-CS-Fixer, PHPStan level 8 with the complexity limit 40, Rector, kb-lint, changelog, exception usage, upgrade exceptions, test annotations, shellcheck and hadolint.
- **`composer test` in the main checkout (default ports): not valid.**
  - Result: 32 errors and 14 failures, all `cURL error 7: Failed to connect to 127.0.0.1:8888` or follow-on failures in daemon-backed tests: `RequestParametersTest`, `ResponseTest`, `ProcessTest`, `MiddlewareDispatchContractTest`, `WorkermanCommandTest`.
  - Cause: while it ran, a second `vendor/bin/phpunit` that I did not start ran in the same checkout on the same default ports, and it took the shared test daemon down. This is environmental.
- **`composer test` in a clean detached worktree of `4f47c55`** with `WMB_PORT_HTTP=28888 WMB_PORT_SECOND=29999 WMB_PORT_THIRD=29991`:
  - 3048 tests, 20107 assertions, 1 failure, 47 skipped.
  - The only failure is `RestartBackoffTest::testStopSignalEndsTheWaitEarly`, the known FAQ-007 host failure.
- `vendor/bin/phpunit tests/BinDirectoryTest.php tests/MarkdownLinkTest.php`, run after writing this file and the findings file: 142 tests, 1279 assertions, OK, with 1 environmental skip (`127.0.0.2` is not available on macOS). Both files are untracked until the maintainer commits them, so `git ls-files` does not list them yet. I grepped both files for the strings `testNoTrackedDocMentionsTheRemovedProcessTooling` forbids, and none occurs.

## New findings

- **R3-1 (low).** `tests/ServerWorkerTest.php:1251-1278`: the `null`-context half of `testSweeperLeavesConnectionsWithoutContextOrLastActivityOpen` cannot fail when the `instanceof \stdClass` guard is removed (M2 survives), because the later `is_int` guard already returns for an empty send buffer. code-decision-3 says the skip is now checked. It is executed, but nothing checks what it does.
  - **Fix:** give the `null`-context connection a non-empty send buffer, for example by writing to it after pausing the peer, or by setting `sendBuffer` through reflection, so the guard-free path would throw "Attempt to assign property on null". Alternatively, drop the claim.
  - **Check that would catch it:** mutation testing (Infection), which the repository does not run. A line-coverage gate cannot see this.
- **R3-2 (nit).** The same test has no positive control: removing the sweeper call (M4) leaves it green. Add a third connection whose `lastActivity` has expired and assert that it is closed. Then "stays open" is proven to be a skip and not a sweeper that never ran. The neighbouring timeout tests cover the arming, so this does not block.

## Candidate knowledge-base entry (for the maintainer)

- **Title:** a characterisation test must kill the mutant, not only cover the line
- **Tags:** tests, refactor
- **Trigger:** writing a test before a refactor to pin a guard or skip branch
- **Paragraph:** When a test is meant to lock a branch in place before a refactor, remove the branch and run the test. Coverage only proves the line ran. A guard that is redundant for the input you chose, such as an `instanceof` guard behind a later `is_int` check on a `null` context, is an equivalent mutant for that input, so the test passes either way. Pick inputs that reach the code the guard protects (here, a non-empty send buffer), and add a positive control showing that the mechanism under test actually ran (#1198 R3-1 and R3-2).
- **Better as a gate?** An Infection run scoped to the changed files would remove the need for this entry.
