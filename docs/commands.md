# Commands

This page lists every console command of the bundle, with all arguments and options.

In this page, `bin/console` is the console of **your application**.
It is not the `bin/` directory of this bundle.
For the scripts of the bundle itself, see [`bin/README.md`](../bin/README.md).

| Command | What it does |
|---------|--------------|
| `workerman:server` | Starts, stops and controls the server. |
| `workerman:build:phar` | Builds a PHAR archive of your application. |
| `workerman:build:bin` | Builds a standalone binary of your application. |

## `workerman:server`

```bash
bin/console workerman:server <action> [-d] [-g]
```

The `action` argument is required.
These are the allowed values:

| Action | What it does |
|--------|--------------|
| `start` | Starts the server. It fails if the server is already running. |
| `stop` | Stops the server. |
| `restart` | Stops the server if it runs, then starts it again. |
| `reload` | Reloads the workers. The master process keeps running. |
| `status` | Shows the status of the server. |
| `connections` | Shows all open connections. |

An unknown action prints an error and the command exits with a failure code.

These are the options:

| Option | Used by | What it does |
|--------|---------|--------------|
| `-d`, `--daemon` | `start`, `restart` | Runs the server in the background (daemon mode). |
| `-g`, `--grace` | `stop`, `restart`, `reload` | Makes the action graceful. Workers finish their current work first. |

The `-d` option has no effect on `stop` and `reload`.
On its own, the `-g` option has no effect on `start`.

Do not use `-d` and `-g` in the same command.
Workerman reads only the first one of them.
For example, `restart -g -d` does not run in daemon mode.

Examples:

```bash
bin/console workerman:server start         # start in the foreground
bin/console workerman:server start -d      # start in daemon mode
bin/console workerman:server stop          # stop
bin/console workerman:server stop -g       # graceful stop
bin/console workerman:server restart       # restart
bin/console workerman:server restart -d    # restart in daemon mode
bin/console workerman:server reload        # reload the workers (hot reload)
bin/console workerman:server reload -g     # graceful reload
bin/console workerman:server status        # show the status
bin/console workerman:server connections   # show the open connections
```

You can also start the server through the runtime, without the console:

```bash
APP_RUNTIME='CrazyGoat\WorkermanBundle\Runtime' php public/index.php start
```

### stop, restart and reload

`stop`, `restart` and `reload` send a signal to the master process.
The command looks for the master process in the `pid_file`.
If no server runs, `stop`, `reload`, `status` and `connections` print an error and exit with a failure code.
`restart` does not fail in this case: it only starts the server.

| Action | Plain | With `-g` |
|--------|-------|-----------|
| `stop`, `restart` | `SIGINT` | `SIGQUIT` |
| `reload` | `SIGUSR1` | `SIGUSR2` |

