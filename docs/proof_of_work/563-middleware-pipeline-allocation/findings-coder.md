# Findings — Issue #563: Middleware pipeline allocation

## Biggest problem

The core challenge was designing a re-entrant dispatcher that is O(1)
allocations per request **without introducing a GC cycle**. A naïve
closure-based re-entrant dispatcher (`use (&$next)`) creates a
self-referencing closure cycle every request. While per-request cycles are
collected by the GC, this trades closure allocation pressure for GC cycle
pressure — potentially worse in a high-throughput worker where the GC may
not run frequently enough. The solution (a dedicated `MiddlewareDispatcher`
class that passes `$this` as `$next` without storing it on any back-referencing
field) avoids the cycle entirely: refcounting frees the dispatcher
immediately when the pipeline returns. This insight came from FAQ-023
(closures that reference each other by reference cannot be freed by
refcounting).

## Benchmark results

### Isolated dispatch (pass-through middleware, no Symfony overhead)

500k reps, PHP 8.5.10, no opcache, no xdebug:

```
MW count   Old us/op   New us/op   Old per-MW   New per-MW
0          0.098       0.189       —            —
1          0.218       0.267       0.120        0.077
3          0.475       0.418       0.128        0.076
5          0.700       0.558       0.113        0.070
10         1.291       0.905       0.118        0.069
```

Per-middleware marginal cost: **~0.12 us → ~0.07 us** (42% reduction).
Fixed cost: +0.09 us (dispatcher object creation). Crossover at ~2 middlewares.

### Full __invoke path (with Symfony kernel, phpbench)

1000 revs, 5 iterations, PHP 8.5.10:

```
                          Before      After
benchNoMiddleware         11.4 us     11.4 us
benchWithMiddleware (3)   13.0 us     13.1 us
benchWithFiveMiddleware   —           13.8 us
```

The full-path numbers are dominated by Symfony kernel overhead (~11 us),
so the dispatch improvement is a small fraction. The 3→5 middleware delta
is 0.7 us (0.35 us/middleware) vs the old ~0.12 us/middleware for dispatch
alone — the difference is the middleware's own work (setHeader).

## Discovered bugs / places to improve

### 1. `testInvokeMiddlewaresAppliedInReverseOrder` — misleading test name
**File:** `tests/HttpRequestHandlerTest.php:428-449`
**Issue:** The test is named "appliedInReverseOrder" but actually tests that
both middlewares' headers appear in the response. It does not verify
execution order at all — both headers are set independently. The name
"reverse order" likely refers to the old `array_reverse` composition
internals, which is no longer relevant with the index-based dispatcher.
**Suggested fix:** Rename to `testInvokeMiddlewaresAllHeadersInResponse` or
add an explicit order-tracking test (like `MiddlewarePipelineTest` does).

### 2. `MiddlewarePipelineTest::executeMiddlewarePipeline` — duplicates the old composition logic
**File:** `tests/MiddlewarePipelineTest.php:122-133`
**Issue:** This test helper manually replicates the old nested-closure
composition (`array_reverse` + `fn($input) => $middleware($input, $next)`),
so it tests the composition pattern, not the handler's actual dispatch.
With the new `MiddlewareDispatcher`, this test still passes because it
tests the middleware *contract* (order, short-circuit, propagation) not the
dispatch mechanism — but the helper code is now a divergent copy of the old
internals.
**Suggested fix:** Consider refactoring this helper to use
`MiddlewareDispatcher` directly, or add a note that it intentionally tests
the contract independently of the handler's dispatch implementation.

### 3. `HttpRequestHandlerBench` — no parameterized middleware count
**File:** `benchmarks/HttpRequestHandlerBench.php`
**Issue:** The bench now has 3 fixed methods (0, 3, 5 middlewares) instead
of using `@ParamProviders` for a parameterized run. FAQ-028 documents that
phpbench 1.x `@ParamProviders` sets arrive as one `array` argument, which
makes parameterized benches harder to identify in the aggregate report.
**Suggested fix:** The fixed-method approach is pragmatic given the
FAQ-028 limitation. If more data points are needed, add more methods rather
than `@ParamProviders`.

