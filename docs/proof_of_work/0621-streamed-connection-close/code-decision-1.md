# Code decision #1 — issue #621: echo `Connection: close` on streamed HTTP/1.1 responses

## Approach taken

The root cause is structural: `HttpRequestHandler::sendResponse()` applies the
central `withProtocolVersion()` + `Connection: close` stamping, but
**early-returns** for directly-sent responses (`responseSentDirectly`), so the
stamping can never reach the streamed path. The strategy receives only
`string $protocolVersion`, not the request connection intent.

The fix threads the request's connection intent through the existing strategy
dispatch chain as a new trailing parameter, and makes
`StreamedResponseStrategy` own the `Connection` header on streamed heads.

### 1. New `bool $shouldClose` parameter (trailing, default `false`)

Added `bool $shouldClose = false` to
`RequestMethodAwareResponseConverterStrategyInterface::convert()` — the opt-in
extension of the base `ResponseConverterStrategyInterface` introduced for HEAD
handling (issue #683). The base interface is **untouched**, so external/custom
strategies that only implement the base 4-argument `convert()` keep working
unchanged. This mirrors the backward-compatibility pattern already established
by the method-aware interface.

`ResponseConverter::convert()` grew the same trailing `bool $shouldClose = false`
and passes it through only to method-aware strategies (the `instanceof` dispatch
is unchanged for base-interface strategies).

`SymfonyController::__invoke()` computes `$shouldClose` from the bundle
`Request $request` — `protocolVersion() === '1.0'` or a case-insensitive
`Connection: close` header — exactly mirroring
`HttpRequestHandler::shouldCloseConnection()`. The controller is the single
call site of `ResponseConverter::convert()` and already has the request, so no
new plumbing through the handler is needed.

### 2. `StreamedResponseStrategy` emits `Connection: close` for HTTP/1.1 close replies

`buildHeaderString()` now emits `Connection: close` when `$protocolVersion === '1.0' || $shouldClose`.
For HTTP/1.1 close replies the head carries **both** `Connection: close` and
`Transfer-Encoding: chunked` — legal per RFC 9112 (chunked delimits the body;
`Connection: close` signals the socket will close after). The body framing
logic (chunked vs raw) is unchanged: HTTP/1.1 still sends hex-framed chunks and
the `0\r\n\r\n` terminator regardless of `$shouldClose`.

`convertHead()` (the HEAD path) threads `$shouldClose` through too, so a HEAD
streamed response to a close-delimited client echoes `Connection: close`
alongside `Transfer-Encoding: chunked`, matching the GET framing (RFC 9110
§9.3.2 "same header fields").

### 3. App-set `Connection` header suppressed on streamed HTTP/1.1 heads

The suppression guard in `buildHeaderString()` was 1.0-only
(`if ($protocolVersion === '1.0' && strcasecmp($name, 'Connection') === 0)`).
It is now unconditional (`strcasecmp($name, 'Connection') === 0`): the strategy
owns the `Connection` header on the streamed path for both 1.0 and 1.1, the same
way it already owns `Content-Length` and `Transfer-Encoding` (belt-and-braces
guards). An app-set `Connection: keep-alive` can no longer be emitted verbatim
while the handler closes the socket.

## What I rejected and why

- **Stamping in `HttpRequestHandler::sendResponse()` instead of the strategy.**
  Rejected: the handler early-returns on `responseSentDirectly` *before* any
  stamping, and the whole point is that the streamed path has already sent the
  head by then. Moving the early-return after stamping would require encoding
  the response twice or restructuring the direct-send contract — far larger
  than the issue warrants, and it would fight DEC-002 ("StreamedResponseStrategy
  is the only direct response sender"; the strategy owns the head).

- **A new `ConnectionAwareResponseConverterStrategyInterface`.** Rejected as
  over-engineering: the method-aware interface already exists for strategies
  that need request context beyond the base contract, and `StreamedResponseStrategy`
  already implements it. Adding a third interface for one extra bool would
  duplicate the `instanceof` dispatch and the backward-compatibility rationale.
  A trailing defaulted parameter on the existing method-aware interface is the
  minimal, consistent extension.

- **Passing the raw request `Connection` header string instead of a bool.**
  Rejected: the only decision the strategy needs is "will the socket close?"
  (`Connection: close` or HTTP/1.0). A bool keeps the strategy decoupled from
  header-parsing details and matches the existing `shouldCloseConnection()`
  semantics. Passing the raw string would push case-insensitive parsing into
  every strategy.

- **Suppressing the app `Connection` header only for close-delimited replies**
  (the issue's "or at least for close-delimited replies" hedge). Rejected in
  favour of unconditional suppression: the strategy already owns body framing
  for all streamed heads (it always emits `Transfer-Encoding: chunked` on 1.1
  and `Connection: close` on 1.0), so owning `Connection` universally is
  consistent and removes the contradiction for the keep-alive case too. A
  partial guard would leave the `Connection: keep-alive`-while-closing
  contradiction live for the keep-alive path.

## What I was unsure about

- **Whether `SymfonyController` is the right place to compute `$shouldClose`.**
  The handler's `shouldCloseConnection()` is the canonical computation, but the
  handler does not call `convert()` — `SymfonyController` does. Duplicating the
  two-line check in the controller mirrors the handler exactly and avoids
  threading the bool through the handler → controller boundary. An alternative
  would be to expose `shouldCloseConnection()` publicly and call it from the
  controller, but that widens the handler's API surface for a two-line check.
  I kept the duplication; it is trivial and the two are already conceptually
  paired (both read the same request fields).

- **No integration test through the full handler → controller → converter →
  strategy chain for the 1.1 close case.** The unit tests cover the strategy in
  isolation (the head content) and the handler's `shouldCloseConnection()`
  separately. A streamed-response integration test would require a real
  StreamedResponse kernel, which the existing `HttpRequestHandlerTest` does not
  set up (it uses `DefaultResponseStrategy` only). The strategy-level tests
  pin the exact wire bytes, which is the behaviour the issue is about.
