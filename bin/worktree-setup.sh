#!/usr/bin/env bash
# Install Composer dependencies in a fresh worktree.
# Called by bin/worktree.sh after a new worktree is created.
# No containers are started: the test daemon binds its ports (8888, 9999, 9991) on
# loopback, so parallel worktrees run the suite with bin/docker-test-worktree, where
# every container has its own network namespace. The Dockerfile is the only
# container definition and publishes no host ports.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer install --no-interaction --prefer-dist
mkdir -p var
