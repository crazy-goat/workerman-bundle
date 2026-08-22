#!/bin/sh
#
# docker-entrypoint.sh — fix named-volume ownership, then drop to the non-root
# `app` user and exec the requested command.
#
# Docker creates named volumes (wmb-var, wmb-vendor) owned by root (UID 0).
# The container's USER is `app`, so without this entrypoint `app` cannot
# create var/cache/dev or vendor/ on first run. We chown the mount points as
# root (the entrypoint runs as root via the Dockerfile's USER root + exec
# switch), then exec the command as `app` via runuser.
#
# When the source tree is bind-mounted at /app (the normal iterative path),
# the bind mount already has the host's ownership — chown is a no-op there
# on macOS/Windows Docker Desktop and correct on Linux when APP_UID/APP_GID
# match the host user.
set -e

# Ensure var/ exists (it may be a freshly-created named volume with nothing in it)
mkdir -p /app/var

# Fix ownership of the named-volume mount points so the app user can write.
chown -R app:app /app/var /app/vendor 2>/dev/null || true

# Drop to the app user and exec the command.
exec runuser -u app -- "$@"
