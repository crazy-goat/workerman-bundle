# Deployment

This page shows how to run the bundle in production: on a Linux server with systemd, in Docker and in Kubernetes.
It covers the user, OPcache, the unit file, the deploy script, the Dockerfile, the logs and the stop time.

## Checklist

- The host has the [requirements](#requirements-on-the-host).
- One Unix user runs the server and owns the cache (see [the user](#user-and-group)).
- OPcache is set on purpose (see [OPcache](#opcache)).
- A systemd unit starts the server in the foreground (see [the unit](#systemd-unit)).
- The deploy script warms up the cache as the runtime user, then restarts or reloads the server (see [deploy script](#deploy-script)).
- In Docker, the image warms up the cache as the runtime user (see [Docker](#docker)).
- The stop time is longer than your longest request (see [stop time](#stop-time-and-graceful-stop)).

## Requirements on the host

- PHP 8.2 or newer, with `ext-pcntl` and `ext-posix`.
- Linux or macOS. Windows is not supported.
- A writable place for the cache, the logs and the PID file.
  By default these are `var/cache`, `var/log` and `var/run` in your project.
  See `runtime_dir`, `pid_file`, `log_file` and `stdout_file` in [Configuration](configuration.md#top-level-keys).

The optional extensions are in [Getting started](getting-started.md#requirements).

## User and group

Run the server as a normal user, for example `www-data`.
Do not run it as `root`.

There are two ways to do this:

1. Let systemd start the process as that user with `User=` and `Group=` (see the unit below).
   The master process, the workers and `cache:warmup` all use the same user.
   This is the simple way, and the one we suggest.
2. Start the server as `root` and set `user` and `group` in `config/packages/workerman.yaml` (see [Configuration](configuration.md#top-level-keys)).
   Each worker changes its user and group after it is forked.
   The master process stays `root`.
   Then the master reads the config cache as `root`, so `root` must own the cache file.
   The workers must still be able to write to the Symfony cache and log directories.

The default of both keys is `null`: the current user and group.

## OPcache

Workers are PHP CLI processes, so OPcache must be switched on for the CLI:

```ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
```

Both values of `opcache.validate_timestamps` work with `reload`.
On a reload, the master process invalidates every script in its OPcache (`Utils::clearOpcache()`).
The new workers are forked from the master and use that cache, so they read the new files.
With `opcache.validate_timestamps=0`, PHP never looks at the files by itself.
This is the fastest setting, and it is safe because of the invalidation on reload.

A separate PHP process cannot reset the OPcache of the server.
The OPcache of the server lives in the server's own process tree.
So `opcache_reset()` in a deploy script does nothing for the running workers.
See [Troubleshooting](troubleshooting.md#opcache-caveats) for the symptoms.

## systemd unit

Start the server in the foreground: do not use `-d`.
systemd then knows the main process and can watch it.

Save this as `/etc/systemd/system/myapp.service`:

```ini
[Unit]
Description=My app (Workerman)
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/myapp/current
Environment=APP_ENV=prod
ExecStart=/usr/bin/php bin/console workerman:server start
ExecReload=/usr/bin/php bin/console workerman:server reload
KillSignal=SIGINT
KillMode=mixed
TimeoutStopSec=10
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

What the important lines do:

- `ExecStart` runs `start` with no `-d`. The command stays in the foreground.
- `ExecReload` makes `systemctl reload myapp` run the `reload` command.
  It runs as the same `User=`, so it finds the master with the PID file.
- `KillSignal=SIGINT` is the signal that `workerman:server stop` sends to the master process.
  The master stops the workers and ends.
  The default signal of systemd is `SIGTERM`, which the master handles in the same way.
  We use the same signal as `stop`.
- `KillMode=mixed` sends the signal to the main process only.
  If something is still alive after `TimeoutStopSec`, systemd sends `SIGKILL` to all the processes of the unit.
- `TimeoutStopSec` must be larger than `stop_timeout`.
  After the signal, the master gives the workers `stop_timeout` seconds (default 2) and then kills them.
  If systemd waits less than that, it kills the workers while they still finish.
  The value 10 is fine for a `stop_timeout` of 2 to 5.
- `Restart=always` starts the server again when it ends for any reason, for example a crash.
  `systemctl stop` does not trigger a restart.
  But a `bin/console workerman:server stop` that you run by hand does: use `systemctl stop` instead.

The signal `SIGQUIT` (a graceful stop) is not used here.
There is an open report about a graceful stop that does not end (see [issue 911](https://github.com/crazy-goat/workerman-bundle/issues/911)).

Enable and start it:

```bash
systemctl daemon-reload
systemctl enable --now myapp
```

## Deploy script

The master process reads the config cache and the settings in `workerman.yaml` once, at start.
A `reload` does not read them again.

In `prod` (debug is off), the server does not compare `workerman.yaml` with the cache file.
If `var/cache/prod/workerman/config.cache.php` exists, the server loads it, even when you changed `workerman.yaml` after it was made.
So after an in-place change of `workerman.yaml`, services or tags, run `bin/console cache:clear` as the runtime user (or remove `var/cache/prod`) before you restart.
In debug mode, the server builds a stale cache again by itself.

So choose by what changed:

| What changed | What to run |
|--------------|-------------|
| Only the code inside methods, changed in place | `reload` |
| `workerman.yaml`, services, constructor arguments, routes, `#[AsTask]` or `#[AsProcess]` attributes, listen addresses, or the number of workers, changed in place | `cache:clear` as the runtime user, then `restart` |
| A new release directory with a symlink switch | `restart` (the new release must have its own warm cache) |

With systemd, run them as `systemctl reload myapp` and `systemctl restart myapp`.
While `restart` runs, the server does not answer for a moment.
See [Commands](commands.md#stop-restart-and-reload) for the signals and the wait times.

A deploy script. It runs as `root`, or as a user who may use `sudo` and `systemctl`:

```bash
#!/bin/sh
set -eu

RELEASE=/var/www/myapp/releases/$(date +%Y%m%d%H%M%S)

# 1. Put the new code in a new directory and install it.
git clone --depth 1 https://example.com/myapp.git "$RELEASE"
cd "$RELEASE"
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Give the runtime user the files it must write.
mkdir -p "$RELEASE/var"
chown -R www-data:www-data "$RELEASE/var"

# 3. Warm up the cache AS THE RUNTIME USER.
sudo -u www-data APP_ENV=prod php bin/console cache:warmup

# 4. Switch the symlink, then restart.
ln -sfn "$RELEASE" /var/www/myapp/current
systemctl restart myapp
```

Step 3 is the one that people forget: see the next section.
The server also warms up the cache itself at start when the cache file is missing (in `prod`) or out of date (in debug mode).
Then the user of the unit owns the cache, so this is safe, but the start takes longer.

## Config cache and the runtime user

The bundle keeps your configuration in a cache file: `{cacheDir}/workerman/config.cache.php`, for example `var/cache/prod/workerman/config.cache.php`.
This is a PHP file, and the server loads it with `require` at start.
So, since 0.25.0, the server refuses to load a file that the loading process does not own.
The check runs in the first process, before the workers are forked.
A wrong owner stops `workerman:server start` with a `RuntimeException`.

The message names both user ids:

```
The configuration cache file "/app/var/cache/prod/workerman/config.cache.php" is owned by uid 0,
not by the current process user (uid 33). The file may have been replaced by another user.
Ensure the cache is written by the same user that loads it (e.g., warm up with the runtime
user, or chown the cache to that user).
```

The fix is one of these:

- **Warm up as the runtime user** (best):

  ```bash
  sudo -u www-data APP_ENV=prod php bin/console cache:warmup
  ```

- **Change the owner of the whole cache after the warm-up** (the `workerman` directory has mode 0700, so the file alone is not enough):

  ```bash
  chown -R www-data:www-data var/cache
  ```

The same rule applies to every split between the deploy user and the runtime user: CI jobs, deploy scripts and `sudo`.
The user who warms up the cache must be the user who starts the server.
Or you change the owner in between.

In Docker the most common mistake is a warm-up as `root` in the build, and a server that runs as `www-data`.
The [Docker](#docker) section has an image that does it right.

If you really cannot do either, you can set `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1`.
This turns the refusal into a warning, and it lowers security: the file is PHP code that runs.
Do not use it when other users can write to the cache directory.
The threat model and the other checks (world-writable directory, group-writable directory) are in [Security](security.md#config-cache-file-protection).

## Docker

This Dockerfile was built and run for real with a Symfony 7 skeleton application and this bundle (version 0.29).
The application has a `/health` route that answers `ok`.

```dockerfile
FROM php:8.3-cli

# pcntl is required. sockets and event are optional.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libevent-dev libssl-dev unzip \
    && docker-php-ext-install pcntl sockets opcache \
    && pecl install event \
    && docker-php-ext-enable --ini-name z-event.ini event \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

RUN printf 'opcache.enable_cli=1\nopcache.memory_consumption=256\n' > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --chown=www-data:www-data . /app
WORKDIR /app
USER www-data

ENV APP_ENV=prod
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && bin/console cache:warmup

EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") === "ok" ? 0 : 1);'

CMD ["bin/console", "workerman:server", "start"]
```

Add a `.dockerignore` with `var/`, `vendor/` and `.git`, so the image does not carry your local files.
The application config of this image is:

```yaml
# config/packages/workerman.yaml
workerman:
  stop_timeout: 10
  servers:
    - name: web
      listen: http://0.0.0.0:8080
      processes: 2
```

Inside a container the server must listen on `0.0.0.0`, not on `127.0.0.1` (see [HTTP server](http-server.md)).

Why the file looks like this:

- `COPY --chown=www-data:www-data . /app` comes **before** `WORKDIR /app`.
  The `COPY` creates `/app` with the right owner.
  If `WORKDIR /app` comes first, Docker creates `/app` as `root`, and the build fails: `composer install` cannot create `/app/vendor`.
- `USER www-data` comes before `cache:warmup`.
  So `www-data` owns `var/cache/prod/workerman/config.cache.php`, and the server accepts it (see [the config cache](#config-cache-and-the-runtime-user)).
  The container ran as `uid=33(www-data)`, and the cache files had the owner `33`.
- If you must warm up as `root`, remove the `USER www-data` line before `RUN composer install`, add `&& chown -R www-data:www-data var` at the end of that `RUN`, and put `USER www-data` after it.
  Change the owner of the whole `var` directory: the server must also write `var/run` and `var/log`.
  This variant was built and run too: it was healthy.
- `pcntl` is the only extension that you must add: the image has `posix`, and `sockets`, `event` and `opcache` are optional.
  `ext-event` needs `libevent-dev` and `libssl-dev` to build.
  `ext-inotify` is only for `file_monitor` in `dev`, so leave it out of a production image.
- `opcache.enable_cli=1` is needed because the workers are CLI processes (see [OPcache](#opcache)).
  An image is never changed in place, so you can also set `opcache.validate_timestamps=0`.
- The server starts in the foreground, which is what a container needs.
  Do not use `-d`: the container would stop at once.
- There is no `STOPSIGNAL` line.
  Docker sends `SIGTERM`, and the master stops on `SIGTERM` like on `SIGINT` (see [stop time](#stop-time-and-graceful-stop)).
- The health check uses PHP, so you need no extra tool in the image.

### Docker Compose

```yaml
services:
  app:
    build: .
    ports:
      - "8080:8080"
    restart: unless-stopped
    stop_grace_period: 15s
    read_only: true
    tmpfs:
      - /tmp
      - /app/var/run:uid=33,gid=33
      - /app/var/log:uid=33,gid=33
```

- `stop_grace_period` must be larger than `stop_timeout`.
- With `read_only: true`, the server needs a writable `var/run` (PID file and status file) and `var/log` (log file).
  The `tmpfs` lines give them to uid 33, which is `www-data`.
  The warm config cache stays in the image.
  Your application may need more writable directories, for example for Symfony cache pools.
- The `HEALTHCHECK` of the image is used by Compose: the service showed `healthy` after the start.

## Logs in a container

In the foreground, the server writes each log line to stdout.
`docker logs` and `kubectl logs` show it, so you do not need a log file.
By default the server also writes the same lines to `var/log/workerman.log` inside the container.
The file does not grow without end: Workerman cuts it when it gets too big.

Do not set `log_file` to `/dev/stderr` or `php://stderr`.
Both give a PHP warning on every log line (see [issue 985](https://github.com/crazy-goat/workerman-bundle/issues/985)).
The `stdout_file` key is only used in daemon mode (see [Configuration](configuration.md#top-level-keys)).

The warnings at the start (for example the one about the `grpc` extension) are written to the log file only.
So do not point `log_file` to `/dev/null` if you want to see them.

## Stop time and graceful stop

Docker and Kubernetes stop a container with `SIGTERM`.
The master handles `SIGTERM` like `SIGINT`: a fast stop.
After `stop_timeout` seconds, it kills the workers that still work.
We ran a route that works for 5 seconds, and stopped the container with `docker stop`:

| `stop_timeout` | What happened |
|----------------|---------------|
| `10` | The request ended with status 200 after 5 seconds. The container stopped after 4 seconds. |
| `2` (the default) | The request was cut after 2 seconds. The client got an empty reply. |

So set `stop_timeout` to more than your longest request.
Then set the stop time of the platform to a bit more than `stop_timeout`:

- Docker: `docker stop -t` or `stop_grace_period` in Compose.
- Kubernetes: `terminationGracePeriodSeconds`.

If the platform stops earlier, it sends `SIGKILL` and the requests are cut.
A real graceful stop with `SIGTERM` is an open request (see [issue 910](https://github.com/crazy-goat/workerman-bundle/issues/910)).

## Kubernetes

This example was **not** run on a real cluster.
The rules in it come from the code and from the Docker tests above.

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: myapp
spec:
  replicas: 2
  selector:
    matchLabels:
      app: myapp
  template:
    metadata:
      labels:
        app: myapp
    spec:
      terminationGracePeriodSeconds: 20
      containers:
        - name: app
          image: registry.example.com/myapp:1.0.0
          ports:
            - containerPort: 8080
          readinessProbe:
            httpGet:
              path: /health
              port: 8080
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /health
              port: 8080
            initialDelaySeconds: 10
            periodSeconds: 10
          resources:
            requests:
              cpu: "1"
              memory: 256Mi
            limits:
              cpu: "2"
              memory: 512Mi
```

- Kubernetes sends `SIGTERM` and waits `terminationGracePeriodSeconds`.
  Keep it larger than `stop_timeout` (here 20 and 10).
- The `/health` route is yours: the bundle has no health route.
  Make it cheap, and do not call a database in the liveness probe.
- The readiness probe keeps a pod out of the service until the server answers.
- The number of workers: if you leave `processes` empty, the server uses the CPU **limit** of the container (cgroup v2 or v1), times 2, not the CPUs of the node (see [Configuration](configuration.md#servers)).
  Here the limit is 2 CPUs, so the server starts 4 workers.
  Set `processes` yourself if you want a fixed number.
- A memory limit kills a pod that grows too much.
  Use the `memory` and `max_requests` [reload strategies](reload-strategies.md) so that workers restart before that.
- Update a release with a new image tag.
  A rolling update replaces the pods, so you do not use `reload` or `restart` inside a pod.

## gRPC in a container

If the image has the `grpc` extension, set the variable in the image:

```dockerfile
ENV GRPC_ENABLE_FORK_SUPPORT=1
```

The server reads it from `$_ENV` or `getenv()`, and warns at the start when it is missing.
See [Troubleshooting](troubleshooting.md#grpc-extension-and-fork-safety) for the reason.