### 4. `MiddlewareInterface` docblock — `responseSentDirectly` not set by any middleware
**File:** `src/Middleware/MiddlewareInterface.php:22-24`
**Issue:** The docblock says "Set `$connection->context->responseSentDirectly
= true` to skip the automatic response send step" but no middleware in the
codebase actually does this — it's only set by `StreamedResponseStrategy`
(inside `SymfonyController`). The middleware contract documents a feature
no middleware uses. This is not a bug, just a documentation inaccuracy: a
middleware *could* set it, but none does.
**Suggested fix:** Clarify that the flag is typically set by response
strategies (called from within the controller), not by middleware directly,
though middleware *may* set it if needed.

## Candidate docs/helpers entries

### Entry 1
**Title:** Index-based middleware dispatch avoids per-request closure allocation
**Tags:** `middleware`, `performance`, `closures`, `memory`
**Trigger:** "changing HttpRequestHandler::getPipeline or the middleware dispatch mechanism"
**Paragraph:** The middleware pipeline is dispatched by a single
`MiddlewareDispatcher` object that walks the middleware array by index,
not by a nested-closure chain. The old composition (pre-#563) allocated one
closure per middleware layer per request because each layer's inner closure
captured the per-request controller. The dispatcher avoids this by carrying
the controller as a field and passing `$this` as `$next` to every
middleware, so per-request allocations are 1 dispatcher + 1 controller
closure regardless of middleware count. The dispatcher restores its index
via try/finally so re-entrant `$next` calls (calling `$next` twice from one
middleware) dispatch from the correct position. Do not revert to
nested-closure composition — it reintroduces the O(M) closure allocation
churn documented in #563.

### Entry 2
**Title:** Re-entrant closures that capture themselves by reference create per-request GC cycles
**Tags:** `closures`, `gc`, `memory`, `performance`
**Trigger:** "writing a re-entrant closure with use(&$self) for per-request dispatch"
**Paragraph:** A closure that captures itself by reference (`use (&$next)`)
to achieve re-entrancy creates a reference cycle that refcounting cannot
collect — only the GC cycle collector reclaims it. While per-request cycles
are eventually collected, they accumulate until the GC runs, trading
allocation savings for GC pressure. For per-request re-entrant dispatch,
prefer a dedicated class with `__invoke` that passes `$this` as the
continuation callable — no cycle, immediate refcount-based cleanup. This is
the dispatcher pattern used by `MiddlewareDispatcher` (issue #563). See
also FAQ-023 for the long-lived cycle variant.

## Round 1 review fixes

Addressed all four findings from `findings-review.md`:

- **F-1 (medium):** Created `tests/MiddlewareDispatcherTest.php` with 7
  tests pinning the shipped `MiddlewareDispatcher` directly: registration
  order, reversed-order contrast, controller-as-innermost, zero-middlewares,
  short-circuit, and fresh-instance isolation. Flipping the index walk
  would fail `testMiddlewaresExecuteInRegistrationOrder`.
- **F-2 (low):** Added
  `testDoubleNextCallDispatchesRemainingChainTwice` in the same file —
  verified that removing the try/finally restore causes this test to fail
  (second `$next` call skips `inner`, goes directly to `controller`).
- **F-3 (low):** Removed the dead `$kernel404`/`$controller404`/`$handler404`
  construction from `testHandlerIsSafeForReuseAcrossTwoDifferentRequests`
  and corrected the comment.
- **F-4 (nit):** Renamed `testInvokeMiddlewaresAppliedInReverseOrder` →
  `testInvokeWithMultipleMiddlewaresAllHeadersInResponse`; clarified
  `MiddlewareInterface` and `HttpRequestHandler` docblocks to state
  `responseSentDirectly` is set by response strategies
  (StreamedResponseStrategy), not by middleware directly.

### New observations during review fixes

- **`tests/MiddlewarePipelineTest.php:122-133`** — The
  `executeMiddlewarePipeline` helper still re-implements the old
  nested-closure composition. It tests the middleware *contract*
  (order, short-circuit, exception propagation) independently of the
  handler's dispatch mechanism, which is valuable — but it's now a
  divergent copy of the old internals. Not changed in this round
  (pre-existing, not introduced by #563), but noted for a future
  refactor: consider re-basing the helper on `MiddlewareDispatcher`
  or documenting it as an intentional contract-only test.

- **`Workerman\Protocols\Http\Response::rawBody()` returns `string`**
  — Rector flagged a redundant `(string)` cast in the new test.
  `rawBody()` is already typed `string` in the vendored Workerman
  source, so the cast is unnecessary. Other tests in the suite may
  have the same redundant cast pattern.

## Round 2 review fixes

- **F-5 (high):** `tests/MiddlewareDispatcherTest.php:130` used
  `new readonly class` — anonymous readonly classes are PHP 8.3+ syntax,
  a parse error on PHP 8.2 which `composer.json` allows (`^8.2`) and CI
  runs. Dropped `readonly` from the anonymous class (it has no
  properties, so `readonly` was decorative). Grepped all files added in
  this branch for other 8.3+-only syntax (anonymous readonly classes,
  typed class constants, `#[Override]` attribute) — no other occurrences
  found.

### Deferred: MiddlewarePipelineTest helper

The `executeMiddlewarePipeline` helper in
`tests/MiddlewarePipelineTest.php:122-133` re-implements the old
nested-closure composition. It is a pre-existing nit (not introduced by
#563) and is explicitly **deferred** — not changed in this PR. The
helper tests the middleware *contract* (order, short-circuit, exception
propagation) independently of the handler's dispatch mechanism, which
has standalone value. Re-basing it on `MiddlewareDispatcher` would
couple the contract test to the implementation under test, reducing its
independence.
