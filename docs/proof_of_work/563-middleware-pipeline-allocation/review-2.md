# Review round 2 — Issue #563: index-based MiddlewareDispatcher

**Branch:** `perf/issue-563-middleware-pipeline-allocates-one-closur` (round-2 commit 1602cbc)
**Files under review this round:** `tests/MiddlewareDispatcherTest.php` (new), `tests/HttpRequestHandlerTest.php`, `src/Middleware/MiddlewareInterface.php`, `src/Http/HttpRequestHandler.php` (docblocks only).

## 0. Trigger verification

`review-critical` still applies: cumulative diff touches `src/Http` and
exceeds 200 changed lines.

## 1. docs/helpers consultation

Same tag set as round 1 (`http`, `middleware`, `tests`, `closures`, `gc`,
`memory`, `phpstan`, `performance`). No new helpers entries were added since
round 1; all previously read entries re-checked against this round's diff:

- **FAQ-018 (long-running/state)** — new dispatcher tests comply (no static state).
- **FAQ-023 (closures/gc)** — no self-referencing closures added; the
  by-ref `$order` captures in the test file are test-local and short-lived.
- **FAQ-029 (phpstan/php84/version drift)** — **relevant in a new way**: the
  dev machine runs PHP 8.5 while the composer minimum is `^8.2`; FAQ-029's
  lesson ("CI runs legs/users install on other versions than your local")
  cuts both ways. This is exactly the trap hit by F-5 below.

No violations of *documented* decisions, but F-5 is a new instance of the
version-drift class FAQ-029 documents.

## 2. Round-1 findings — disposition

- **F-1 (low, dead `$handler404` in `testHandlerIsSafeForReuseAcrossTwoDifferentRequests`)**
  — **fixed.** Diff removes the `$kernel404`/`$controller404`/`$handler404`
  construction; the comment now correctly describes the second request as
  same-handler/different-path. The statelessness assertion (invocation
  count) is unchanged and still valid.
  (Note: the coder's findings-coder.md maps this as "F-3" — their labels
  are permuted relative to findings-review.md; substance matches.)
- **F-2 (medium, execution order not pinned against the shipped dispatcher)**
  — **fixed.** `tests/MiddlewareDispatcherTest.php` exercises
  `MiddlewareDispatcher` directly: `testMiddlewaresExecuteInRegistrationOrder`
  (['A','B','C'] for registration order), `testReversedRegistrationOrderProducesDifferentSequence`
  (contrast proving the order test is meaningful),
  `testControllerRunsAfterAllMiddlewares`. Flipping the index walk now fails
  a test. Residual (pre-existing, carried as nit): `MiddlewarePipelineTest`'s
  helper still re-implements the old nested-closure composition — the coder
  noted it as a future refactor; acceptable since the shipped path is now
  pinned directly.
- **F-3 (low, try/finally re-entrancy restore unpinned)**
  — **fixed.** `testDoubleNextCallDispatchesRemainingChainTwice` expects
  `['double-start','inner','controller','between','inner','controller','double-end']`.
  I hand-traced the mutation: without the `finally` restore the second
  `$next` call starts at index 2 (≥ count) and goes straight to the
  controller, yielding `['double-start','inner','controller','between','controller','double-end']`
  — assertion fails. The pin is real.
- **F-4 (nit, doc/test-name drift)**
  — **fixed.** Test renamed to `testInvokeMiddlewaresAllHeadersInResponse`→
  `testInvokeWithMultipleMiddlewaresAllHeadersInResponse` with corrected
  assertion messages; `MiddlewareInterface` and `HttpRequestHandler`
  docblocks now attribute `responseSentDirectly` to response strategies.
  Verified by grep: only `StreamedResponseStrategy.php:92,113` sets the
  flag — the new wording is accurate.

## 3. Verification performed this round

