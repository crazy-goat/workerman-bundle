# Getting started

This page takes you from zero to a running server.
You need about 5 minutes.

## Requirements

You need all of these:

| What | Version |
|------|---------|
| PHP | 8.2 or newer |
| `ext-pcntl` | any |
| `ext-posix` | any |
| Symfony | 6.4, 7 or 8 |
| Workerman | 5 (Composer installs it for you) |
| Operating system | Linux or macOS |

Windows is not supported.

Some packages and extensions are optional:

| Optional | What it gives you |
|----------|-------------------|
| `ext-event` | A faster event loop. |
| `ext-inotify` | Fast file monitoring. Without it, the bundle checks files on a timer. |
| `ext-zip` | Needed by `workerman:build:bin`. |
| `dragonmantank/cron-expression` | Cron schedules for tasks. |

## Install

```bash
composer require crazy-goat/workerman-bundle
```

## Enable the bundle

Add the bundle to `config/bundles.php`:

```php
<?php
// config/bundles.php

return [
    // ...
    \CrazyGoat\WorkermanBundle\WorkermanBundle::class => ['all' => true],
];
```

## Write a minimal config

Create `config/packages/workerman.yaml`:

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080

when@dev:
  workerman:
    reload_strategy:
      file_monitor:
        active: true

when@prod:
  workerman:
    reload_strategy:
      exception:
        active: true
      max_requests:
        active: true
        requests: 1000
      memory:
        active: true
        limit: 134217728
```

What the config does:

- `servers` lists your servers. Each server has a `name` and a `listen` address.
- In `dev`, `file_monitor` reloads the workers when you change a file in `src/` or `config/`.
- In `prod`, a worker reloads after about 1000 requests.
  It also reloads when it uses more than 128 MB of memory (134217728 bytes).
- A worker is a child process that handles requests.
- Do not use `file_monitor` in `prod`. It costs CPU time.

> **Note:** `listen` is required. If you leave it out, the server does not start.
> You get an error: `Unsupported listen scheme`.

The `listen` value starts with a scheme: `http://`, `https://`, `ws://` or `wss://`.
Use `http://` to start.
An `https://` listener also needs `local_cert` and `local_pk`.
See [SSL certificate and key validation](security.md#ssl-certificate-and-key-validation).

To see every config option with its default, run:

```bash
bin/console config:dump-reference workerman
```

## Start the server

Run the server in the foreground:

```bash
bin/console workerman:server start
```

Stop it with `Ctrl+C`.

Run the server in the background (daemon mode) with `-d`:

```bash
bin/console workerman:server start -d
```

Stop the daemon with:

```bash
bin/console workerman:server stop
```

> **Note:** `bin/console` is the console of your application.
> It is not the `bin/` directory of this bundle.

## Open the browser

Open <http://127.0.0.1:8080>.
You see your Symfony application.

## Ports below 1024

The example uses port `8080`. Any user can open it.

To use a port below 1024, such as `80` or `443`, you need one of these:

- Run the server as root.
- On Linux, give the `CAP_NET_BIND_SERVICE` capability to the PHP binary.

In production, set `user` and `group` in the config.
The server then drops its rights after it opens the port.
You can also put a reverse proxy, such as nginx or Caddy, in front of the server.

## Next steps

- [Security](security.md): trusted hosts, TLS and static files.
- [Troubleshooting](troubleshooting.md): problems of long-running workers.
- [PHAR and binary packaging](build-packaging.md): ship your application as one file.
- [README](../README.md): the full config reference, commands, middlewares, scheduler and supervisor.
