#!/usr/bin/env bash
# Install Composer dependencies in a fresh worktree.
# Called by bin/worktree.sh after a new worktree is created.
# No containers are started: the test daemon binds its ports on loopback. The
# ports default to 8888/9999/9991 and each worktree can override them with
# WMB_PORT_HTTP/WMB_PORT_SECOND/WMB_PORT_THIRD to run the suite in parallel on
# the host; bin/docker-test-worktree also isolates runs in containers, where
# every container has its own network namespace. The Dockerfile is the only
# container definition and publishes no host ports.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer install --no-interaction --prefer-dist
mkdir -p var
