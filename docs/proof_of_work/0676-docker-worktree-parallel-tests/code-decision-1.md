# 0676 — Decision: parallel dockerized test suite across git worktrees

## Approach taken

- **`tests/App/Kernel.php`**: the three hardcoded `'listen'` entries became
  `'http://' . (getenv('WMB_LISTEN_ADDR') ?: '127.0.0.1') . ':<port>'`
  (ports 8888/9999/9991, lines 44/51/60). With the var unset the generated
  string is byte-identical to before; `WMB_LISTEN_ADDR=0.0.0.0` is set only by
  the helper's `--publish` path.
- **`bin/docker-test-worktree`** (new, executable): derives `wmb-<basename>`
  container name and `wmb-vendor-<basename>` volume from the worktree
  basename, bind-mounts the worktree at `/app`, runs
  `composer install --no-interaction && composer test` (or `test:coverage` /
  `lint` via pass-through flags), exits with the container's exit code, and on
  `--publish` adds ephemeral `-p 127.0.0.1::8888|9999|9991` +
  `-e WMB_LISTEN_ADDR=0.0.0.0` and prints `docker port` mappings after a green
  run. Style copied from `bin/docker-test` (#674): same header comment block,
  `usage()`/`die()`/`die_usage()` helpers, `set -euo pipefail`,
  `command -v docker` guard, "Exit codes" trailer.

## Alternatives rejected

1. **Parameterised ports (`WMB_PORT_BASE` + offsets)** — rejected in the issue
   itself: would require touching Kernel.php plus every hardcoding test client
   (ResponseTest, WorkermanCommandTest, RequestParametersTest, MiddlewareTest,
   MiddlewareDispatchContractTest) for no benefit once each container has its
   own loopback.
2. **A named `var/` volume per worktree (`wmb-var-<base>`)** — rejected in
   favour of leaving `var/` inside the bind mount: it keeps parallel runs from
   ever sharing PID files/dispatch_count by construction and matches the
   issue's risk mitigation ("the helper always uses the bind-mounted worktree
   var/, no named var volume"). Downside: artifacts don't survive container
   removal — irrelevant for test runs.
3. **Sharing one `wmb-vendor` volume across worktrees** — explicitly forbidden:
   branches may pin different dependencies (e.g. a Symfony bump); a shared
   vendor dir gets corrupted for siblings.
4. **`docker compose` orchestration / resource-limit flags** — out of scope per
   the issue; raw `docker run` stays minimal. Resource limits documented in
   CONTRIBUTING.md as a recommendation only.

## Uncertainties

- The issue's example passes the worktree path positionally *before* flags;
  I made argument order free but reject non-existent paths that start with `-`
  (they'd otherwise be indistinguishable from unknown flags). Paths not
  starting with `-` are accepted anywhere.
- `--publish` prints mappings only when the suite exits 0 (container is then
  already removed by `--rm`; `docker port` still answers because the mapping
  table is queried immediately after exit — if Docker purges it faster than
  the call, `|| true` prevents a spurious failure). For interactive curl
  debugging the issue's manual detached `docker run -d ... --entrypoint sh`
  recipe remains the reliable route; the flag exists to prove the mapping +
  `WMB_LISTEN_ADDR` wiring works end-to-end.
- Multi-worktree concurrency was verified with one real second checkout plus a
  name-collision check, not three simultaneous full suites (host load);
  see findings-coder.md for what exactly ran.
