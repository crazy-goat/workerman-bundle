# 0676 — Coder findings

## Obstacles / surprises

1. **`--publish` + `--rm` race on `docker port`** — `bin/docker-test-worktree`
   runs the suite in the foreground and the container is auto-removed on exit;
   `docker port` output was queried immediately after and still answered, but
   Docker does not guarantee how long a removed container's mapping table
   stays queryable. Mitigation: mappings printed only after exit 0, guarded
   with `|| true`; docs point at the detached `docker run -d` recipe for real
   interactive debugging (issue "Usage example" 3c). Suggested follow-up: a
   `--debug` flag that starts the daemon detached instead of running phpunit.
2. **Issue's usage example vs. helper contract mismatch (minor)** — the issue's
   3c example runs `APP_RUNTIME=... php tests/App/index.php start` with
   `composer install &&` inside `sh -c`, i.e. it never runs phpunit; my helper
   treats `--publish` as "suite + print mappings". Both satisfy the acceptance
   criterion; documented in code-decision-1.md.
3. **CONTRIBUTING.md note drift** — the old note (~lines 60-62) said ports
   **8888 and 9999**, omitting 9991 which Kernel.php also binds; fixed to list
   all three while rewriting it.

## Bugs / weak spots noticed (incl. out of scope)

1. **`bin/docker-test` shares `wmb-var` by design** (`bin/docker-test:20`,
   `-v wmb-var:/app/var`) — safe for one checkout, but exactly the shape issue
   #676 flags as fatal when reused across *parallel* worktrees. CONTRIBUTING.md
   now warns explicitly; longer term `docker-test` could refuse to run when a
   sibling worktree container holds the same volume. Out of scope here.
2. **Port numbers hardcoded in 4 test clients**
   (`tests/ResponseTest.php:16`, `tests/MiddlewareTest.php`,
   `tests/RequestParametersTest.php`, `tests/WorkermanCommandTest.php`) plus
   `tests/App/Kernel.php`. Fine under network isolation, but any future
   `--network host` workflow breaks silently. If ever revisited, centralise in
   one env-driven constant rather than per-file edits.
3. **`var/dispatch_count` is host-stateful across local runs**
   (`DispatchCountMiddleware` writes `%kernel.project_dir%/var/dispatch_count`;
   `var/dispatch_count` exists in this checkout) — two bare-metal
   `composer test` runs in different checkouts of the same machine still
   collide only if they share a directory; not an issue, but worth knowing
   that Docker bind-mounting the same directory twice would reintroduce the
   collision the docs warn about.
4. **`docs/helpers/faq.md` FAQ-009 ("Address already in use") is stale-ish**:
   it points only at ports 8888/9999 documentation; with 9991 now bound too,
   troubleshooting text mentioning just two ports should be swept next time
   docs/workflow.md step 7 or docs/troubleshooting.md is touched. Not edited
   here (docs/helpers/ is retro-only).
5. **Helper name-collision edge**: `basename` of two differently-parented
   worktrees can be equal (e.g. `~/a/wmb-fix` vs `~/b/wmb-fix`). The helper
   then reuses both container name and vendor volume; docker fails loudly on
   the name clash (exit ≠ 0), so no silent corruption of *vendor*, but a
   clearer error message could mention the collision. Suggested fix: append a
   short hash of the canonical path when a container named `wmb-<base>`
   already exists.
