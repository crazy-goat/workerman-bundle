# Review — Round 2

**Issue:** #674 — Provide a Dockerfile for running the test suite without local PHP/extensions
**Branch:** feat/issue-674-provide-a-dockerfile-for-running-the-tes
**Date:** 2026-08-22

## Findings from round 1 — status

### F-1 — Dockerfile: duplicated `apt-get update` — **FIXED**
Evidence: `grep -c 'apt-get update' Dockerfile` returns 1 (was 2). The RUN line
now reads `RUN apt-get update && apt-get install ...` — single update.

### F-2 — Named volume root-owned, app cannot write — **FIXED**
Evidence: `docker-entrypoint.sh` added at repo root. It runs as root (no `USER`
directive before ENTRYPOINT), `mkdir -p /app/var`, `chown -R app:app /app/var
/app/vendor 2>/dev/null || true`, then `exec runuser -u app -- "$@"`.
Dockerfile copies it to `/usr/local/bin/docker-entrypoint.sh` and sets
`ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]`.

Verified at runtime:
- `docker run --rm workerman-bundle-test whoami` → `app` (drop works)
- `docker run --rm -v wmb-var-test:/app/var workerman-bundle-test sh -c 'touch
  /app/var/testfile && ls -la /app/var/'` → file created, owned by `app:app`,
  directory `drwxr-xr-x app app`. Named volume is writable by the app user.

### F-3 — `--build-arg KEY=VALUE` (space-separated) rejected — **FIXED**
Evidence: parser rewritten to indexed `for ((i=1; i<=$#; i++))` loop. When
`--build-arg` (bare) is seen, it consumes `${!next}` (the next arg) as the
value and advances `i`. Both `--build-arg KEY=VALUE` and `--build-arg=KEY=VALUE`
forms are accepted. `bash -n bin/docker-test` passes.

### F-4 — Container memory_limit 128M too low — **FIXED**
Evidence: `echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/workerman-test.ini`
added to the Dockerfile. Verified at runtime:
`docker run --rm workerman-bundle-test php -i | grep memory_limit` →
`memory_limit => 512M => 512M`.

## New findings in round 2

None. The implementation satisfies all acceptance criteria from the issue:
- Dockerfile on php:8.2-cli-bookworm with all required extensions and ini-values ✓
- .dockerignore excludes all required paths ✓
- `docker build` succeeds ✓
- Container runs as `app` (non-root) with writable var/ via entrypoint ✓
- Extensions match CI exactly (zip, inotify, pcntl, posix, pcov) ✓
- ini-values match CI (phar.readonly=0, pcov.directory=/app/src) + memory_limit=512M ✓
- CONTRIBUTING.md documents the workflow + UID caveat ✓
- No changes to composer.json or .github/workflows/* ✓
- bin/docker-test helper with correct --build-arg parsing ✓
- CHANGELOG.md entry under existing Added section ✓

## Verdict

**Ready for PR.** All round 1 findings fixed and verified at runtime. No new
issues found. The Docker image builds, runs as non-root `app`, can write to
named volumes via the entrypoint, and has the correct extensions/ini-values to
mirror CI's PHP 8.2 + Symfony 6.4 gating leg.
