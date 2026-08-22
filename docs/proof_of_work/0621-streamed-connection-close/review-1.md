# Review round 1 — issue #621: echo `Connection: close` on streamed HTTP/1.1 responses

Branch: `feat/issue-621-streamed-responses-don-t-echo-connection`
Base: `origin/master`

## Scope

Reviewed the full diff (`git diff origin/master`) plus the changed source files
in full context:

- `src/Http/Response/RequestMethodAwareResponseConverterStrategyInterface.php`
- `src/Http/Response/ResponseConverter.php`
- `src/Http/Response/Strategy/BinaryFileResponseStrategy.php`
- `src/Http/Response/Strategy/StreamedResponseStrategy.php`
- `src/Middleware/SymfonyController.php`
- `tests/Strategy/StreamedResponseStrategyTest.php`
- `CHANGELOG.md`

Also read for context: `src/Http/HttpRequestHandler.php` (the canonical
`shouldCloseConnection()`), `src/Http/Request.php` (inherits
`header()`/`protocolVersion()` from Workerman), the Workerman
`Request::header()` / `protocolVersion()` implementations, and the existing
tests pinning the changed files (`tests/Strategy/StreamedResponseStrategyTest.php`,
`tests/SymfonyControllerTest.php`, `tests/HttpRequestHandlerTest.php`,
`tests/ResponseConverterTest.php`).

## Verification performed

- `vendor/bin/phpunit --filter StreamedResponseStrategyTest` — 19 tests, 115
  assertions, all green.
- `vendor/bin/phpstan analyse` (level per `phpstan.neon.dist`) — no errors.
- Confirmed `SymfonyController::__invoke()` computes `$shouldClose` with the
  exact same expression as `HttpRequestHandler::shouldCloseConnection()`
  (`src/Http/HttpRequestHandler.php:207-210`): `protocolVersion() === '1.0' ||
  strcasecmp((string) $request->header('Connection', ''), 'close') === 0`.
- Confirmed only `StreamedResponseStrategy` sets
  `connection->context->responseSentDirectly = true` (both the GET and HEAD
  paths), so the handler's central `Connection: close` stamping is correctly
  skipped for streamed responses and the strategy must own the header.
- Confirmed `BinaryFileResponseStrategy` does NOT set
  `responseSentDirectly`; its responses (including `HeadResponse`) go through
  `HttpRequestHandler::sendResponse()`, so the handler's central stamping
  applies and `$shouldClose` is correctly unused there.

## Knowledge-base check (docs/helpers/)

Loaded the tag indexes for `faq.md` and `decisions.md`, then the entries whose
tags match the diff files (`http`, `response-strategy`, `streamed-response`,
`headers`, `bc`, `security`):

- **DEC-001** (single-write large responses) — not violated; streamed path
  unchanged in framing.
- **DEC-002** (`StreamedResponseStrategy` is the only direct response sender)
  — **respected**: the fix keeps `Connection` header ownership inside the
  strategy, consistent with the strategy owning the head.
