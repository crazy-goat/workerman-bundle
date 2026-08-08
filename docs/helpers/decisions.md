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

### Per-connection timers are cancelled on connection close

All timers registered per connection must be cancelled in the close
handler, otherwise they fire for dead connections (fix #571, #616).

### Negative realpath cache is capped

`StaticFilesMiddleware` caps the negative realpath cache so long-lived
workers do not grow it unboundedly when files are missing (#570, #607).

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
