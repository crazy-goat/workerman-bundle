# 0676 — Review round 2 (commit 400940b)

Scope: round-1 fixes in bin/docker-test-worktree (detached --publish daemon
mode, COMPOSER_ROOT_VERSION removal, image auto-build, collision hints),
CONTRIBUTING.md publish paragraph rewrite, findings-review.md status updates.
Method: full `git show 400940b` diff read against e3a9f82; static bash
analysis (`bash -n` clean); read-only docker probes (image present, engine
29.7.2). Full suite NOT run, no images built, per instructions.

## Round-1 finding statuses

1. COMPOSER_ROOT_VERSION=1.0.0 (low) — **fixed**. The `-e` line is gone from
   RUN_ARGS (bin/docker-test-worktree:95-98); the non-publish command is now
   byte-identical in env to bin/docker-test/CI (`composer install
   --no-scripts --no-interaction && composer <cmd>`).
2. var/ artifacts land in the host worktree (nit) — **wontfix by design**,
   unchanged, matches the issue's own mitigation text. No action needed.
3. --publish foreground flow cannot demonstrate AC4 (medium) — **fixed**.
   Publish branch now runs `-d` + `restart -d` + `tail -f /dev/null`
   (lines 100-106), prints mappings while the container lives (123), blocks
   until Ctrl-C (126-128), then stops the daemon so `--rm` removes the
   container. Matches the issue's 3c recipe; suite not executed in this mode.
4. docker port queried after --rm removed the container (low) — **fixed**.
   Container stays alive through publish mode; `|| true` removed (123).
5. Basename-collision error messages (low) — **partially fixed**.
   Publish-path docker-run failure names the clash and the cleanup command
   (109-110); non-publish path hints only when docker exits 125 (136-139);
   a first-run vendor-volume name clash surfaces as plain `docker run` exit 1
   with no hint (see NEW-3).
6. tests/App/Kernel.php WMB_LISTEN_ADDR fallback — not touched by 400940b;
   round-1 verified-ok stands.
7. CONTRIBUTING anchor — not touched by 400940b; verified-ok stands.
8. CONTRIBUTING.md overstates publish usefulness (medium) — **fixed**. The
   rewritten paragraph (CONTRIBUTING.md:237-248) states exactly what the new
   code does: starts ONLY the daemon detached, prints mappings, blocks until
   Ctrl-C which stops it, suite not executed. No mismatch found.
9. Never-share-var/ + per-worktree vendor rules — untouched, verified-ok.
10. AC3 (three concurrent green runs) / AC6 (two concurrent --publish
    containers, distinct ports demonstrated) — **still UNVERIFIED**; this
    session ran no suites per instructions. AC4 itself is now demonstrable by
    construction (daemon-only detached mode).

## New findings

NEW-1. **bin/docker-test-worktree:115-120 — readiness loop cannot fail;
       mappings printed even when the daemon never came up** (medium)
       The probe loop runs `docker exec ... fsockopen(127.0.0.1, 8888)` up to
       30 times but discards the result: break on success, otherwise sleep
       and fall through. After 30 failed probes (~30s) it proceeds to print
       "published host ports" and "daemon running; curl the ports above".
       Trigger: the daemon fails to start inside the container (bad worktree
       state, missing vendor deps in a fresh volume, port already bound by a
       leftover process from an earlier crashed run sharing the same var/
       pid file). The user is told to curl dead ports with no error and the
       helper exits 0 on Ctrl-C. The loop should track success and die with
       a message (e.g. `docker logs wmb-<base>`) when exhausted.

