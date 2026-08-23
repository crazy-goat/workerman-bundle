# 0676 — Review round 3 (convergence check, commit 20dd0aa)

Method: read findings-review.md Round 2 section + `git show 20dd0aa`; static review of
bin/docker-test-worktree; `bash -n` clean; isolated bash simulations of trap/wait/INT/TERM
semantics. No suites run, no containers started (per constraints).

## Round-2 statuses — all fixed

1. Readiness loop cannot fail (medium) — FIXED. bin/docker-test-worktree:127-142:
   `ready=0`, set to 1 only when the three-port probe succeeds; on exhaustion prints a
   `docker logs --tail 20` excerpt (indented via sed, `|| true`) and dies with an explicit
   message; container removed.
2. SIGTERM bypassing the stop-trap (low) — FIXED. Blocker is `tail -f /dev/null & wait $!`
   (:151-152); trap INT TERM EXIT installed at :117 BEFORE container start; handler uses
   `docker rm -f`. Verified empirically this session: TERM delivered to the shell while
   blocked in `wait` runs the trap immediately and exits 143 — no hang. Double removal is
   impossible (second `docker rm -f ... || true` is a no-op).
3. Collision hint only on exit 125 (low) — FIXED. :160-165 fires for every non-zero status;
   125 name-clash explanation kept as a sub-case; shared wmb-vendor volume consequence named.
4. Auto-build default UID/GID=1000 (nit) — FIXED. :64-68 passes APP_UID/APP_GID from
   id -u/id -g; Dockerfile:16-17 declares both ARGs.
5. Probe covered only port 8888 (nit) — FIXED. :129-132 probes 8888, 9999, 9991 in one
   php -r (short-circuit ||).
6. Stale root-owned config.cache.php* killing the daemon before readiness (medium, found
   during round-2 verification) — FIXED. Publish sh -c purges
   /app/var/cache/dev/workerman/config.cache.php* as root before composer install (:109).

## NEW findings

- bin/docker-test-worktree:152 | `kill -INT <helper-pid>` (direct signal, not terminal
  Ctrl-C) does not run the stop-trap: bash treats a trapped signal arriving while blocked in
  `wait` as "resume waiting" when the child outlives the signal. Reproduced locally: script
  stayed blocked after INT; trap ran only on a later forced kill. Ctrl-C works because the
  terminal delivers INT to the whole process group (tail exits); plain `kill -TERM` works
  (verified). Trigger: a shutdown script that sends INT to the helper PID alone. Fix:
  `wait $! || true`. | low | open
- bin/docker-test-worktree:140 | docker log lines ending CRLF keep their CR through
  `sed 's/^/  /'`; on a terminal the CR repositions the cursor and partially overwrites the
  indented fail-loud excerpt. Cosmetic, new fail-loud path only; `| tr -d '\r'` would fix.
  | nit | open

## Checked and clean (no defect)

- EXIT trap + INT/TERM coexistence: one trap line registers all three; handler idempotent.
- die() inside readiness failure prints logs BEFORE exiting → cleanup cannot destroy the
  evidence; no double removal.
- Spontaneous death of tail under set -e → wait non-zero → EXIT trap removes container:
  correct, no leak.
- Cache purge scoped to publish command string only (:109); non-publish sh -c (:157)
  untouched — non-publish behavior unchanged.
- APP_UID auto-build safe for Docker Desktop users (ARG defaults 1000; Desktop ignores
  bind-mount UIDs regardless); fixes Linux fresh-cloners as intended.
- CONTRIBUTING.md:236-249 publish paragraph matches code claim-for-claim; omits the 30s
  readiness gate and purge but states nothing inaccurate.
- Multi-line php -r probe quoting: single-quoted heredoc-free string inside an if condition,
  `$e`/`$s` expanded in-container, not by bash — correct.

## Unverified (carried over)

AC3 (three concurrent suite runs green) and AC6 (two concurrent --publish containers with
distinct ports) were not demonstrated this session per review constraints; round-2 notes
observed distinct ports across repeated publish smoke tests.

## Verdict

All round-2 items fixed with diff evidence. Two residual low/nit findings (INT-to-PID wait
semantics, CR in log excerpt), neither breaking an acceptance criterion. Converged for
practical purposes.
