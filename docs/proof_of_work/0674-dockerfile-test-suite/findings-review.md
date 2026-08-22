# Findings — Review (Round 1)

**Issue:** #674 — Provide a Dockerfile for running the test suite without local PHP/extensions
**Branch:** feat/issue-674-provide-a-dockerfile-for-running-the-tes
**Date:** 2026-08-22

## F-1 — Dockerfile: duplicated `apt-get update`
- **File:** Dockerfile:17
- **Severity:** low
- **Status:** fixed (round 1 → round 2)
- **What is wrong:** The RUN line reads `apt-get update && apt-get update && apt-get install ...` — `apt-get update` is issued twice. The second invocation is redundant (the first already refreshed the index); it is wasted build work and a copy-paste typo. Not behavior-breaking (the install still succeeds), but the image does not do what the comment implies.
- **Trigger:** always (present in every build).
- **Fix:** removed the duplicate `&& apt-get update`, leaving a single `apt-get update` before `apt-get install`.

## F-2 — Named volume `wmb-var` is root-owned; container runs as `app`, so `var/` is unwritable and the suite fails
- **File:** Dockerfile (USER app at line 62; COPY/volume interaction) + bin/docker-test:88 (`-v wmb-var:/app/var`) + CONTRIBUTING.md:172 (documented `docker run ... -v wmb-var:/app/var`)
- **Severity:** high
- **Status:** fixed (round 1 → round 2)
- **What is wrong:** Docker creates named volumes owned by root (UID 0) by default. The image's final `USER app` (UID 1000) therefore cannot create `var/cache/dev` inside the `wmb-var` volume on first run. Observed failure: `Unable to create the "cache" directory (/app/var/cache/dev)` → cache warmup fork signals SIGTERM → `RuntimeException: Cache warmup failed in forked process` (src/Runner.php:132), exit 255. This is the exact run path documented in CONTRIBUTING.md and wrapped by `bin/docker-test`, so acceptance criterion #4 (`docker run ... composer test` passes) is not met. Running as `--user root` gets past this but is not the documented path and hits F-4.
- **Trigger:** any first run of `bin/docker-test` or the CONTRIBUTING `docker run` with a fresh `wmb-var` volume, on any host (Linux or macOS Docker Desktop). The bind-mounted `/app` tree itself is writable (Docker Desktop maps it to the app user); only the named volume is root-owned.
- **Fix:** added `docker-entrypoint.sh` (repo root) that runs as root, `mkdir -p /app/var`, `chown -R app:app /app/var /app/vendor`, then `exec runuser -u app -- "$@"`. The Dockerfile now copies the entrypoint, sets it as ENTRYPOINT, and removes `USER app` (the entrypoint handles the drop). Named volumes are chowned on every container start, so `app` can always write.

## F-3 — `--build-arg KEY=VALUE` (space-separated) is rejected by bin/docker-test
- **File:** bin/docker-test:61-64
- **Severity:** medium
- **Status:** fixed (round 1 → round 2)
- **What is wrong:** The parser matches `--build-arg` (bare) → `die_usage`, and `--build-arg=*` (equals form) → accepted. It never consumes the *next* argument as the value, so the space-separated form `--build-arg APP_UID=1000` — which is the form shown in bin/README.md:196, CONTRIBUTING.md:162, and the script's own usage text (line 19/26/34) — is rejected with `--build-arg needs a KEY=VALUE argument`. Only `--build-arg=APP_UID=1000` works, which is not what the docs instruct. The documented Linux UID/GID build command (`bin/docker-test --build --build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g)`) therefore fails.
- **Trigger:** `bin/docker-test --build --build-arg APP_UID=1000` (space form, as documented).
- **Fix:** rewrote the parser loop to use an indexed `for ((i=1; i<=$#; i++))` loop; when `--build-arg` (bare) is seen, it consumes the next argument (`${!next}`) as the value and advances `i`. Both space-separated and equals forms now work.

## F-4 — Container `memory_limit` defaults to 128M; suite exhausts it
- **File:** Dockerfile (no `memory_limit` set; php:8.2-cli-bookworm default is 128M)
- **Severity:** medium
- **Status:** fixed (round 1 → round 2)
- **What is wrong:** The official `php:*-cli` images ship `memory_limit=128M`. The test suite needs ~150MB (host run reported `Memory: 150.00 MB`) and fatigues inside the container around test 1500/2384 with `Fatal error: Allowed memory size of 134217728 bytes exhausted`. CI's `shivammathur/setup-php` runner inherits a larger limit (host showed 1G), so the image does not actually reproduce a green CI run without raising the limit. The ini drop-in at Dockerfile:41 sets `phar.readonly=0` and `pcov.directory=/app/src` but omits `memory_limit`.
- **Trigger:** `docker run ... composer test` (or `test:coverage`) in the image, once F-2 is worked around (e.g. as root). Breaks acceptance criterion #4/#5.
- **Fix:** added `memory_limit=512M` to the ini drop-in (`/usr/local/etc/php/conf.d/workerman-test.ini`) alongside the existing `phar.readonly=0` and `pcov.directory=/app/src`. 512M gives comfortable headroom over the ~150MB the suite needs.