- `vendor/bin/phpunit --filter MiddlewareDispatcherTest --no-coverage`: **7 tests pass** (11 with the repo's FinalClass meta-tests), 15 assertions, OK.
- `vendor/bin/phpunit --filter "MiddlewareDispatcherTest|HttpRequestHandlerTest|MiddlewarePipelineTest|MiddlewareTest"`: 2 errors in `MiddlewareTest:52,81` — Guzzle connection errors because the live `tests/App` server is not running (the repo's `composer test` starts it first). **Environmental, not caused by this diff** (same failures occur on any commit without the server up).
- `vendor/bin/phpstan` (level 8): **clean** (255 files).
- `vendor/bin/php-cs-fixer fix --dry-run`: **clean**.
- PHP version semantics verified externally: `new readonly class` (anonymous readonly class) is **PHP 8.3+** (php.net manual; php-src GH-10377; PHP 8.3 UPGRADING: "Anonymous classes may now be marked as readonly"). On PHP 8.2 it is a *parse error*.

## 4. New findings

### F-5 — `new readonly class` is PHP 8.3+ syntax; CI has 8.2 legs → parse error
`tests/MiddlewareDispatcherTest.php:130` — the short-circuit test uses
`new readonly class implements MiddlewareInterface`. Anonymous readonly
classes were only allowed in PHP 8.3 (8.2's readonly-classes RFC forbade
them; GH-10377). The repo's composer minimum is `"php": "^8.2"` and
`.github/workflows/tests.yaml:29,113` run PHP **8.2** legs — on those legs
this file is a compile-time parse error and the whole suite fails.
Everything local passes because the dev machine runs PHP 8.5.10; phpstan,
cs-fixer and phpunit here cannot see it. The anonymous class declares no
properties, so `readonly` buys nothing — the fix is to delete the keyword.
**Severity: high** (breaks the minimum-version CI leg).
**Which check catches it:** the PHP 8.2 CI leg — but only after push. This
is the mirror image of FAQ-029's 8.4-deprecation trap (local PHP newer than
another supported target): new *syntax* (not just deprecations) must be
checked against the **minimum** supported version. A pre-push `php -l`
sweep or cs-fixer run under 8.2 (its own startup warning recommends this)
would catch the class locally.

### F-6 (nit) — carried observation, no action required this round
`tests/MiddlewarePipelineTest.php:122-133` — helper still duplicates the old
nested-closure composition. Now harmless (the shipped dispatcher is pinned
directly by `MiddlewareDispatcherTest`), but a future refactor should either
re-base it on `MiddlewareDispatcher` or add a comment that it intentionally
tests the middleware *contract*, not the dispatch mechanism.

## 5. Gate status

No gate lowered. Coverage floor, PHPStan level 8, cs-fixer, rector,
changelog check untouched and green (on PHP 8.5 — see F-5 for the 8.2 gap).

## Candidate knowledge-base entries

### Entry (new this round)
- **Title:** New PHP *syntax* must be checked against the minimum supported version — local PHP 8.5 hides 8.3-only constructs
- **Tags:** `php82`, `tests`, `ci`, `phpstan`, `lint`
- **Trigger:** using a recently-added PHP syntax feature (check "as of PHP 8.x" in the manual) while composer.json allows an older minor
- **Paragraph:** The dev machine runs PHP 8.5 while composer.json allows `^8.2` and CI runs 8.2 legs. `new readonly class` (anonymous readonly class) is PHP 8.3+ syntax and is a **parse error** on 8.2 — it shipped in `tests/MiddlewareDispatcherTest.php` (#563) with phpstan/cs-fixer/rector/phpunit all green locally, because every local tool ran on 8.5. Mirror image of FAQ-029 (which covers 8.4 deprecations invisible on older runtimes): for version-gated *syntax*, check against the minimum. Detection options: run php-cs-fixer under the minimum PHP (its startup warning already recommends this), a `php -l` sweep under 8.2, or a CI lint leg pinned to the lowest matrix version. Named `final readonly class` fixtures are fine on 8.2 — only the *anonymous* readonly form is 8.3+.

(Round-1 entries 1 and 2 stand; entry 1's last sentence about
`MiddlewarePipelineTest` remains accurate per F-6.)