`stop` and `restart` wait for the master process to end.
The wait time is the `stop_timeout` setting plus 3 seconds.
With `-g`, it is three times `stop_timeout` plus 3 seconds.
With the default `stop_timeout` of 2, this is 5 seconds, or 9 seconds with `-g`.
If the master process does not end in time, `stop` and `restart` print an error and exit with a failure code.
See [Configuration](configuration.md#top-level-keys).

### status

The `status` action asks the master process to write a status file.
Then it prints the content.
It waits as long as the `status_timeout` setting (5 seconds by default).
If no data arrives in time, it prints the warning `No status data available.`

### connections

The `connections` action lists every open TCP connection of all worker processes.
It works like `status`: it waits as long as `status_timeout`.
If no data arrives in time, it prints the warning `No connection data available.`

Example output:

```text
--------------------------------------------------------------------- WORKERMAN CONNECTION STATUS --------------------------------------------------------------------------------
PID      Worker          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address
12345    webserver         1        tcp     Http              1      0       0B           0B          12.3KB       4.1KB        ESTABLISHED    127.0.0.1:8080         127.0.0.1:54321
```

| Column | Description |
|--------|-------------|
| `PID` | The process ID of the worker that handles the connection. |
| `Worker` | The name of the worker process. It is cut to 14 characters. |
| `CID` | The connection ID that Workerman gives to the connection. |
| `Trans` | The transport protocol: `tcp` or `ssl`. |
| `Protocol` | The application protocol, for example `Http`. If there is none, you see the transport name. A name longer than 15 characters is cut to 13 characters plus `..`. |
| `ipv4` | `1` if the connection uses IPv4, otherwise `0`. |
| `ipv6` | `1` if the connection uses IPv6, otherwise `0`. |
| `Recv-Q` | The bytes that wait to be read. It has the suffix B, KB, MB, GB or TB. |
| `Send-Q` | The bytes that wait to be sent. It has the suffix B, KB, MB, GB or TB. |
| `Bytes-R` | The bytes received during the life of the connection. |
| `Bytes-W` | The bytes written during the life of the connection. |
| `Status` | The state of the connection: `INITIAL`, `CONNECTING`, `ESTABLISHED`, `CLOSING`, `ENDING` or `CLOSED`. |
| `Local Address` | The local address, as `ip:port`. |
| `Foreign Address` | The address of the other side, as `ip:port`. |

> **Platform note:** `status` and `connections` send signals to the master process, so they work on Linux and macOS.
> They do not work on Windows, because the commands use `posix_kill()`.

> **Note:** For better performance, Workerman recommends the `php-event` extension.

> **Note:** If the `grpc` extension is loaded, set `GRPC_ENABLE_FORK_SUPPORT=1` before you start the server.
> See [grpc/grpc#31241](https://github.com/grpc/grpc/issues/31241), [Configuration](configuration.md#environment-variables) and [Troubleshooting](troubleshooting.md#grpc-extension-and-fork-safety).

## Reload from your code

You can reload the workers from your own code with `Utils::reload()`:

```php
<?php

use CrazyGoat\WorkermanBundle\Utils;

// Reload only the current worker process
Utils::reload();

// Reload all worker processes
Utils::reload(reloadAllWorkers: true);
```

`Utils::reload()` sends `SIGUSR1`.
With the default, it signals the current process.
With `reloadAllWorkers: true`, it signals the parent process.
In a worker, for example in a controller or a service, the parent is the master process.
Then it does the same as `bin/console workerman:server reload`.

In a scheduled task, the parent is the scheduler worker, not the master process.
Do not call it from a separate command, for example a deploy script.
The parent would be your shell or CI runner, and `SIGUSR1` would stop it.
In a deploy script, run `bin/console workerman:server reload` instead.

It needs the `pcntl` and `posix` PHP extensions.
The Workerman runtime always has them.

## `workerman:build:phar`

Builds a PHAR archive of your application.
Run it with `phar.readonly=0`, or the build fails:

```bash
php -d phar.readonly=0 bin/console workerman:build:phar [options]
```

| Option | Default | What it does |
|--------|---------|--------------|
| `-o`, `--output-dir=DIR` | `build.build_dir` | The output directory. |
| `--filename=NAME` | `build.phar_filename` | The name of the PHAR file. |
| `--kernel-class=CLASS` | `build.kernel_class` | The kernel class for the PHAR stub. |
| `--include-tests` | off | Puts the `tests/` directory into the PHAR. Use it only for tests. |

The defaults are config keys.
See [Configuration](configuration.md#build).

## `workerman:build:bin`

Builds a standalone binary. It builds a PHAR first, then adds it to a `phpmicro.sfx` file.
Run it with `phar.readonly=0`, or the build fails:

```bash
php -d phar.readonly=0 bin/console workerman:build:bin [options]
```

| Option | Default | What it does |
|--------|---------|--------------|
| `-o`, `--output-dir=DIR` | `build.build_dir` | The output directory. |
| `--filename=NAME` | `build.bin_filename` | The name of the binary. |
| `--phar-filename=NAME` | `build.phar_filename` | The name of the PHAR file that the build makes on the way. |
| `--kernel-class=CLASS` | `build.kernel_class` | The kernel class for the PHAR stub. |
| `--sfx-file=PATH` | `build.sfx.file` | The path of a local `phpmicro.sfx` file. |
| `--sfx-url=URL` | `build.sfx.url`, then `https://download.workerman.net/php/php<version>.micro.sfx` | The URL to download `phpmicro.sfx` from. |
| `--sfx-checksum=HASH` | `build.sfx.sha256` | The expected SHA-256 of the SFX file, in hex. |
| `--php-version=VER` | `build.bin_php_version`, then the current PHP version | The PHP version of the static binary, for example `8.3`. It chooses the default download URL. |
| `--insecure` | `build.sfx.allow_insecure` (`false`) | Turns off TLS peer verification for the download. Not recommended. |
| `--unsafe-no-checksum` | off | Skips the SHA-256 check. Not recommended. |

If the build downloads the SFX file and has no checksum, it fails, unless you pass `--unsafe-no-checksum`.
A local SFX file needs no checksum.
For the checksum, the SFX source order and the security notes, see [Build and packaging](build-packaging.md).
