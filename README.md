# Workerman runtime for symfony applications
![PHP ^8.2](https://img.shields.io/badge/PHP-^8.2-777bb3.svg?style=flat)
![Symfony ^6.4|^7.0|^8.0](https://img.shields.io/badge/Symfony-^6.4|^7.0|^8.0-374151.svg?style=flat)
[![Tests Status](https://img.shields.io/github/actions/workflow/status/crazy-goat/workerman-bundle/tests.yaml?branch=master)](../../actions/workflows/tests.yaml)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

[Workerman](https://github.com/walkor/workerman) is a high-performance, asynchronous event-driven PHP framework written in pure PHP.  
This bundle provides a Workerman integration in Symfony, allowing you to easily create a http server, scheduler and supervisor all in one place.
This bundle allows you to replace a traditional web application stack like php-fpm + nginx + cron + supervisord, all written in pure PHP (no Go, no external binaries).
The request handler works in an event loop which means the Symfony kernel and the dependency injection container are preserved between requests,
making your application faster with less (or no) code changes.

## Contributing

Please see [CONTRIBUTING.md](https://github.com/crazy-goat/workerman-bundle/blob/master/CONTRIBUTING.md) for information about branch protection rules and development workflow.

## What new in this fork
* `servers.reuse_port` - on linux machines u can use kernel load balancer if `SO_REUSEPORT` is enabled  
*  By default `luzrain/workerman-bundle` parse data to `psr7` request and then to symfony `Request`.
This `workerman-bundle` will create symfony request without psr7 it increase performance, but it is still experimental. 
## Getting started
### Install composer packages
```bash
composer require crazy-goat/workerman-bundle
```

### Enable the bundle

```php
<?php
// config/bundles.php

return [
    // ...
    \CrazyGoat\WorkermanBundle\WorkermanBundle::class => ['all' => true],
];
```

### Configure the bundle
A minimal configuration might look like this.  
For all available options with documentation, see the command output.
```bash
$ bin/console config:dump-reference workerman
```

```yaml
# config/packages/workerman.yaml

workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 4

  reload_strategy:
    exception:
      active: true

    file_monitor:
      active: true
```

> **Note:** The example above binds an unprivileged port (`8080`) so it works without `sudo`.
>
> To bind a port below 1024 (e.g. `80` or `443`) you must run the process as **root** or grant the `CAP_NET_BIND_SERVICE` capability on Linux.
>
> In production, consider using the `user` and `group` config keys to drop privileges after binding, or front with a reverse proxy (e.g. nginx, Caddy).

> **`listen` is effectively required.** Omitting it creates a worker that does not accept connections — no traffic reaches your application.
> Supported URI schemes: `http://`, `https://`, `ws://` (WebSocket), `wss://` (WebSocket over SSL), `tcp://` (raw TCP).

## Configuration reference

All top-level `workerman` configuration options:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `runtime_dir` | `string` | `%kernel.project_dir%` | Writable runtime directory for cache, logs, and PID files. In PHAR mode defaults to the directory containing the PHAR. Can be overridden with `WORKERMAN_RUNTIME_DIR` env var. See [build-packaging.md](docs/build-packaging.md#writable-paths). |
| `user` | `string\|null` | `null` (current user) | Unix user of processes. |
| `group` | `string\|null` | `null` (current group) | Unix group of processes. |
| `stop_timeout` | `int` | `2` | Max seconds of child process work before force kill. |
| `cache_warmup_timeout` | `int` | `30` | Max seconds to wait for cache warmup in forked process. Can be overridden with `WORKERMAN_CACHE_WARMUP_TIMEOUT` env var. |
| `status_timeout` | `int` | `5` | Max seconds to wait for status file generation after sending SIGIOT. |
| `pid_file` | `string` | `%kernel.project_dir%/var/run/workerman.pid` | File to store master process PID. |
| `log_file` | `string` | `%kernel.project_dir%/var/log/workerman.log` | Log file. |
| `stdout_file` | `string` | `%kernel.project_dir%/var/log/workerman.stdout.log` | File to write all output (echo, var_dump, etc.) to when running as daemon. |
| `max_package_size` | `int` | `10485760` (10 MB) | Maximum accepted package size in bytes. |
| `connection_timeout` | `int` | `120` | Max seconds to wait for a complete request before closing the connection (slowloris protection). See [security.md](docs/security.md). |
| `keepalive_timeout` | `int` | `30` | Max idle seconds for keep-alive connections before closing. See [security.md](docs/security.md). |
| `response_chunk_size` | `int` | `2048` | Response chunk size in bytes. |
| `trusted_hosts` | `string[]` | `[]` | List of regex patterns for trusted hostnames. Requests with a non-matching `Host` header are rejected with `SuspiciousOperationException`. See [security.md](docs/security.md). |

### Start application

Using the Symfony console command (the `bin/console` below refers to **your application's** Symfony console, **not** the `bin/` directory shipped by this bundle):
```bash
$ bin/console workerman:server start
$ bin/console workerman:server start -d   # daemon mode
```

> **Note:** All `bin/console workerman:*` commands throughout this document refer to your application's Symfony console, not the scripts in this bundle's `bin/` directory. See [`bin/README.md`](bin/README.md) for the bundle's own development scripts.

Or using the runtime directly:
```bash
$ APP_RUNTIME=CrazyGoat\\WorkermanBundle\\Runtime php public/index.php start
```

### Manage the server

```bash
$ bin/console workerman:server stop        # stop the server
$ bin/console workerman:server stop -g     # graceful stop
$ bin/console workerman:server restart     # restart
$ bin/console workerman:server restart -d  # restart in daemon mode
$ bin/console workerman:server reload      # reload workers (hot reload)
$ bin/console workerman:server reload -g   # graceful reload
$ bin/console workerman:server status      # show server status
$ bin/console workerman:server connections # show active connections
```

#### `workerman:server connections` output

The command lists every active TCP connection across all worker processes. Example output:

```
--------------------------------------------------------------------- WORKERMAN CONNECTION STATUS --------------------------------------------------------------------------------
PID      Worker          CID       Trans   Protocol        ipv4   ipv6   Recv-Q       Send-Q       Bytes-R      Bytes-W       Status         Local Address          Foreign Address
12345    webserver         1        tcp     Http              1      0       0B           0B          12.3KB       4.1KB        ESTABLISHED    127.0.0.1:8080         127.0.0.1:54321
```

| Column | Description |
|--------|-------------|
| `PID` | Process ID of the worker handling the connection |
| `Worker` | Name of the worker process (truncated to 14 characters) |
| `CID` | Unique connection identifier assigned by Workerman |
| `Trans` | Transport layer protocol (`tcp`, `udp`, `ssl`) |
| `Protocol` | Application protocol (`Http`, `Websocket`, `Text`, or the transport name when no protocol is set). Names longer than 15 characters are truncated to 13 characters + `..` |
| `ipv4` | `1` if the connection uses IPv4, `0` otherwise |
| `ipv6` | `1` if the connection uses IPv6, `0` otherwise |
| `Recv-Q` | Bytes waiting to be read from the receive buffer (formatted with B/KB/MB/GB/TB suffix) |
| `Send-Q` | Bytes waiting to be sent in the send buffer (formatted with B/KB/MB/GB/TB suffix) |
| `Bytes-R` | Total bytes received over the lifetime of the connection |
| `Bytes-W` | Total bytes written over the lifetime of the connection |
| `Status` | Current connection state: `INITIAL`, `CONNECTING`, `ESTABLISHED`, `CLOSING`, `ENDING`, or `CLOSED` |
| `Local Address` | Local socket address in `ip:port` format |
| `Foreign Address` | Remote peer socket address in `ip:port` format |

> **Platform note:** Connection introspection relies on Workerman's internal tracking and is available on POSIX-compatible platforms (Linux, macOS). The output is generated by sending `SIGIO` to the master process, which collects data from each worker. Windows is not supported because the command uses `posix_kill()`.

> **Note:** For better performance, Workerman recommends installing the _php-event_ extension.

> **Note:** If you have the `grpc` PHP extension installed, you must set the environment variable `GRPC_ENABLE_FORK_SUPPORT=1` before starting the server. The grpc extension spawns background threads that deadlock in forked child processes (e.g. scheduler tasks) unless fork support is explicitly enabled. See [grpc/grpc#31241](https://github.com/grpc/grpc/issues/31241) for details.

### Programmatic reload

You can trigger a worker reload from your application code using `Utils::reload()`:

```php
<?php

use CrazyGoat\WorkermanBundle\Utils;

// Reload only the current worker process
Utils::reload();

// Reload all worker processes
Utils::reload(reloadAllWorkers: true);
```

This sends a `SIGUSR1` signal to the worker (or parent) process. It is equivalent to running `bin/console workerman:server reload` but can be called from any context — controllers, services, scheduled tasks, or deploy hooks.

> **Note:** `Utils::reload()` requires the `pcntl` and `posix` PHP extensions. Both are always available in the Workerman runtime process.

## Reload strategies
Because of the asynchronous nature of the server, the workers reuse loaded resources on each request. This means that in some cases we need to restart workers.  
For example, after an exception is thrown, to prevent services from being in an unrecoverable state. Or every time you change the code in the IDE.  
There are a few restart strategies that are implemented and can be enabled or disabled depending on the environment.

 - **exception**  
   Reload worker each time that an exception is thrown during the request handling.
 - **max_requests**  
   Reload worker on every N request to prevent memory leaks.
 - **file_monitor**  
   Reload all workers each time you change the files**.
 - **always**  
   Reload worker after each request.
 - **memory**  
   Reload worker when memory usage reaches a certain threshold. Three options are available:
   `active` (default: `false`) toggles the strategy, `limit` (default: `134217728` — 128 MB) is the RSS threshold in bytes that triggers a worker reload, and `gc_limit` (default: `100663296` — 96 MB) runs `gc_collect_cycles()` preemptively before the reload check.
   ```yaml
   workerman:
     reload_strategy:
       memory:
         active: true
         limit: 268435456       # 256 MB
         gc_limit: 201326592    # 192 MB
   ```
 
** It is highly recommended to install the _php-inotify_ extension for file monitoring. Without it, monitoring will work in polling mode, which can be very cpu and disk intensive for large projects.

See all available options for each strategy in the command output.
```bash
$ bin/console config:dump-reference workerman reload_strategy
```

### Implement your own reload strategies
You can create reload strategy with your own logic by implementing the RebootStrategyInterface and adding the `workerman.reboot_strategy` tag to the service.
```php
<?php

use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workerman.reboot_strategy')]
final class TestRebootStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool
    {
        return true;
    }
}
```

## Middlewares

Middlewares allow you to intercept and process requests before they reach the Symfony controller, or modify responses before they are sent to the client.

A middleware is any service implementing `CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface`:

```php
<?php

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

final readonly class MyMiddleware implements MiddlewareInterface
{
    public function __invoke(Request $request, callable $next): Response
    {
        // Pre-processing: inspect or modify the request
        if ($request->header('X-Custom') === null) {
            return new Response(400);
        }

        $response = $next($request);

        // Post-processing: inspect or modify the response
        $response->header('X-Processed-By', 'MyMiddleware');
        return $response;
    }
}
```

### Registering middlewares

Register your middleware as a service in the Symfony container, then reference its service ID under `workerman.servers[].middlewares`:

```yaml
# config/services.yaml
services:
  App\Middleware\MyMiddleware: ~
```

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 4
      middlewares:
        - App\Middleware\MyMiddleware
```

### Static files middleware

The deprecated `serve_files` and `root_dir` server options are replaced by the `StaticFilesMiddleware`. To serve static files from a public directory, register the middleware with the root directory path:

```yaml
# config/services.yaml
services:
  workerman.middleware.static_files:
    class: CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware
    arguments:
      $rootDirectory: '%kernel.project_dir%/public'
```

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 4
      middlewares:
        - workerman.middleware.static_files
```

The `StaticFilesMiddleware` resolves requests against the configured root directory, serves matching files directly, and passes through to the next handler for non-file requests. Directory traversal attacks are prevented by ensuring the resolved path stays within the root directory.

### Execution order

Middlewares are executed in reverse registration order (last registered, first executed). This means the first middleware in the `middlewares` list wraps the innermost layer. Using onion model terminology:

```
Request → Middleware 1 → Middleware 2 → ... → Symfony controller → ... → Middleware 2 → Middleware 1 → Response
```

This allows outer middlewares to handle cross-cutting concerns (authentication, logging, rate limiting) before inner middlewares or the Symfony controller process the request.

## Scheduler
Periodic tasks can be configured with attributes or with tags in configuration files.  
Schedule string can be formatted in several ways:  
 - An integer to define the frequency as a number of seconds. Example: _60_
 - An ISO8601 datetime format. Example: _2023-08-01T01:00:00+08:00_
 - An ISO8601 duration format. Example: _PT1M_
 - A relative date format as supported by DateInterval. Example: _1 minutes_
 - A cron expression**. Example: _*/1 * * * *_

** Note that you need to install the [dragonmantank/cron-expression](https://github.com/dragonmantank/cron-expression) package if you want to use cron expressions as schedule strings

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsTask;

/**
 * Attribute parameters
 * name: Task name
 * schedule: Task schedule in any format
 * method: method to call, __invoke by default
 * jitter: Maximum jitter in seconds that adds a random time offset to the schedule. Use to prevent multiple tasks from running at the same time
 */
#[AsTask(name: 'My scheduled task', schedule: '1 minutes')]
final class TaskService
{
    public function __invoke()
    {
        // ...
    }
}
```

## Supervisor
Supervisor can be configured with attributes or with tags in configuration files.  
Processes are kept alive and wake up if one of them dies.

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;

/**
 * Attribute parameters
 * name: Process name
 * processes: number of processes
 * method: method to call, __invoke by default
 */
#[AsProcess(name: 'My worker', processes: 1)]
final class ProcessService
{
    public function __invoke()
    {
        // ...
    }
}
```

## Packaging (experimental)

> **⚠️ Experimental:** PHAR and standalone binary packaging are new features. The API may change in future releases.

The bundle provides commands to package your Symfony application as a standalone PHAR archive or a native binary:

```bash
# Build a PHAR archive
$ php -d phar.readonly=0 bin/console workerman:build:phar

# Build a standalone binary (requires phpmicro.sfx)
$ php -d phar.readonly=0 bin/console workerman:build:bin

# Options
$ php -d phar.readonly=0 bin/console workerman:build:phar --help
$ php -d phar.readonly=0 bin/console workerman:build:bin --help
```

See [docs/build-packaging.md](docs/build-packaging.md) for full documentation, build configuration options, and known limitations.

For an overview of all documentation files, see [docs/](docs/).

For security-related documentation including Host-header protection and trusted hosts configuration, see [docs/security.md](docs/security.md).

For long-running worker gotchas, state pollution, stale DB connections, blocking IO, and other common issues, see [docs/troubleshooting.md](docs/troubleshooting.md).

## License

This bundle is open-sourced software licensed under the [MIT license](LICENSE).
