# Findings (coder) — issue #621

## Biggest problem faced

The call chain for `convert()` does **not** flow through
`HttpRequestHandler` — it flows through `SymfonyController::__invoke()`, which
is the sole caller of `ResponseConverter::convert()`. The issue's "Where"
section points at `HttpRequestHandler.php:145-152` (the early return on
`responseSentDirectly`), which correctly identifies *why* the central stamping
never reaches the streamed path, but the fix cannot live in the handler: the
handler never calls `convert()`. Threading `$shouldClose` from the handler
would require a new handler → controller argument, widening the controller's
invocation contract. Instead the bool is computed in `SymfonyController` from
the same `Request` it already holds, mirroring
`HttpRequestHandler::shouldCloseConnection()` exactly. This keeps the change
local to the strategy-dispatch chain and avoids touching the handler's
`__invoke` signature.

A secondary wrinkle: the backward-compatibility pattern in this codebase is
explicit — the base `ResponseConverterStrategyInterface` is kept stable for
external/custom strategies, and request context is added via the opt-in
`RequestMethodAwareResponseConverterStrategyInterface` (issue #683). Adding the
bool to the base interface would break that contract; adding it as a trailing
defaulted parameter on the method-aware interface preserves it. Both
`StreamedResponseStrategy` and `BinaryFileResponseStrategy` implement the
method-aware interface, so both signatures were updated (BinaryFile ignores the
flag — its responses are not directly sent, so the handler's central stamping
still applies).

## Discovered bugs / places to improve

### 1. `SymfonyController` and `HttpRequestHandler` duplicate the close-intent check

`src/Middleware/SymfonyController.php` (new, issue #621) and
`src/Http/HttpRequestHandler.php` (`shouldCloseConnection()`, ~line 175) now
contain the same two-line computation:

```php
$shouldClose = $request->protocolVersion() === '1.0'
    || strcasecmp((string) $request->header('Connection', ''), 'close') === 0;
```

If the close-intent rule ever changes (e.g. honouring `Connection: keep-alive`
on HTTP/1.1 explicitly, or a future protocol version), both sites must be
updated in lockstep or they will diverge silently — the handler decides whether
the socket closes, the controller decides whether the streamed head echoes
`Connection: close`, and a mismatch reproduces this exact issue.

**Suggested fix:** extract a single static helper, e.g.
`Http\ConnectionIntent::shouldClose(Request $request): bool`, and call it from
both sites. Low priority (the rule is stable and RFC-defined), but the
duplication is a latent drift hazard.

### 2. `DefaultResponseStrategy::convert()` ignores `$protocolVersion` — and now `$shouldClose` is unavailable to it

`src/Http/Response/Strategy/DefaultResponseStrategy.php:19` — the strategy
implements only the base `ResponseConverterStrategyInterface`, so it never
receives `$shouldClose`. This is fine *today* because its responses are not
directly sent (the handler's central stamping applies). But the comment at
line 21 ("HttpRequestHandler::sendResponse() stamps the request's protocol
version centrally before encoding") documents an implicit contract that is
invisible from the strategy's signature. A future strategy author copying
`DefaultResponseStrategy` as a template for a directly-sent response would
have no signal that they need the method-aware interface to receive
`$shouldClose`.

**Suggested fix:** add a one-line note to the base interface's `convert()`
PHPDoc stating that strategies which send the response directly (bypassing
`HttpRequestHandler::sendResponse()`) must implement
`RequestMethodAwareResponseConverterStrategyInterface` to receive
`$shouldClose` and own the `Connection` header themselves. Documentation only.

### 3. `tests/HttpRequestHandlerTest` has no streamed-response integration path

`tests/HttpRequestHandlerTest.php` constructs its `ResponseConverter` with
`[new DefaultResponseStrategy()]` only (setUp, ~line 152). There is no test
that drives a `StreamedResponse` through the full
`handler → controller → converter → strategy` chain, so the
`$shouldClose` threading added here is covered only at the strategy unit level
and by the handler's separate `shouldCloseConnection()` tests. The two are not
asserted together.

**Suggested fix:** add an `HttpRequestHandlerTest` case that wires
`StreamedResponseStrategy` into the converter and a kernel returning a
`StreamedResponse`, then asserts the wire bytes for the HTTP/1.1
`Connection: close` case. This would have caught issue #621 at the
integration level. Medium priority — the unit coverage is solid, but the
contract between controller-computed `$shouldClose` and handler
`shouldCloseConnection()` is currently untested as a pair.

### 4. (Out of scope, pre-existing) `kb-lint` budget warning on `docs/helpers/faq.md`

`composer lint` emits: `kb-lint: warning: docs/helpers/faq.md: 376 lines
(index excluded) is over the 300-line budget — promote or drop entries`.
This is pre-existing and unrelated to this issue, but it will keep flagging
on every lint run until entries are promoted to decisions/faq-2 or dropped.
Not addressed here (out of scope; helpers are retro-only).

### 5. (Out of scope, observation) `ResponseConverter::convert()` signature now has two trailing defaulted booleans

`src/Http/Response/ResponseConverter.php:36` —
`convert(..., string $requestMethod, bool $shouldClose = false)`. The
`$requestMethod` is required (no default) while `$shouldClose` defaults.
Callers that pass `$shouldClose` must also pass `$requestMethod` positionally.
This is fine for the single internal caller, but a future caller wanting only
`$shouldClose` would have to pass the method explicitly. Not a bug — just
noting the positional coupling. A named-arguments call site would avoid it,
but the codebase does not use named arguments for `convert()`.
