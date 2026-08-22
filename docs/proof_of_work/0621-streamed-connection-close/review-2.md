# Review round 2 — issue #621: echo `Connection: close` on streamed HTTP/1.1 responses

**Verdict:** All round-1 findings resolved. No new issues found. The implementation is clean and ready for PR.

## Round-1 finding status

### F-1 (medium) — Missing test for $shouldClose in SymfonyController → FIXED

Two new tests added in `tests/SymfonyControllerTest.php`:
- `testStreamedResponseHttp11ConnectionCloseEchoesConnectionClose` — drives a `StreamedResponse` through `SymfonyController` with HTTP/1.1 + `Connection: close`, captures `$connection->send()` calls, asserts the head (first send call) contains `Connection: close`.
- `testStreamedResponseHttp11KeepAliveDoesNotEchoConnectionClose` — HTTP/1.1 without `Connection: close`, asserts NO `Connection:` header in the head.

Both tests use `willReturnCallback` to capture send calls. The first `$sendCalls[0]` is the strategy-built head (sent via `$connection->send($head, true)` before any body frames). Verified correct.

### F-2 (medium) — Missing test for $shouldClose pass-through in ResponseConverter → FIXED

Two new tests added in `tests/ResponseConverterTest.php`:
- `testConvertThreadsShouldCloseToStreamedStrategy` — calls `convert()` with 5th arg `true` on a `StreamedResponse` over HTTP/1.1, asserts the head contains `Connection: close`.
- `testConvertDefaultShouldCloseKeepsStreamedHeadKeepAlive` — 4-arg call (default `false`), asserts NO `Connection:` in the head.

Both correctly assert on the strategy-built head captured via `$connection->send()`.

### F-3 (low) — Duplicated close-intent check → FIXED

New `src/Http/ConnectionIntent.php` with `static shouldClose(Request $request): bool`. Both `SymfonyController::__invoke()` and `HttpRequestHandler::shouldCloseConnection()` now delegate to it.

Edge cases verified — behavior is byte-for-byte identical to the original duplicated logic:
- HTTP/1.0 → `true` (always close)
- HTTP/1.1 + `Connection: close` → `true`
- HTTP/1.1 without `Connection` header → `false` (keep-alive default)
- HTTP/1.1 + `Connection: keep-alive` → `false`
- HTTP/2 → `false` (not 1.0, typically no Connection header)
- Empty `Connection` header → `false` (strcasecmp against empty string)
- Case-insensitive `Close` → `true` (strcasecmp is case-insensitive)
- `Connection: close, keep-alive` (comma-list) → `false` — pre-existing limitation, identical on master, not a regression

The `HttpRequestHandlerTest` reflection tests over `shouldCloseConnection()` still pass (103 tests green). The private method now forwards to the helper, preserving its test contract.

### F-4 (nit) — Unused $shouldClose in BinaryFileResponseStrategy → BY-DESIGN

Confirmed: the parameter is required by the `RequestMethodAwareResponseConverterStrategyInterface` contract so `ResponseConverter` can dispatch uniformly. `BinaryFileResponseStrategy` returns a regular `WorkermanResponse` that goes through central stamping in `sendResponse()`, so it never needs the flag. The existing comment at the call site documents this. No code change needed. Not a real finding.

## New issues found in round 2

None. The fix commit is clean:
- `ConnectionIntent` is `final` with a private constructor — non-instantiable, correct for a pure static helper.
- PSR-4 autoload path is correct: `src/Http/ConnectionIntent.php` → `CrazyGoat\WorkermanBundle\Http\ConnectionIntent`.
- No new header injection vectors — `Connection: close` is a hardcoded literal.
- No backward compatibility break — the `bool $shouldClose = false` default on the method-aware interface is preserved.
- Test suite: 2362 tests, 0 failures, 32 skipped (pre-existing environment skips).
- Lint: PHPStan, PHP-CS-Fixer, Rector all clean. One pre-existing kb-lint budget warning on `docs/helpers/faq.md` (376 lines > 300 budget) — unrelated to this change.

## Conclusion

No open findings. Ready for PR.
