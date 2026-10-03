# Deployment

This page shows how to run the bundle on a Linux server with systemd.
It covers the user, OPcache, the unit file and the deploy script.
For Docker, see the [Config cache](#config-cache-and-the-runtime-user) section for the user rules that also apply there.

## Checklist

- The host has the [requirements](#requirements-on-the-host).
- One Unix user runs the server and owns the cache (see [the user](#user-and-group)).
- OPcache is set on purpose (see [OPcache](#opcache)).
- A systemd unit starts the server in the foreground (see [the unit](#systemd-unit)).
- The deploy script warms up the cache as the runtime user, then restarts or reloads the server (see [deploy script](#deploy-script)).

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
This works:

```dockerfile
FROM php:8.3-cli
COPY --chown=www-data:www-data . /app
WORKDIR /app
USER www-data
RUN bin/console cache:warmup
CMD ["bin/console", "workerman:server", "start"]
```

`COPY --chown` makes `www-data` the owner of `/app`.
So the runtime user can write `var/cache` in the warm-up.
A plain `COPY . /app` makes files of `root`, and `www-data` cannot write to them.

This version changes the owner after the warm-up:

```dockerfile
FROM php:8.3-cli
COPY . /app
WORKDIR /app
RUN bin/console cache:warmup && chown -R www-data var/cache
USER www-data
CMD ["bin/console", "workerman:server", "start"]
```

If you really cannot do either, you can set `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE=1`.
This turns the refusal into a warning, and it lowers security: the file is PHP code that runs.
Do not use it when other users can write to the cache directory.
The threat model and the other checks (world-writable directory, group-writable directory) are in [Security](security.md#config-cache-file-protection).
