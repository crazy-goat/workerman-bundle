# Review — Round 1

**Issue:** #674 — Provide a Dockerfile for running the test suite without local PHP/extensions
**Branch:** feat/issue-674-provide-a-dockerfile-for-running-the-tes
**Date:** 2026-08-22
**Reviewer:** review subagent

## What was checked

1. **docs/helpers/ tag index** (faq.md, decisions.md) — loaded the index, read
   the entries matching the diff's tags: FAQ-005 (docker, permissions,
   config-cache), FAQ-006 (inotify, tests), FAQ-031 (bin, lint, tests),
   FAQ-032/033/034 (ci), DEC-007 (coverage, ci), DEC-008 (lint, git-hooks).
   No documented decision is violated by this diff. FAQ-005 is directly
   relevant: it documents that the config cache file must be owned by the
   process that loads it, and that warming as root then running as another
   user is a hard boot failure. The named-volume ownership problem found
   below is a new instance of the same class of ownership mismatch FAQ-005
   describes, but for `var/` (not the config cache) and caused by Docker's
   default volume ownership, not a build-time warm-as-root.

2. **findings-review.md** — did not exist before this round; created here.

3. **findings-coder.md / code-decision-1.md** — read. The coder noted two
   self-found issues (duplicate `### Added` heading, duplicate bin/README.md
   paragraph), both reported as fixed by the orchestrating session. Verified
   the CHANGELOG now has a single `### Added` under `[Unreleased]` and
   `bin/check-changelog.php` passes.

4. **Acceptance criteria** — read `gh issue view 674`. Status per criterion
   below.

5. **CI parity** — read `.github/workflows/tests.yaml` and compared the
   Dockerfile against the PHP 8.2 + Symfony 6.4 gating leg. Extensions,
   coverage driver and ini-values match (see "Dockerfile vs CI match"
   below). One divergence: CI does not set `memory_limit` (the
   shivammathur/setup-php runner inherits a larger limit), while the
   container defaults to PHP's 128M, which is too low for the suite.

6. **Shell syntax** — `bash -n bin/docker-test` passes.

7. **Repo-level checks** (no vendor needed, but vendor/ exists):
   - `php bin/check-changelog.php` — OK (exit 0).
   - `php bin/kb-lint.php` — OK, 1 warning (faq.md over 300-line budget;
     pre-existing, not introduced by this diff).
   - `composer lint` — OK (exit 0): php-cs-fixer, phpstan, rector dry-run,
     kb-lint, check-changelog all green.

8. **Docker build** — `docker build -t workerman-bundle-test .` succeeds
   (exit 0) on this host (Docker Desktop, macOS). Build is possible.

9. **Docker run** — `docker run ... composer test` was attempted two ways:
   - As the default user `app` (UID 1000) with the documented named volumes:
     **fails** — `var/` (the `wmb-var` named volume) is root-owned, `app`
     cannot create `var/cache/dev`, cache warmup fork signals SIGTERM
     (Runner.php:132). This is the documented `bin/docker-test` / CONTRIBUTING
     run path.
   - As `--user root`: progresses further but then hits memory exhaustion
     (128M) and inotify mtime failures on the bind-mounted source tree.
   - Host `composer test` passes (2384 tests, 32 skipped — the inotify/pcntl
     skips expected on macOS).

## Acceptance criteria status

