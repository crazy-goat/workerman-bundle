# Decisions — Notable Project Decisions with Rationale

Subagents: read this before starting a task, append new entries after
finishing (see [README.md](README.md) for rules).

## Architecture / behavior

### Large responses are sent in a single write

`DefaultResponseStrategy` sends large responses in one write instead of
chunked streaming (`feat: single-send large responses`, #556, #620). Do
not reintroduce chunked sends for regular responses.

### `StreamedResponseStrategy` is the only direct response sender

`HttpRequestHandler` delegates actual response sending exclusively to
`StreamedResponseStrategy` (docs fix #622). Any new response path must go
through the strategy layer, not the handler.

### One worker-level sweeper enforces connection/keepalive timeouts (supersedes per-connection timers)

ServerWorker enforces `connection_timeout` and `keepalive_timeout` via one
persistent worker-level sweeper plus `context->lastActivity` /
`context->requestCompleted` timestamps instead of per-connection timers
(#555, 8294c49). This supersedes the earlier "Per-connection timers are
cancelled on connection close" decision (fix #571, #616): the sweeper
intentionally survives connection close, and `onClose` clears the context
so closed connections are skipped. Timeouts are enforced with sweep
interval granularity (`max(1, intdiv(min(timeout), 4))` seconds), not
exactly, and activity is whole-second granular.

### Negative realpath cache is capped

`StaticFilesMiddleware` caps the negative realpath cache so long-lived
workers do not grow it unboundedly when files are missing (#570, #607).

### Trusted-host patterns are applied once and re-applied only on cache miss (#560)

`SymfonyController` maintains a per-worker, bounded (64-entry) validated-host
cache. `Request::setTrustedHosts()` is called only on a cache miss (a host not
seen — or evicted — by this worker), not on every request. The reset inside
`setTrustedHosts()` is what bounds Symfony's internal `Request::$trustedHosts`
list: with a wildcard trusted-host pattern that list would otherwise grow by one
entry per distinct matching host for the worker's lifetime (unbounded memory +
quadratic `in_array` lookup). The previous per-request call was wasteful but was
the only thing resetting that list — fixing the waste without a bound would have
been strictly worse. `getTrustedHosts()` returns the *patterns*, not the
validated-host cache, so the regression test reads `Request::$trustedHosts` via
reflection. When a trusted proxy forwards `X-Forwarded-Host`, the cache is
skipped (reset every request) because the cache key (direct Host header) would
not match the value `getHost()` validates.

## Security policy

The following hardening measures were consolidated through the security
review (#582–#586 series) — keep them intact when touching the related
code paths:

- Cache directory permissions and ownership are checked before `require`
  (#586, #611).
- Static file serving allows only an explicit allowlist; backup/credential
  extensions (`.bak`, `.env`, etc.) are blocked (#580/#582, #603/#609).
- Master process identification is hardened (#584, #608).
- HTTP headers starting with underscore are dropped (#578, #605).
- Dropped-underscore-header logging is bounded per worker: at most 64
  distinct client-supplied header names are recorded and logged, then a
  single suppression notice (issue #638). The map is capped and the
  suppression flag is a separate scalar, so attacker-sent names can
  neither grow worker memory nor amplify log writes.
- SFX redirect policy is unified across modes (#585, #606).
- Transport-owned headers are stripped to prevent duplicate
  `Content-Length` (#579, #602).

Do not loosen these without an explicit, documented reason.

## Process / repository policy

### 80% line-coverage floor, single source of truth

`composer.json`'s `coverage:check` script is the only place the 80%
threshold is defined (#589, #601) — update it there, not in CI YAML.

### Pre-push hook runs `composer lint`

`bin/install-git-hook.php` installs a pre-push hook that runs
php-cs-fixer, phpstan and rector (dry-run). Keep `composer lint` /
`lint-fix` as the canonical lint entry points.

### Subagents maintain this knowledge base

`worker`/`coder` and `review` subagents read and append to
`docs/helpers/` (see [README.md](README.md)). This keeps project memory
persistent across sessions.

### Cookie values are raw-URL-decoded exactly like PHP's SAPI (#583)

`RequestConverter::parseCookiesFromServerBag()` decodes cookie values with
`rawurldecode()` semantics **after** splitting on `;`/`=`, mirroring PHP's
`php_default_treat_data()` → `php_raw_url_decode()` step that populates
`$_COOKIE` under FPM and every other SAPI. Verified from `main/php_variables.c`
(PHP-8.2) and live probes: `%XX` sequences decode, a literal `+` stays `+`
(it is NOT turned into a space — despite the widespread assumption that cookie
values are `urldecode()`d), and cookie names are not decoded at all. Decoding
is ordered after splitting so an encoded `%3B` can never re-open the #217
smuggling class.
