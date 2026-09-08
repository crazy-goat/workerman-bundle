# Review round 1 — Issue #563: index-based MiddlewareDispatcher

**Branch:** `perf/issue-563-middleware-pipeline-allocates-one-closur` (commit 8e9fbb7)
**Files under review:** `src/Http/MiddlewareDispatcher.php` (new), `src/Http/HttpRequestHandler.php`, `tests/HttpRequestHandlerTest.php`, `benchmarks/HttpRequestHandlerBench.php`, `CHANGELOG.md`

## 0. Trigger verification

`review-critical` applies on two criteria:

1. **`src/Http` touched** — `HttpRequestHandler.php` modified, `MiddlewareDispatcher.php` added.
2. **>200 changed lines** — 439 insertions / 23 deletions across the diff.

## 1. docs/helpers consultation

Tags matching the diff: `http`, `middleware`, `tests`, `benchmarks`, `phpbench`, `closures`, `gc`, `memory`, `long-running`, `state`, `phpstan`, `performance`.

Entries read and checked against the diff:

- **FAQ-023 (closures/gc/memory)** — mutual by-ref closure captures are reference cycles. The dispatcher passes `$this` as `$next` but never stores it on a back-referencing field; the dispatcher is created per request inside the cached pipeline closure and freed by refcounting when the pipeline returns. **Compliant.** (Residual note: a middleware that stores `$next` on itself would create a cycle dispatcher→middlewares→mw→next→dispatcher, but the old nested closures had the equivalent cycle and `MiddlewareInterface`'s docblock forbids per-request state on middleware instances. Not a regression.)
- **FAQ-028 (benchmarks/phpbench)** — the new `benchWithFiveMiddleware` is a fixed method, no `@ParamProviders`, so the phpbench 1.x array-argument pitfall does not apply. **Compliant.**
- **FAQ-018 (long-running/state)** — the handler remains stateless: the dispatcher's mutable `$index` is per-request, created and discarded inside the pipeline closure. The two new reuse tests pin this. **Compliant.**
- **FAQ-014 (tests/phpstan)** — n/a (no byte helpers).
- **DEC-002 (StreamedResponseStrategy is the only direct sender)** — no new response path added; `sendResponse()` flow untouched. **Compliant.**
- **DEC-013 (fail-open gates on security parsers)** — n/a, no parser gate touched.
- **DEC-014 (bounded static caches)** — n/a, no process-lifetime static cache added. The cached `$pipeline` closure is an instance property, bounded by construction.
- **FAQ-025 / DEC-005 / FAQ-001/002/004/027** — header parsing, trusted hosts, Content-Length, static-file rules: not touched by this diff.

No violations of documented decisions found.

## 2. Previous findings

Round 1: no `findings-review.md` existed before this round. The coder's own
`findings-coder.md` lists four improvement observations (misleading test name
`testInvokeMiddlewaresAppliedInReverseOrder`, `MiddlewarePipelineTest` helper
duplicating the old composition, bench parameterization, `MiddlewareInterface`
docblock on `responseSentDirectly`) — these were not claimed fixed and are
carried into findings F-2/F-4 below where they intersect this diff.

## 3. Verification performed

- `vendor/bin/phpstan` (level 8): **clean** (254 files).
- `vendor/bin/php-cs-fixer fix --dry-run`: **clean**.
- `vendor/bin/rector process --dry-run`: **clean**.
- `php bin/check-changelog.php`: **OK**.
- `vendor/bin/phpunit --filter HttpRequestHandlerTest`: **69 tests, 10123 assertions, OK**.
- `vendor/bin/phpunit --filter MiddlewarePipelineTest`: **12 tests, OK**.

### Behavioral trace (the sensitive parts)

- **Execution order**: `getPipeline()` captures `$this->middlewares` in
  registration order; `MiddlewareDispatcher::__invoke` walks index 0→N, so
  first registered = outermost = first executed. Matches the old
  `array_reverse` composition and the `MiddlewareInterface` FIFO contract.
- **Short-circuit from any position**: a middleware returning without calling
  `$next` simply never advances the chain; `finally` restores `$index`.
  `responseSentDirectly` handling lives in `sendResponse()` and is untouched;
  the flag is honored regardless of which layer set it.
- **Re-entrant `$next` (called twice from one middleware)**: traced by hand —
  index saved in `$i`, incremented, restored in `finally`; a second `$next`
  call re-dispatches from the same position, matching the old closure
  semantics exactly (old: each `$next` closure re-invoked `$previous` from
  its own layer). The code-decision claim is correct.
- **Statelessness across requests**: dispatcher + controller closure are
  allocated per request inside the cached pipeline closure; nothing
  per-request is stored on the handler. Pipeline invalidation on
  `withMiddlewares()`/`withRootDirectory()` is unchanged.
- **BC**: `MiddlewareDispatchInterface` and all public signatures unchanged;
  `MiddlewareDispatcher` is `@internal` and `final`.
- **Perf claim**: old path allocated M nested closures per dispatch (each
  layer created `fn($req) => $previous($req, $controller)` when invoked);
  new path allocates exactly 1 dispatcher + 1 controller closure regardless
  of M. Claim verified against the code; coder's micro-benchmark numbers
  (~0.12 → ~0.07 µs per middleware) are plausible for this shape of change.

## 4. New findings

### F-1 — Dead `$handler404` code makes the reuse test misleading
`tests/HttpRequestHandlerTest.php:1576-1579` — the test builds `$kernel404`,
`$controller404`, `$handler404` and comments "a 404 via a kernel that returns
404", but then dispatches the second request through `$this->handler` and
asserts `200`. The 404 handler is never invoked; the assertion message
("no state leak from the 404 handler we also created") describes work the
test does not do. The core statelessness assertion (invocation count) is
valid, but four lines of dead setup plus a wrong narrative will mislead the
next reader. **Severity: low.** Fix: either dispatch request 2 through
`$handler404` (which additionally proves a shared middleware instance is safe
across *two handlers*) or delete the dead lines. No automated check catches
unused local variables in tests (PHPStan level 8 does not flag them).

### F-2 — Middleware execution order is not pinned through the new dispatcher
`src/Http/MiddlewareDispatcher.php:58-71` — the one suite pinning order and
short-circuit, `tests/MiddlewarePipelineTest.php:122-133`
(`executeMiddlewarePipeline`), re-implements the **old** nested-closure
composition in the test helper, so it tests the middleware *contract* against
a copy of the pre-#563 internals, not the code that now ships. The only
handler-level "order" test, `testInvokeMiddlewaresAppliedInReverseOrder`
(`tests/HttpRequestHandlerTest.php:428`), asserts both headers appear but
never asserts sequence (already flagged in `findings-coder.md`). Net effect:
if a future edit flipped the index walk in `MiddlewareDispatcher` (innermost
first), no test would fail. **Severity: medium.** Fix: add a dedicated
`MiddlewareDispatcherTest` (order, short-circuit mid-chain, controller-last)
or re-implement the `MiddlewarePipelineTest` helper on top of
`MiddlewareDispatcher`. No automated check can catch this; it is a
coverage-of-guarantee gap (the mutation "reverse the walk" survives the
suite — mutation testing would).

### F-3 — Re-entrant double-`$next` semantics are designed but unpinned
`src/Http/MiddlewareDispatcher.php:64-70` — the try/finally index restore
exists solely so a middleware calling `$next` twice re-dispatches from the
same position (parity with the old composition, per the class docblock and
code-decision-1). Deleting the try/finally passes the entire suite, so the
parity claim could silently rot. **Severity: low** (double-`$next` is
explicitly "not part of the contract"). Fix: one test invoking `$next` twice
and asserting the downstream middleware ran twice.

### F-4 (nit, carried over from coder notes) — Docblock/test-name inaccuracies in the blast radius
- `tests/HttpRequestHandlerTest.php:428`: name says "ReverseOrder", test
  asserts no order. Now actively confusing because "reverse" referred to the
  removed `array_reverse` composition. **nit.**
- `src/Middleware/MiddlewareInterface.php:22-24` and
  `src/Http/HttpRequestHandler.php:52-54`: "a middleware that sets
  responseSentDirectly" — no shipped middleware does; the flag is set by
  `StreamedResponseStrategy` from within the controller. Pre-existing, not
  introduced here. **nit.**

## 5. Gate status

No gate lowered (coverage floor 80 %, PHPStan level 8, cs-fixer, rector,
changelog check all intact and green).

## Candidate knowledge-base entries

### Entry 1
- **Title:** Middleware dispatch is index-based (`MiddlewareDispatcher`); do not revert to nested closures
- **Tags:** `middleware`, `http`, `performance`, `closures`
- **Trigger:** changing `HttpRequestHandler::getPipeline()` or the middleware dispatch mechanism
- **Paragraph:** Since #563 the pipeline closure captures the middleware array in registration order and, per request, instantiates one `MiddlewareDispatcher` that walks it by index, passing `$this` as `$next`. Per-request allocations are 1 dispatcher + 1 controller closure, independent of middleware count; the old nested-closure composition allocated one closure per layer per request. The dispatcher restores `$index` via try/finally so a middleware calling `$next` twice re-dispatches from the same position — parity with the old semantics. Beware: `tests/MiddlewarePipelineTest`'s helper still re-implements the old composition (tests the contract, not the shipped dispatcher).

### Entry 2
- **Title:** Re-entrant dispatch: pass `$this` from an `__invoke` class, never a self-capturing closure
- **Tags:** `closures`, `gc`, `memory`, `performance`
- **Trigger:** writing a re-entrant/self-referencing closure for per-request dispatch in a long-lived worker
- **Paragraph:** A closure made re-entrant via `use (&$self)` is a reference cycle per request: refcounting cannot free it, so it accumulates until the cycle collector runs (unbounded under `gc_disable()`, cf. FAQ-023). A tiny `final` class with `__invoke` that passes `$this` as the continuation has no cycle — `$this` is a parameter, not a stored back-reference — and is freed by refcounting when the dispatch returns. Pattern: `MiddlewareDispatcher` (#563).
