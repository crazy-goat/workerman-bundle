# 0676 — Review round 1 findings

Format: `file:line | what is wrong | severity | status`

- bin/docker-test-worktree:89 | `-e COMPOSER_ROOT_VERSION=1.0.0` is set unconditionally, but the repo has no `version`/`branch-alias` in composer.json and no other consumer sets this var (CI, Dockerfile and bin/docker-test all run plain `composer install` successfully). It silently overrides the real root package version for every install inside the helper; if a root `version` field is ever added it will be masked. Unjustified divergence from bin/docker-test style; should be dropped or documented. | low | **fixed** in round 2 (env removed from RUN_ARGS; composer derives the root version from git like bin/docker-test/CI)
- bin/docker-test-worktree:89-93 | The worktree is bind-mounted read-write at /app while only vendor/ is redirected to a volume; a failing/green suite still writes var/, cache and coverage artifacts into the host worktree (unlike bin/docker-test which isolates var/ in wmb-var). Not a correctness break for parallelism (each worktree has its own var/), but the usage text "var/ stays in the worktree itself" is the only place this is stated; acceptable per issue mitigation. | nit | **wontfix (by design)** — the issue's own risk mitigation mandates the worktree's own var/ ("the helper always uses the bind-mounted worktree var/, no named var volume"); artifacts landing in the checkout are the accepted trade-off, documented in usage() and CONTRIBUTING.md
- bin/docker-test-worktree:96-101 | `--publish` runs the full phpunit suite in the foreground with published ports; the daemon is stopped by `composer test`'s final step before `docker port` is called, so the printed mappings are useless for curl (nothing listens after exit) and rely on removed-container mapping-table timing (`|| true`). The issue's acceptance criterion 4 ("curl from the host reaches 127.0.0.1:<assigned-port>/response_test") cannot be demonstrated with this flow as shipped — it needs a detached daemon (the issue's 3c recipe). Coder acknowledged this in findings-coder.md; flagging because AC4 is therefore not met by the helper alone. | medium | **fixed** in round 2 (`--publish` redesigned as detached daemon-only mode per the issue's 3c recipe: `-d` + `restart -d`, readiness probed via fsockopen inside the container, mappings printed while alive, blocks until Ctrl-C then stops the daemon; verified end-to-end: curl from host → HTTP 200 "hello from test controller")
- bin/docker-test-worktree:104-107 | `docker port` is invoked after the container has already been removed by `--rm`; Docker does not guarantee the mapping table remains queryable for a removed container. Output is best-effort (`|| true`) so no failure, but the feature can silently print nothing. | low | **fixed** in round 2 (container now stays alive during publish mode; `docker port` always queries a running container, `|| true` removed)
- bin/docker-test-worktree:66-80 | Two worktrees with the same basename under different parents (e.g. ~/a/wmb-fix and ~/b/wmb-fix) collide on both container name and vendor volume name; docker fails loudly on the name clash (no silent corruption), but the error message does not mention the collision cause. Known limitation, also self-reported by coder. | low | **fixed** in round 2 (both failure paths print an explicit message naming the clash cause, the stale-container cleanup command and the shared-volume consequence)
- tests/App/Kernel.php:44,51,60 | `getenv('WMB_LISTEN_ADDR') ?: '127.0.0.1'` — an empty-string env value falls back to 127.0.0.1 (verified), matching the intent; unset path produces a byte-identical string (verified against parent commit). No defect found. | none | verified-ok
- CONTRIBUTING.md:206-250 | New subsection anchor `#parallel-test-runs-across-git-worktrees` matches the heading "Parallel test runs across git worktrees" (GitHub slug rules); internal link from line ~69 resolves. Verified statically. | none | verified-ok
- CONTRIBUTING.md:237 | Docs say `--publish` "passes -p ... so Docker assigns each container a random free host port" and present the flow as usable for curl debugging, but do not mention that the helper's publish mode stops the daemon when the suite ends (mappings printed are then dead). Doc overstates usefulness of the helper's publish path for manual curl; the detached recipe is not shown in CONTRIBUTING.md. | medium | **fixed** in round 2 (paragraph rewritten to match the new detached behavior: starts ONLY the daemon, prints mappings, blocks until Ctrl-C which stops it; suite not executed in this mode)
- CONTRIBUTING.md:223-227 | "Never share var/" rule present, per-worktree vendor rule present, unique --name documented — matches issue AC1. | none | verified-ok
- (process) | Acceptance criteria 3 (three concurrent worktree runs green) and 6 (two concurrent --publish containers get distinct ports) were NOT executed in this review session (host load / time); coder's own findings-coder.md admits criterion 3 was verified with one second checkout plus a name-collision check, not three simultaneous suites. Both remain UNVERIFIED, not failed. | info | open — orchestrator note: AC6's distinct-port guarantee was observed across this cycle's repeated publish smoke tests (each run got a different ephemeral port); a formal two-concurrent demonstration remains undone

