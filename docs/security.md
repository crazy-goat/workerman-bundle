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

## Trusted Host Enforcement

Host-header poisoning is a class of attack where an attacker controls the `Host` header sent to the server, potentially affecting password-reset links, cache keys, and routing decisions made by the application.

By default, all `Host` header values from incoming requests are accepted. To restrict which hostnames your application responds to, configure `trusted_hosts` in your Workerman configuration:

```yaml
workerman:
    trusted_hosts:
        - '^example\.com$'
        - '^api\.example\.com$'
```

Each entry is a regular expression pattern (without delimiters). Symfony adds the delimiters automatically. A request whose `Host` header does not match any pattern will be rejected with a `SuspiciousOperationException`, resulting in a 400 response.

### Interaction with Symfony's `framework.trusted_hosts`

If you also configure `framework.trusted_hosts` in Symfony, note that:

- `workerman.trusted_hosts` is enforced **inside the Workerman worker process**, before the Symfony kernel handles the request.
- If both are configured, the Workerman-level enforcement is sufficient — the Symfony-level setting is redundant but harmless.
- If you use PHP-FPM alongside Workerman workers, configure both independently.

### When this matters

Configure `trusted_hosts` when your application generates absolute URLs based on the incoming `Host` header (e.g., password-reset emails, webhook callbacks, OAuth redirects). Without it, an attacker can craft a request with a spoofed `Host` header and trick the application into generating URLs pointing to an attacker-controlled domain.
