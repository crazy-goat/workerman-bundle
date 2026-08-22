# Code Decision — Round 1

**Issue:** #674 — Provide a Dockerfile for running the test suite without local PHP/extensions
**Branch:** feat/issue-674-provide-a-dockerfile-for-running-the-tes
**Date:** 2026-08-22

## Approach

Added a self-contained test image at the repo root, plus a `.dockerignore` to
keep the build context clean, a `bin/docker-test` shell helper for the common
bind-mount run, and a CONTRIBUTING.md subsection documenting the workflow.

### Dockerfile

Base image `php:8.2-cli-bookworm` — Debian, matching CI's `ubuntu-latest`
family and avoiding Alpine/musl PECL friction. PHP 8.2 is the oldest CI
version and the leg that carries the coverage gate (PHP 8.2 + Symfony 6.4),
so a green local Docker run mirrors the most restrictive CI leg.

Extensions installed to match CI's `shivammathur/setup-php` `extensions:` list
exactly: `zip, inotify, pcntl, posix` + coverage driver `pcov`. `pcntl`,
`posix`, `zip` are bundled (installed via `docker-php-ext-install`); `inotify`
and `pcov` come from PECL. System packages: `git`, `unzip`, `libzip-dev`,
`libinotifytools-dev`, `procps` (procps so process-state inspection in the
daemon start/stop tests works as on CI's ubuntu-latest).

php.ini drop-in (`/usr/local/etc/php/conf.d/workerman-test.ini`) sets
`phar.readonly=0` and `pcov.directory=/app/src`, mirroring CI's `ini-values:
pcov.directory=src,phar.readonly=0`. The absolute path is used because PCOV
resolves it at request time, not relative to the process CWD.

Composer is copied from the official `composer:2` image so its version tracks
upstream without a separate install step.

Non-root user `app` with configurable `APP_UID`/`APP_GID` build-args (defaults
1000/1000) so bind-mounted `var/` and `.git` stay writable on Linux. macOS /
Windows Docker Desktop bind mounts do not honour UIDs, so the defaults are
fine there.

`COPY . /app` + `composer install --no-scripts --prefer-dist --no-interaction`
makes the image self-contained (no bind mount required to run the suite,
though one is recommended for iteration). `--no-scripts` skips the
post-install git-hook installer (no host git repo inside the build).
`CMD ["composer", "test"]` runs the full suite by default.

### .dockerignore

Excludes `vendor/`, `e2e/vendor/`, `var/`, `e2e/build/`, `composer.lock`,
`e2e/composer.lock`, `.git/`, `.pi-subagents/`, `.php-cs-fixer.cache`,
`var/.php-cs-fixer.cache`. This mirrors `.gitignore` where the paths are
git-ignored and adds tool caches that are not version-controlled but can
exist on a contributor's checkout. `composer.lock` is excluded because it is
platform-specific — the image resolves its own.

### bin/docker-test

A bash helper (`set -euo pipefail`, matching `bin/gh-branch` style) that
wraps the bind-mount run with named volumes (`wmb-vendor`, `wmb-var`) for
deps and artifacts. Builds the image automatically on first use or when
`--build` is passed. Supports `--build-arg KEY=VALUE` forwarding for UID/GID.

### CONTRIBUTING.md

New `### Running tests in Docker (no local PHP needed)` subsection under
`### Before Submitting a PR`, with build + run commands, the in-container
ports note (8888/9999/9991 on 127.0.0.1, no `-p` needed), and the
macOS/Linux UID caveat.

### CHANGELOG.md

Entry added under the existing `### Added` subheading in the `[Unreleased]`
block (Keep a Changelog format, issue reference included).

## What was rejected

- **Alpine base** — rejected: musl/PECL friction for `inotify` and `pcov`;
  Debian bookworm matches CI's ubuntu family.
- **docker-compose.yml** — rejected: the issue asks for a Dockerfile + helper,
  not an orchestration file. The bind-mount + named-volume run is simple enough
  for a one-command helper.
- **ARG PHP_VERSION=8.2 parameterisation** — explicitly listed as out-of-scope
  follow-up in the issue.
- **xdebug variant** — explicitly listed as out-of-scope follow-up.
- **CI job that builds the image** — explicitly listed as out-of-scope follow-up.
- **Changes to composer.json scripts or .github/workflows/*** — the issue
  forbids this.

## Uncertainties

- The `.dockerignore` excludes `composer.lock` (platform-specific). The image
  resolves its own lockfile via `composer install`. This is correct for a test
  image but differs from the git-tracked `composer.lock` — the image will pin
  the latest matching deps, not the exact CI versions. This is acceptable for
  the stated goal (reproducible-enough local testing) and matches the issue's
  proposed `.dockerignore`. A follow-up could pin the lockfile inside the image.

- The `pcov.directory=/app/src` uses an absolute path. CI uses the relative
  `pcov.directory=src` (resolved relative to the GITHUB_WORKSPACE). The absolute
  form is safer inside a container where the CWD may not always be `/app`.

- The image does not set `WORKERMAN_ALLOW_PCNTL_SKIP=1` — since `pcntl` and
  `posix` are installed, the signal-logic tests run as on CI, which is the
  intended behaviour.