## Round 2 (commit 400940b)

Format: `file:line | what is wrong | severity | status`

- bin/docker-test-worktree:115-120 | Readiness loop cannot fail: 30 failed `docker exec ... fsockopen` probes fall through to printing "published host ports" / "daemon running; curl the ports above" with no error and exit 0 on Ctrl-C. Trigger: daemon fails to bind inside the container (stale pid file in the worktree's var/, broken vendor volume) — user is told to curl dead ports. Loop should track success and die with a `docker logs` hint when exhausted. | medium | status=open
- bin/docker-test-worktree:126-128 | SIGTERM sent directly to the helper PID while it waits on foreground `tail -f /dev/null` does not reliably run the stop-trap before exit (bash defers traps until the foreground child exits; nothing terminates tail), so a scripted/kill-based shutdown can leave container wmb-<base> running and blocking its own name. Ctrl-C (SIGINT to the process group) works. Fix: `tail -f /dev/null & wait $!`. | low | status=open
- bin/docker-test-worktree:136-139 | Non-publish collision hint fires only on docker exit 125 (container-name clash). The other same-basename failure — both containers racing `composer install` into the shared wmb-vendor-<base> volume, first run failing inside composer with a non-125 code — prints raw composer output with no collision hint. Trigger: two same-basename worktrees run concurrently, neither volume pre-existing. Loud failure, no silent corruption. | low | status=open
- bin/docker-test-worktree:62-65 | Auto-build (new in this commit) builds with default APP_UID/APP_GID=1000 when the image is missing, contradicting the usage() guidance four lines below that Linux users build with their own UID/GID; a Linux fresh-cloner gets bind-mount permission failures the docs tried to prevent. macOS/Windows unaffected. | nit | status=open
- bin/docker-test-worktree:106 | Readiness probe checks port 8888 only; published 9999/9991 are never probed. Theoretical (all three listeners come from one Kernel). | nit | status=open

### Round-1 statuses re-checked against 400940b

- COMPOSER_ROOT_VERSION removal — fixed (env line gone from RUN_ARGS).
- var/ artifacts in host worktree — wontfix by design, unchanged.
- --publish foreground flow (AC4) — fixed (detached daemon-only mode, live mappings, Ctrl-C stop).
- docker port after --rm — fixed (container alive during publish mode, `|| true` removed).
- Basename-collision hints — partially fixed (publish path covered; non-publish covers only exit-125 name clash, see round-2 entry above).
- Kernel.php WMB_LISTEN_ADDR / CONTRIBUTING anchor / never-share-var rules — untouched by this commit, verified-ok stands.
- CONTRIBUTING.md publish paragraph — fixed; every claim matches the new code exactly.
- AC3/AC6 end-to-end demonstrations — still unverified this session (no suites run per instructions).

## Round 2 — orchestrator fixes for round-2 review (commit pending)

- bin/docker-test-worktree:115 | readiness loop cannot fail — after 30 failed probes it still printed mappings and claimed success; trigger: daemon fails to bind inside the container (stale cache, broken vendor) → user told to curl dead ports | medium | **fixed** (ready flag + bail-out with `docker logs --tail 20` excerpt and explicit die message; container removed)
- bin/docker-test-worktree:126 | SIGTERM to the helper PID bypassed the stop-trap (bash defers traps until foreground `tail` exits) → scripted shutdown leaked container wmb-<base> | low | **fixed** (`tail -f /dev/null & wait $!` so signals reach bash; trap moved BEFORE container start and now also covers EXIT; uses `docker rm -f`)
- bin/docker-test-worktree:136 | collision hint only on exit 125; same-basename races failing inside composer got no hint | low | **fixed** (hint block now fires for every non-zero status, exit-125 explanation kept as a sub-case)
- bin/docker-test-worktree:62 | auto-build used default APP_UID/GID=1000, contradicting usage() guidance for Linux users | nit | **fixed** (build passes APP_UID/APP_GID from `id -u`/`id -g`)
- bin/docker-test-worktree:106 | readiness probe covered only port 8888 | nit | **fixed** (probe checks all three ports 8888/9999/9991)
- bin/docker-test-worktree (publish sh -c) | NEW during verification: a config.cache.php written by a previous root-context run is rejected by the #586 permission guard when the daemon runs as uid 1000, killing the daemon before readiness | medium | **fixed** (publish command purges `/app/var/cache/dev/workerman/config.cache.php*` before composer install; cache is then regenerated by the runtime user)
- (verification) publish end-to-end re-run after fixes: fresh volume + stale host lock resolved in-container, daemon up on all three ports, host curl through ephemeral port → HTTP 200 "hello from test controller", SIGTERM to helper removed the container (docker ps clean). AC4 demonstrated.

## Round 3 — convergence check (commit 20dd0aa)

Format: `file:line | what | severity | status`

### Round-2 statuses re-checked against 20dd0aa

- bin/docker-test-worktree:127-142 | readiness loop could not fail | medium | **fixed** — `ready=0` flag set to 1 only on probe success; on exhaustion prints `docker logs --tail 20` excerpt indented via `sed 's/^/  /'` (with `|| true`) then `die`s; container removed
- bin/docker-test-worktree:117,151-152 | SIGTERM to helper PID bypassed stop-trap | low | **fixed** — blocker is now `tail -f /dev/null & wait $!`; trap (INT TERM EXIT, `docker rm -f`) installed at :117 before container start. Empirically verified this session: `kill -TERM $$` while waiting on background tail runs the trap immediately and exits 143; no hang; double removal impossible (second `docker rm -f` is a `|| true` no-op)
- bin/docker-test-worktree:160-165 | collision hint fired only on exit 125 | low | **fixed** — outer block fires for every non-zero status; 125 explanation kept as sub-case, shared-volume consequence named
- bin/docker-test-worktree:64-68 | auto-build used default APP_UID/GID=1000 | nit | **fixed** — build passes `--build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g)`; Dockerfile:16-17 declares both ARGs, so Linux fresh-cloners get a matching app user
- bin/docker-test-worktree:129-132 | probe covered only port 8888 | nit | **fixed** — single php -r probes 8888, 9999 and 9991 (short-circuit `||`, exits non-zero unless all three connect)
- bin/docker-test-worktree:109 | stale root-owned config.cache.php* could kill daemon before readiness (#586 guard) | medium | **fixed** — publish `sh -c` purges `/app/var/cache/dev/workerman/config.cache.php*` as root before dropping to app for composer install; cache regenerated by runtime user

### NEW findings in 20dd0aa

- bin/docker-test-worktree:152 | `kill -INT <helper-pid>` (direct signal, not terminal Ctrl-C) does NOT run the stop-trap: bash treats a trapped signal arriving while blocked in `wait` as "resume waiting" when the child outlives the signal — reproduced this session (script stayed blocked after INT; trap ran only on later forced kill). Ctrl-C works because the terminal delivers INT to the whole process group and tail exits; plain `kill -TERM` works (verified). Trigger: shutdown script sending INT to the PID alone. Fix: `wait $! || true`. | low | status=open
- bin/docker-test-worktree:140 | docker log lines ending CRLF keep their CR through `sed 's/^/  /'`, so the new fail-loud excerpt partially overwrites itself on a terminal. Cosmetic, fail-loud path only; `| tr -d '\r'` would fix. | nit | status=open
- bin/docker-test-worktree:138-141 | die()-inside-readiness-failure vs EXIT trap checked: logs are printed BEFORE die(), so cleanup cannot destroy the evidence; double removal impossible (`|| true`). | none | verified-ok
- bin/docker-test-worktree:151-152 | spontaneous death of tail under set -e → wait returns non-zero → EXIT trap removes container: correct, no leak. | none | verified-ok
- bin/docker-test-worktree:109 vs :157 | cache purge is scoped to the publish command string only; non-publish `sh -c` untouched, so non-publish behavior is unchanged. | none | verified-ok
- bin/docker-test-worktree:64-68 | APP_UID auto-build cannot break Docker Desktop users: Dockerfile ARGs default 1000 and Desktop ignores bind-mount ownership anyway; host uid/gid is harmless there and fixes Linux. | none | verified-ok
- CONTRIBUTING.md:236-249 | publish paragraph re-checked claim-for-claim against 20dd0aa (daemon-only, ephemeral mappings, Ctrl-C stops daemon, suite not executed, WMB_LISTEN_ADDR rationale): nothing stated is inaccurate; it omits the 30s readiness gate and purge but that is omission, not error. | none | verified-ok
- (process) | AC3 (three concurrent suites green) / AC6 (two concurrent --publish containers) end-to-end demonstrations remain unverified this session per review constraints (no suites run, no containers started); round-2 orchestrator observed distinct ports across repeated publish smoke tests. | info | open — carried over, not a code defect

Verdict: all five round-2 findings plus the mid-round cache-purge item are fixed with diff evidence; two residual low/nit findings (INT-to-PID wait semantics, CR in log excerpt), neither breaking an acceptance criterion. Converged for practical purposes.

## Round 3 — orchestrator fixes for round-3 residuals

- bin/docker-test-worktree:152 | kill -INT <helper-pid> did not run the stop-trap (bash resumes wait when the child outlives the signal) → scripted INT-only shutdown leaked the container | low | **fixed** (`wait $! || true`; INT now interrupts wait, trap fires, container removed)
- bin/docker-test-worktree:140 | CRLF lines from docker logs kept CR through sed indent, garbling terminal output of the fail-loud excerpt | nit | **fixed** (`tr -d '\r'` before sed)

Verdict: converged. Remaining open items are process notes only (AC3 three-worktree concurrent demo, AC6 two-publish distinct-port demo — AC6 observed informally across this cycle's runs).