- **DEC-006** (security hardening intact) — the `Connection` header
  suppression is a transport-ownership guard, consistent with the
  transport-owned-headers policy (#579). Not a loosening.
- **FAQ-001** (HEAD + Content-Length duplication) — not relevant; streamed
  path has no Content-Length.
- **FAQ-002** (BinaryFile HEAD, method-aware interface BC pattern) — **directly
  relevant and respected**: the new `bool $shouldClose` is added as a trailing
  defaulted parameter on the method-aware interface, exactly mirroring the BC
  pattern established by #683. The base
  `ResponseConverterStrategyInterface` is untouched; `ResponseConverter`
  dispatches on `instanceof` and only passes `$shouldClose` to method-aware
  strategies. External strategies implementing only the base interface keep
  working.

No documented decisions are violated by this diff.

## Findings

See `findings-review.md` for the per-finding table. Summary:

- **F-1 (medium):** No test pins the `$shouldClose` computation in
  `SymfonyController::__invoke()` — the duplicated two-line check is
  untested at its new call site. A drift between the controller and the
  handler would reproduce this exact issue silently.
- **F-2 (medium):** No test pins the `$shouldClose` threading through
  `ResponseConverter::convert()` — the new parameter is passed to
  method-aware strategies but never asserted at the converter level.
- **F-3 (low):** The duplicated close-intent check
  (`SymfonyController` vs `HttpRequestHandler::shouldCloseConnection()`) is a
  latent drift hazard. The coder already flagged this (findings-coder #1);
  a single shared helper would eliminate it.
- **F-4 (nit):** `BinaryFileResponseStrategy::convert()` declares
  `bool $shouldClose = false` but never reads it. Intentional and documented
  in a comment, but PHPStan does not flag unused params, so the contract
  "this strategy ignores close intent" lives only in a comment.

## Edge cases considered and found correct

- **HTTP/1.1 + `Connection: close` + `Transfer-Encoding: chunked`**: legal per
  RFC 9112. Chunked delimits the body; `Connection: close` signals the socket
  closes after. The body framing (hex chunks + `0\r\n\r\n` terminator) is
  unchanged. Correct.
- **HTTP/1.1 keep-alive (default)**: no `Connection` header emitted. RFC 9112
  §9.3: default is keep-alive for 1.1. The suppression guard does not add a
  header here. Correct.
- **HTTP/1.0**: `Connection: close` emitted, no `Transfer-Encoding`. Body is
  raw (close-delimited). Unchanged. Correct.
- **HEAD + HTTP/1.1 + close**: `convertHead` threads `$shouldClose` through
  `buildHeaderString`, emitting `Connection: close` + `Transfer-Encoding:
  chunked` with no body. Matches GET framing (RFC 9110 §9.3.2). Correct.
- **App-set `Connection: keep-alive` on HTTP/1.1 close reply**: suppressed
  unconditionally; strategy emits `Connection: close`. Correct — removes the
  contradiction.
- **App-set `Connection` on HTTP/1.1 keep-alive (no close)**: suppressed, no
  `Connection` header emitted. This is a behavior change (previously the app
  value was emitted verbatim for 1.1), but it is correct: the strategy owns
  the `Connection` header on streamed heads for both 1.0 and 1.1, consistent
  with owning `Content-Length` and `Transfer-Encoding`. An app cannot
  override the transport's connection decision on a streamed response.
- **Backward compatibility**: `bool $shouldClose = false` is a trailing
  defaulted parameter on the method-aware interface only. The base interface
  is untouched. `ResponseConverter::convert()` also defaults it. The single
  internal caller (`SymfonyController`) passes it explicitly. External
  strategies implementing only the base 4-arg `convert()` are unaffected.
  External strategies implementing the method-aware interface with the old
  5-arg signature would break — but the method-aware interface was introduced
  in #683 (this repo), so there are no external implementors yet. No BC break.
- **HTTP header injection**: `buildHeaderString` already guards header names
  (`strpbrk($name, ":\r\n")`) and values (`strpbrk($value, "\r\n")`). The
  `Connection: close` literal is hardcoded. No injection vector introduced.

## Automated checks that could catch the findings

- **F-1 / F-2 (missing tests):** a regression test driving a
  `StreamedResponse` through `SymfonyController` with an HTTP/1.1
  `Connection: close` request, asserting the wire bytes contain
  `Connection: close`, would catch both the computation and the threading in
  one integration test. The coder noted this gap (findings-coder #3) but did
  not add it. This is the same class of defect as FAQ-032 (multiple tests pin
  the same files, a narrow filter misses the integration path) — the
  strategy-level unit tests pin the wire bytes, but the
  controller→converter→strategy contract for `$shouldClose` is untested as a
  pair.
- **F-3 (duplication):** a PHPStan rule or a reflection-based test asserting
  `SymfonyController`'s close-intent expression matches
  `HttpRequestHandler::shouldCloseConnection()` would catch drift, but this is
  over-engineering for a two-line check. A single shared static helper
  (`Http\ConnectionIntent::shouldClose(Request)`) called from both sites is
  the simpler fix and makes the duplication structurally impossible.
