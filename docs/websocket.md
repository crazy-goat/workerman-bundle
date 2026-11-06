# WebSocket support: design note

This note is the result of spike [#909](https://github.com/crazy-goat/workerman-bundle/issues/909).
It answers the questions in that issue and records the decision for the `0.32`
milestone.

## Decision

**Do not build WebSocket support in `0.32`.** Keep `ws://` and `wss://`
rejected at startup with the existing clear error (see
[HTTP server](http-server.md#listen-address)). The current bundle serves Symfony HTTP
requests and has no lifecycle for long-lived connections.

This is a scope decision, not a claim that the work is impossible. Revisit it
when there is a concrete user need and a design for the questions below.

## Answers

### Handler interface

A future implementation should add one interface, for example:

```php
interface WebSocketHandlerInterface
{
    public function onOpen(TcpConnection $connection, Request $request): void;
    public function onMessage(TcpConnection $connection, string $data): void;
    public function onClose(TcpConnection $connection): void;
}
```

One handler service per server, selected in the server config. A default
handler that forwards to the Symfony kernel is not enough: the kernel expects
one request and one response, not an open connection.

### Config

Add `servers[].websocket_handler: <service id>` and restore `ws://`/`wss://`
in `ListenScheme` only when that key is set and the service exists. A server
without a handler must still be rejected.

### Handshake

The HTTP upgrade request can go through the Symfony kernel (routing, firewall,
session) once, before the connection switches protocols. Workerman's
`onWebSocketConnect` gives the raw request; the bundle would need to convert
it, run the kernel, and keep the authenticated user on the connection. This is
the largest open design risk.

### Kernel state

There is no request cycle per message. Long-lived connections must not keep a
request-scoped container, Doctrine entity manager or session open. A future
handler would need an explicit per-message context (for example a fresh
container scope) or a documented rule that handlers only use stateless
services.

### Limits

Define and document:

- maximum frame size and maximum message size;
- ping/pong interval and idle timeout;
- maximum connections per worker and backpressure when a client is slow;
- what happens to a message when the handler throws.

### Timeouts and stop

Open WebSocket connections block a graceful stop. A future implementation
must close connections on stop, or rely on `graceful_stop_timeout` and the
SIGKILL backstop. `connection_timeout` and `keepalive_timeout` are HTTP
timeouts and do not apply to a WebSocket message loop.

### `wss://`

Reuse the existing `local_cert` / `local_pk` TLS handling. The handshake and
the frame loop run over the same TLS connection.

### Tests

A real WebSocket client end-to-end test is mandatory: handshake, one echo
message, close, and a graceful stop with an open connection. Without it,
`ws://` and `wss://` must stay rejected.

## What stays in `0.32`

`ListenScheme` accepts only `http://` and `https://`. A `ws://` or `wss://`
listen address fails at startup with
`WebSocket listeners (ws://, wss://) are not supported yet in "<listen>". Use http:// or https://.`
The README, [security](security.md) and [HTTP server](http-server.md) docs say
the same.