NEW-2. **bin/docker-test-worktree:126-128 — SIGTERM during `tail -f
       /dev/null` bypasses the trap; the container leaks** (low)
       The trap handles INT TERM, but bash defers a trap set for SIGTERM
       while waiting on the foreground child (`tail`) until that child
       exits — and nothing terminates `tail`. On `kill <pid>` (the natural
       way to stop a backgrounded publish session, or `--publish` used from
       a script/supervisor sending TERM to the process group... TERM to the
       group does reach tail, but a direct TERM to the helper PID does not):
       bash's wait is interrupted, the trap runs `docker stop`, but the
       shell can then exit before/while stopping — observed semantics vary;
       worst case the script dies with the container still running and
       `--rm` never fires, leaving `wmb-<base>` up and blocking its own
       name for the next run. Ctrl-C (SIGINT to the foreground process
       group) is fine — tail dies, trap runs, container stops. Fix: run the
       waiter as `tail -f /dev/null & wait $!` or `sleep infinity & wait`,
       so both signals interrupt `wait` directly.

NEW-3. **bin/docker-test-worktree:136-139 — non-publish collision hint
       misses the most common first-run failure: the vendor-volume name
       clash** (low)
       The hint fires only on docker exit code 125. Two same-basename
       worktrees run concurrently do hit 125 on the second container
       (name in use) — covered. But the FIRST of the two runs can instead
       fail inside `composer install` (exit 1 or higher, propagated via
       `exit "$STATUS"` at 140) because both containers mount the SAME
       `wmb-vendor-<base>` volume and race their installs into it; that
       path prints raw composer output with no collision hint. Trigger:
       `bin/docker-test-worktree ../a/wmb-fix & bin/docker-test-worktree
       ../b/wmb-fix` where neither volume existed before. Severity kept low:
       loud failure, no corruption beyond a possibly mixed vendor tree that
       the next solo run re-resolves.

NEW-4. **bin/docker-test-worktree:62-65 — auto-build ignores the
       APP_UID/APP_GID guidance printed four lines later** (nit)
       usage() (51-53) tells Linux users to build once with
       `--build-arg APP_UID=$(id -u) APP_GID=$(id -g)`, but the new
       auto-build (added in this commit) builds silently with defaults when
       the image is missing, so a Linux fresh-cloner gets a 1000/1000 image
       and bind-mount permission failures that the docs explicitly tried to
       prevent. Cosmetic-to-annoying on Linux only; macOS/Windows unaffected.

NEW-5. **bin/docker-test-worktree:106 — readiness probe covers port 8888
       only** (nit)
       9999/9991 are published and curlable targets too; a daemon that binds
       8888 but fails the other two listeners would pass the probe. Purely
       theoretical given all three come from one Kernel; noting for
       completeness.

## Verified-clean areas

- Non-publish path behavior vs pre-commit: identical flow (set +e → docker
  run → STATUS → set -e → exit "$STATUS"); COMPOSER_ROOT_VERSION removal
  makes it match bin/docker-test exactly. Exit-code propagation intact.
- `bash -n`: clean.
- Readiness probe quoting: single-quoted php -r snippet survives the sh -c
  single-quote nesting correctly (double quotes inside); `exit((int) !...)`
  yields 0/1 as intended; `docker exec` failure mid-loop (container dying)
  is absorbed by the `if` — safe, though it feeds NEW-1's silent path.
- Trap string expansion: BASENAME is expanded at definition time inside the
  single-quoted trap body — correct, since the variable cannot change after
  parsing; no word-splitting hazard in this context.
- CONTRIBUTING.md rewrite vs code: every claim (only-daemon, detached,
  ephemeral -p list, WMB_LISTEN_ADDR=0.0.0.0, prints docker port, blocks
  until Ctrl-C, Ctrl-C stops daemon, suite not executed) matches the code.
- CHANGELOG.md section structure preserved (Added block extended in place;
  Changed heading re-created further down; check-changelog constraints hold).
- Image auto-build guard placement: after the docker-presence check, before
  arg parsing — a typo'd `--publsh` now triggers a multi-minute build before
  dying with usage; wasteful but harmless (see NEW-4's family).

## Acceptance criteria after round 2

1, 2, 5, 7 — MET (unchanged from round 1).
4 — MET by construction now: `--publish` keeps a live daemon behind the
    printed mappings while the helper blocks; host curl has a working
    target (round-1 fix note records an end-to-end 200 verification).
3, 6 — still UNVERIFIED this session (no suites run per instructions);
    Docker's ephemeral-port allocator makes 6 near-certain but undemonstrated.
