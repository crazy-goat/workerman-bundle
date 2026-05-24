# Security

## Header Injection Protection

The `RequestConverter` applies security hardening when propagating HTTP headers from Workerman to Symfony:

### Cookie Header (RFC 6265)

When multiple `Cookie` header lines are present in the request, values are joined with `; ` as required by RFC 6265, rather than the standard HTTP `, ` separator. This prevents cookie smuggling where a `,` byte in a cookie value could be misinterpreted as a separator between cookies.

**Before (vulnerable):**
```
Cookie: session=abc123
Cookie: token=xyz789
→ HTTP_COOKIE: "session=abc123, token=xyz789"
→ Cookies parsed as one cookie: session=abc123, token=xyz789
```

**After (hardened):**
```
Cookie: session=abc123
Cookie: token=xyz789
→ HTTP_COOKIE: "session=abc123; token=xyz789"
→ Cookies correctly parsed as two cookies: session=abc123, token=xyz789
```

### Duplicate Sensitive Headers

Duplicate `Host`, `Content-Length`, and `Authorization` headers are suspicious and may indicate request smuggling or header injection attacks. Only the first value of each is propagated to Symfony; subsequent values are silently discarded.

- **Host**: Prevents Host-header poisoning attacks
- **Content-Length**: Prevents request smuggling via conflicting Content-Length values
- **Authorization**: Prevents authorization header injection

### Control Character Rejection

Header values containing control characters (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`, `\x7F`) are rejected with an `\InvalidArgumentException`. This prevents:

- HTTP response splitting via CR/LF injection in header values
- Log forging via control characters in custom headers
- Protocol-level attacks through malformed header values

### Request URI and Method Validation

The `RequestConverter` also validates:

- **URI**: Control characters in the request URI are rejected (defense against log forging and URI-based access bypass)
- **Method**: Only uppercase ASCII letters are allowed (stricter than RFC 7230 to minimise routing bypass attacks), with a maximum length of 32 characters
