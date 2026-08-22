# Test-suite image for workerman-bundle.
#
# Mirrors the most restrictive CI leg — PHP 8.2 + Symfony 6.4, with PCOV
# coverage — so a green local Docker run reproduces the CI gate that blocks
# a merge. Contributors get the full suite, coverage check and lint without
# installing PHP, extensions or Composer on the host.
#
# See CONTRIBUTING.md ("Running tests in Docker") for usage.

FROM php:8.2-cli-bookworm

# Build-args let a Linux contributor match the image's app user to their host
# UID/GID so bind-mounted var/ and .git stay writable. macOS/Windows users
# can keep the 1000/1000 defaults — Docker Desktop's bind mounts do not
# honour UIDs anyway.
ARG APP_UID=1000
ARG APP_GID=1000

# System dependencies: git for Composer/coverage tooling, unzip for Composer
# zip extraction, libzip-dev/libinotifytools-dev as build headers for the PHP
# extensions below, procps so tests that inspect process state (the daemon
# start/stop cycle) work as they do on CI's ubuntu-latest.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libinotifytools-dev \
        procps \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions matching CI's shivammathur/setup-php `extensions:` list.
# pcntl, posix and zip ship as bundled extensions; inotify and pcov come
# from PECL. pcov is the coverage driver CI uses on the gating leg.
RUN docker-php-ext-install pcntl posix zip \
    && pecl install inotify pcov \
    && docker-php-ext-enable inotify pcov

# php.ini drop-in mirroring CI's `ini-values`:
#   pcov.directory=src, phar.readonly=0
# pcov.directory scopes coverage to /app/src (the image WORKDIR); the absolute
# path is required because PCOV resolves it at request time, not relative to
# the process CWD. phar.readonly=0 lets the PHAR build/build:bin tests run.
# memory_limit=512M is NOT set by CI (the runner inherits a larger limit from
# the host), but php:*-cli ships 128M and the suite needs ~150 MB, so the
# image must raise it explicitly to reproduce a green CI run.
RUN echo "phar.readonly=0" > /usr/local/etc/php/conf.d/workerman-test.ini \
    && echo "pcov.directory=/app/src" >> /usr/local/etc/php/conf.d/workerman-test.ini \
    && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/workerman-test.ini

# Composer, copied from the official composer:2 image so its version tracks
# the upstream release without a separate install step.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Non-root user `app` with configurable UID/GID. Created before the source
# copy so the image can run as app without root, matching the production
# guidance in docs/security.md ("Containerised deployments").
RUN groupadd -g "${APP_GID}" app \
    && useradd -u "${APP_UID}" -g app -m -s /bin/bash app

# Copy the repository and install dependencies. --no-scripts skips the
# post-install git-hook installer (no host git repo inside the build) and
# --prefer-dist matches CI. The image is self-contained: no bind mount is
# required to run the suite, though one is recommended for iteration.
COPY --chown=app:app . /app
RUN composer install --no-scripts --prefer-dist --no-interaction

# Entrypoint fixes named-volume ownership (Docker creates wmb-var/wmb-vendor
# root-owned; the app user cannot write to them without this) then drops to
# the non-root `app` user via runuser. The container starts as root so the
# chown succeeds; the actual command runs as app.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["composer", "test"]
