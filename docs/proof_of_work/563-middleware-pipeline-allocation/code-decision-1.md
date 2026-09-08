# Code Decision 1 — Issue #563: Index-based middleware dispatcher

## Approach

Replaced the nested-closure pipeline composition with a single re-entrant
`MiddlewareDispatcher` object that walks the middleware array by index.

**Old design** (`getPipeline()`): built a chain of closures bottom-up by
`array_reverse`-ing the middleware array. Each composed layer, when invoked
at request time, allocated a fresh inner closure
`fn(Request $req) => $previous($req, $controller)` that captured the
per-request `$controller`. With M middlewares → M closure allocations per
request.

**New design**: `getPipeline()` caches a single arrow-function closure that
captures the middleware array (in registration order). At dispatch time it
instantiates one `MiddlewareDispatcher` object, passing it the middleware
array and the per-request controller callable. The dispatcher implements
`__invoke(Request): Response` — it is the `$next` callable that every
middleware receives. Each invocation advances an internal index, calls the
middleware at that index with `$this` as `$next`, and restores the index
via try/finally on return. When the index exceeds the array, the controller
is called.

Per-request allocations: **1 dispatcher object + 1 controller closure** —
independent of middleware count. The pipeline closure itself is cached and
reused across requests, as before.

## Why the dispatcher class (not a closure with self-reference)

A re-entrant closure needs to reference itself (`use (&$next)`), which
creates a reference cycle per request: the closure captures a reference to
itself. While per-request cycles are eventually collected by the GC, this
introduces GC pressure where the old design had none (the old closure chain
was a simple linked list freed by refcounting).

The `MiddlewareDispatcher` class avoids this entirely: it passes `$this` to
middlewares as `$next`, but `$this` is not stored on any field that
references back — it's just a parameter. When the dispatcher goes out of
scope (after the pipeline returns), refcounting frees it immediately. No
cycle, no GC involvement.

## Why the try/finally index restore

A middleware that calls `$next` more than once (not part of the contract,
but not explicitly forbidden) would dispatch the remaining chain from the
same position each time, matching the semantics of the old nested-closure
composition. Without the restore, the index would be permanently advanced
after the first call, and a second `$next` call would skip directly to the
controller.

## What was rejected

1. **Mutable per-request state on the handler** (the issue's "bind the
   controller once per request" alternative): would require storing the
   controller on `$this` or a per-request field, making the handler
   stateful and unsafe for concurrent reuse. The issue explicitly prefers
   the stateless approach.

2. **A closure-based re-entrant dispatcher**: works but creates a
   self-referencing closure cycle per request (see above). The class-based
   approach is cleaner and avoids GC pressure.

3. **Keeping the nested-closure composition and only fixing the docblock**:
   the issue says "at minimum" correct the docblock, but the acceptance
   criteria ask for flat per-middleware cost if restructured. The
   dispatcher is a small, clean change that delivers both.

## Uncertainties

- The `MiddlewareDispatcher` is marked `@internal` — it's an implementation
  detail of `HttpRequestHandler`. If external code ever needs to inspect or
  wrap the dispatch mechanism, this would need revisiting. Unlikely given
  the current API surface.

- The dispatcher's `$controller` field is typed `mixed` because
  `callable` is not a valid property type in PHP. The constructor accepts
  `callable` (enforced at call site) but stores it as `mixed`. PHPStan
  level 8 accepts this.