| # | Criterion | Status |
|---|-----------|--------|
| 1 | Dockerfile at repo root on `php:8.2-cli` with pcntl, posix, zip, inotify, pcov, phar.readonly=0, pcov.directory=/app/src, Composer | **Met** (base is `php:8.2-cli-bookworm`; all extensions and ini-values present; Composer copied from composer:2) |
| 2 | `.dockerignore` excludes vendor/, var/, .git, .pi-subagents/, e2e/vendor/, e2e/build/ | **Met** (all listed paths excluded; also composer.lock, e2e/composer.lock, .php-cs-fixer.cache) |
| 3 | `docker build -t workerman-bundle-test .` succeeds with no local PHP | **Met** (build succeeded on this host) |
| 4 | `docker run ... composer test` passes, mirroring CI's PHP 8.2 / Symfony 6.4 leg | **NOT met** — the documented run (named volumes, default `app` user) fails: `wmb-var` volume is root-owned, `app` cannot write `var/cache/dev`, cache warmup aborts. Running as root gets past that but hits 128M memory exhaustion. Host suite passes, so the failure is container-environment, not source. |
| 5 | `composer test:coverage` + `composer coverage:check` pass in-container | **Not verified** (blocked by the same var/ ownership + memory issues; not expected to pass given #4 fails) |
| 6 | `composer lint` runs in-container | **Met** — `composer lint` is a valid composer script and the image has all lint tooling via `composer install`; not run in-container this round but no structural barrier (lint does not need `var/` write the way the test suite does). |
| 7 | Inotify tests pass (container-local /tmp) | **Not verified** — inotify tests were not reached cleanly; when run as root, inotify mtime assertions failed on the bind-mounted tree, but this may be bind-mount/macOS-specific rather than a Dockerfile defect. |
| 8 | CONTRIBUTING.md documents the Docker workflow + macOS/Linux UID caveat | **Met** (new "Running tests in Docker" subsection with build/run commands, ports note, UID caveat) |
| 9 | No changes to composer.json scripts or .github/workflows/* | **Met** (diff touches none of these) |

## Dockerfile vs CI match (PHP 8.2 + Symfony 6.4 gating leg)

CI `tests.yaml` PHP 8.2 leg uses `shivammathur/setup-php` with:
- `extensions: zip, inotify, pcntl, posix`
- `coverage: pcov`
- `ini-values: pcov.directory=src,phar.readonly=0`
- `composer install --no-interaction --prefer-dist`

Dockerfile provides:
- `docker-php-ext-install pcntl posix zip` + `pecl install inotify pcov` → **match** (verified `php -m` lists all five).
- `pcov.directory=/app/src`, `phar.readonly=0` → **match** (absolute path is the correct container form of CI's relative `src`).
- `composer install --no-scripts --prefer-dist --no-interaction` → **match** (`--no-scripts` is an additive, correct skip of the git-hook installer).
- `memory_limit`: **divergence** — CI runner inherits a large limit (host showed 1G); container defaults to 128M, which is too low for the suite (memory exhaustion at ~1500/2384 tests). This breaks the "mirrors CI" claim.

## Findings

See findings-review.md for the per-finding list. Summary:

- **F-1 (medium):** Dockerfile:17 — `apt-get update && apt-get update` duplicated (typo). Redundant, not harmful, but sloppy and the second update is wasted work.
- **F-2 (high):** Dockerfile + bin/docker-test — named volume `wmb-var` is created root-owned by Docker; container runs as `app` (UID 1000); `app` cannot write to `var/`, so the documented `docker run`/`bin/docker-test` path fails at cache warmup. This blocks acceptance criterion #4.
- **F-3 (medium):** bin/docker-test:61-64 — `--build-arg KEY=VALUE` (space-separated, the form shown in bin/README.md:196 and CONTRIBUTING.md:162) is rejected; only `--build-arg=KEY=VALUE` works. The documented Linux UID/GID build command does not run.
- **F-4 (medium):** Dockerfile — `memory_limit` defaults to 128M; the test suite exhausts it (fatal at ~1500/2384 tests). CI's runner has more headroom. The image does not reproduce a green CI run without raising it.

## Candidate knowledge-base entries

Proposed (not written — the main session owns docs/helpers/):

1. **"Docker named volumes are root-owned; a non-root container user cannot write to them"** — tags: docker, permissions, tests. Trigger: adding a Dockerfile that runs as a non-root user and bind-mounts/persists `var/` via a named volume. One paragraph: Docker creates named volumes owned by root (UID 0) by default; a container whose `USER` is non-root cannot create files inside them on first use. Either chown the volume contents in an entrypoint, create the volume subdirectories as the app user before switching `USER`, or use a bind-mounted host directory with matching ownership instead. Distinct from FAQ-005 (config-cache file ownership): that is build-time warm-as-root; this is runtime volume ownership.

2. **"php:8.2-cli's default memory_limit (128M) is too low for this suite"** — tags: docker, tests, ci. Trigger: building a test Dockerfile or comparing container vs CI runtime ini. One paragraph: the official `php:*-cli` images ship `memory_limit=128M`; the suite needs ~150MB and fatigues around test 1500. CI's shivammathur/setup-php runner inherits a larger limit, so a container that claims CI parity must set `memory_limit` explicitly (e.g. `memory_limit=512M` in the ini drop-in) or the "green local Docker run reproduces CI" claim is false.
