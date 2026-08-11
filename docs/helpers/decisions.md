# Decisions — Notable Project Decisions with Rationale

**How to read this file:** load the tag index below, pick the tags that match
the files in your diff, then read only those `###` entries. Do not read the
whole file.

**Who writes here:** only the retro step. Implementation and review subagents
*propose* candidate entries in their report — they never append (see
[README.md](README.md)).

## Tag index

<!-- kb-index:start -->
- `architecture` — DEC-002
- `ci` — DEC-007
- `cookies` — DEC-010
- `coverage` — DEC-007
- `git-hooks` — DEC-008
- `http` — DEC-001, DEC-002, DEC-005, DEC-010
- `knowledge-base` — DEC-009
- `lint` — DEC-008
- `long-running` — DEC-003
- `memory` — DEC-004, DEC-005
- `policy` — DEC-006, DEC-007, DEC-008, DEC-009
- `process` — DEC-009
- `response-strategy` — DEC-001, DEC-002
- `security` — DEC-005, DEC-006, DEC-010
- `static-files` — DEC-004
- `timers` — DEC-003
<!-- kb-index:end -->

## Architecture / behavior

### Large responses are sent in a single write
<!-- kb: id=DEC-001 date=2026-08-08 tags=http,response-strategy trigger="changing how response bodies are written" hits=0 status=active -->

`DefaultResponseStrategy` sends large responses in one write instead of
chunked streaming (`feat: single-send large responses`, #556, #620). Do
not reintroduce chunked sends for regular responses.

### `StreamedResponseStrategy` is the only direct response sender
<!-- kb: id=DEC-002 date=2026-08-08 tags=http,response-strategy,architecture trigger="adding a new response path in src/Http" hits=0 status=active -->

`HttpRequestHandler` delegates actual response sending exclusively to
`StreamedResponseStrategy` (docs fix #622). Any new response path must go
through the strategy layer, not the handler.

### One worker-level sweeper enforces connection/keepalive timeouts (supersedes per-connection timers)
<!-- kb: id=DEC-003 date=2026-08-08 tags=timers,long-running trigger="connection_timeout, keepalive_timeout or ServerWorker timers" hits=0 status=active -->

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
<!-- kb: id=DEC-004 date=2026-08-08 tags=static-files,memory trigger="caching in StaticFilesMiddleware" hits=0 status=active -->

`StaticFilesMiddleware` caps the negative realpath cache so long-lived
workers do not grow it unboundedly when files are missing (#570, #607).

### Trusted-host patterns are applied once and re-applied only on cache miss (#560)
<!-- kb: id=DEC-005 date=2026-08-10 tags=http,memory,security trigger="trusted hosts, X-Forwarded-Host or SymfonyController caching" hits=0 status=active -->

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

### Security hardening from the #582–#586 review must stay intact
<!-- kb: id=DEC-006 date=2026-08-09 tags=security,policy trigger="touching static file serving, headers, master identification or cache permissions" hits=0 status=active -->

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
<!-- kb: id=DEC-007 date=2026-08-08 tags=coverage,ci,policy trigger="coverage threshold, CI workflow or composer scripts" hits=0 status=active -->

`composer.json`'s `coverage:check` script is the only place the 80%
threshold is defined (#589, #601) — update it there, not in CI YAML.
Lowering it is forbidden outright.

### `composer lint` / `lint-fix` are the canonical entry points
<!-- kb: id=DEC-008 date=2026-08-11 tags=lint,git-hooks,policy trigger="adding a new check, or wiring one into CI or the hook" hits=0 status=active -->

`bin/install-git-hook.php` installs a pre-push hook that runs `composer lint`
— php-cs-fixer, phpstan, rector (dry-run) and the knowledge-base linter
(`bin/kb-lint.php`). A new repository-wide check is added to the `lint` script,
and gets a standalone `composer <name>` script only as a convenience alias;
nothing invokes the individual tools directly, so `lint` stays the one thing
CI, the hook and a contributor all run.

A check inside `lint` must be **safe to run at any point in a cycle**. Composer
aborts an array script on the first non-zero command, so a check that can fail
mid-cycle blocks every push on every branch — the `--no-verify` failure mode
the pre-push hook exists to avoid. A check that cannot meet that bar does not
belong in `lint`.

### The main session is the only writer of this knowledge base
<!-- kb: id=DEC-009 date=2026-08-11 tags=knowledge-base,process,policy trigger="learning something worth recording during implementation or review" hits=0 status=active -->

Two writers (coder and review) produced duplicates, unlabelled entries and a
file that had to be read in full for every task, so since issue #686 the
knowledge base has a **single writer**: the main session, at the end of the
cycle (workflow step 14). Implementation and review subagents *propose*
candidate entries in their report — id, tags, trigger, one paragraph — and the
main session decides what lands. Reading is
unchanged and mandatory: tag index first, then only the entries matching the
files in the diff. `bin/kb-lint.php` enforces front matter, unique ids, index
freshness and the line budget; the decay rules live in
[README.md](README.md).

### Cookie values are raw-URL-decoded exactly like PHP's SAPI (#583)
<!-- kb: id=DEC-010 date=2026-08-08 tags=http,cookies,security trigger="parsing cookies or changing RequestConverter" hits=0 status=active -->

`RequestConverter::parseCookiesFromServerBag()` decodes cookie values with
`rawurldecode()` semantics **after** splitting on `;`/`=`, mirroring PHP's
`php_default_treat_data()` → `php_raw_url_decode()` step that populates
`$_COOKIE` under FPM and every other SAPI. Verified from `main/php_variables.c`
(PHP-8.2) and live probes: `%XX` sequences decode, a literal `+` stays `+`
(it is NOT turned into a space — despite the widespread assumption that cookie
values are `urldecode()`d), and cookie names are not decoded at all. Decoding
is ordered after splitting so an encoded `%3B` can never re-open the #217
smuggling class.
