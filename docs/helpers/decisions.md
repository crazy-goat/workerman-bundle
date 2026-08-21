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
- `cookies` — DEC-010, DEC-015
- `coverage` — DEC-007
- `docs` — DEC-012
- `gh` — DEC-011
- `git-hooks` — DEC-008
- `http` — DEC-001, DEC-002, DEC-005, DEC-010, DEC-013, DEC-014, DEC-015
- `knowledge-base` — DEC-009
- `lint` — DEC-008
- `long-running` — DEC-003, DEC-014
- `markdown` — DEC-012
- `memory` — DEC-004, DEC-005, DEC-014
- `performance` — DEC-013
- `policy` — DEC-006, DEC-007, DEC-008, DEC-009
- `pr` — DEC-011
- `process` — DEC-009, DEC-011
- `response-strategy` — DEC-001, DEC-002
- `security` — DEC-005, DEC-006, DEC-010, DEC-013, DEC-015
- `static-files` — DEC-004
- `tests` — DEC-014, DEC-015
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

### The PR opens after implementation and local gates — `gh pr create` refuses a branch with no commits ahead of base
<!-- kb: id=DEC-011 date=2026-08-11 tags=gh,pr,process trigger="opening a pull request before the first implementation commit exists" hits=0 status=active -->

`gh pr create` — draft or not — fails with "GraphQL: No commits between
`master` and `<branch>`" when the head has no commits ahead of base:
GitHub will not create an empty-diff PR. A draft-first step therefore needed
a junk seed commit that polluted history, and CI on the empty branch ran the
full matrix for nothing (the #670 cycle; cancelled by the implementation
push minutes later). Since #704 (PR #705) the workflow opens the PR only
after implementation and local gates pass — `docs/workflow.md` step 9. The
issue link works regardless: `closingIssuesReferences` comes from the body's
`Closes #N` line from the first push.

### Raw angle-bracket placeholders in prose render as nothing on GitHub — backtick them
<!-- kb: id=DEC-012 date=2026-08-11 tags=docs,markdown trigger="writing or reviewing Markdown prose that quotes shell placeholders" hits=0 status=active -->

GitHub's renderer treats a bare `<word>` in prose as an inline HTML tag and
renders it as nothing, so a raw `<branch>` in a sentence silently disappears
from the rendered doc — it bit the #704 edit of `docs/workflow.md`,
`docs/process-notices.md` and `docs/process-changelog.md` and was fixed by
backticking. Convention: wrap shell placeholders in backticks everywhere
outside fenced code blocks. A fence- and backtick-aware scan of the tracked
`.md` files finds 0 raw occurrences today; keep it that way when editing
docs — the angle-bracket tokens belong in fenced blocks or backticks, never
bare in prose.

### Optimization gates on security-relevant parsers must fail open (re-parse) on every unverified input shape (#557)
<!-- kb: id=DEC-013 date=2026-08-15 tags=http,security,performance trigger="adding a fast-path gate to a security-relevant parser, or relaxing an existing one" hits=0 status=active -->

`RequestConverter::buildServerHeaders()` re-parses the raw header block on
every request solely to detect duplicate header values, because duplicate
`Cookie`/`Host`/`Content-Length`/`Authorization`/`Transfer-Encoding` handling
is a security property (the #217 duplicate-`Cookie` smuggling class). The
re-parse was gated behind an O(1) `rawHeadMayHaveDuplicates()` check in #557.
The invariant the gate must uphold: **it may return `true` when there are no
duplicates (false positive → wasted work, safe), but must NEVER return `false`
when duplicates exist (false negative → skipped duplicate handling, security
regression).** Every input shape the gate cannot prove safe — colon-less
garbage lines, obs-fold / whitespace-padded names, middleware-added headers
shifting the count, a `rawHead()` shape that does not match the assumed
terminator semantics — forces the slow path. Two genuine false-negative
classes were found while designing the gate (name-trim divergence between the
raw parser and Workerman's `parseHeaders()`, and middleware-added-header
count compensation; see FAQ-025) and both were closed by forcing the slow
path on those shapes. Any future optimization gate on a parser that enforces
a security property follows the same rule: fail open on unverified input, and
prove the proof for every input shape before trusting the fast path.

### Bounded static caches in long-lived workers: cap + plausibility skip + test affordance
<!-- kb: id=DEC-014 date=2026-08-16 tags=memory,long-running,http,tests trigger="adding or reviewing a process-lifetime static/FIFO cache keyed by data the bundle does not control" hits=1 status=active -->

Issue #574 established the house pattern for static caches shared across a
worker process: an explicit `*_MAX_SIZE` constant enforced on **every insert**
via `unset($cache[array_key_first($cache)])` (not `array_shift()` — ~5x
slower, see #558), a plausibility skip so implausibly long keys never enter
the cache (mirroring Workerman's `MAX_CACHE_STRING_LENGTH`), and public test
affordances (`::cache()` / `::resetCache()`) so the bound is assertable
without reflection on a method-local static. Caveat discovered en route: a
`final readonly class` cannot own the mutable static (PHP rejects static
properties in readonly classes) — extract an `@internal` utility class
(`HeaderNameNormalizer`) rather than dropping `readonly`. Complements DEC-004
and DEC-005.

Applied to the `StaticFilesMiddleware` realpath cache in the #558 cycle: the
planted three pillars landed there too — `StaticFilesRealPathCache`
(`@internal`, `::cache()` / `::resetCache()` affordances) and a 512-byte
`CACHE_KEY_MAX_BYTES` key guard (mirroring Workerman's
`MAX_CACHE_STRING_LENGTH`) that probes implausibly long request paths without
caching, fail-open per DEC-013 — plus an LRU-semantics regression test that
distinguishes least-recently-*used* eviction from FIFO.

### Cookie parsing has no max_input_vars cap — documented deviation, pinned by test (#628)
<!-- kb: id=DEC-015 date=2026-08-21 tags=http,cookies,security,tests trigger="touching parseCookiesFromServerBag(), considering an input-count cap, or editing the $_COOKIE deviations paragraph" hits=0 status=active -->

PHP's SAPI stops registering cookies once `max_input_vars` (default 1000) is
reached — one E_WARNING, remaining pairs silently dropped — while
`RequestConverter::parseCookiesFromServerBag()` parses every pair in the
header. This is an **intentional** deviation, recorded in docs/security.md
("Known intentional deviations from `$_COOKIE`") and pinned by the
characterisation test `testCookieParsingIsNotCappedAtMaxInputVars` (1001
pairs → all parsed; first/middle/last asserted). Do not "fix" this by adding
a cap: capping silently drops data and needs its own decision — the test
fails first and forces docs/security.md to change with any behavior change.
(#628 cycle; resolved docs-only per the issue's minimum fix.)
