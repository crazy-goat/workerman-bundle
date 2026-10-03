# Logging and monitoring

This page tells where the server writes its logs, how to rotate them, and how to see if the server is healthy.
It also shows what to watch in production.

## Where the logs go

There are four places:

| What | Where | You set it with |
|------|-------|-----------------|
| Lines of the Workerman server: start, reload, stop, a worker that ended with an error | The Workerman log file, and also the console when the server runs in the foreground | `log_file` |
| Output of your code: `echo`, `var_dump`, PHP warnings | The console in the foreground. The stdout file in daemon mode | `stdout_file` |
| Logs of your app and Symfony: `$logger->info()`, exceptions of the kernel | Where Monolog sends them | `config/packages/monolog.yaml` |
| Errors of tasks and processes | The Monolog channels `task` and `process` | `config/packages/monolog.yaml` |

The paths `log_file` and `stdout_file` are in the [configuration reference](configuration.md#top-level-keys).
By default they are in `var/log/` of your project.
The directory has mode 0700 and belongs to the runtime user.

For Docker and Kubernetes, read [Logs in a container](deployment.md#logs-in-a-container).

## The Workerman log

Each line has the time, the process ID and the message:

```text
2026-10-03 18:12:00 pid:11 Workerman[console] reloading
2026-10-03 18:12:00 pid:93 [Server] web "web" started
2026-10-03 18:12:15 pid:11 worker[[Server] web:114] exit with status 9
```

- `[Server] web "web" started` is written by each worker when it starts.
  You see it again after each reload and after a worker restart.
- `worker[<name>:<pid>] exit with status <n>` means that a worker ended with a status other than 0.
  We killed a worker with `kill -9` and got status 9.
  A [process](supervisor.md) whose method returned ended with status 256.
  A normal reload does not write this line.
- The scheduler and the supervisor also write lines to this file (see [Scheduler](scheduler.md) and [Supervisor](supervisor.md)).

In the foreground, the server writes the same lines to the console.
In daemon mode (`start -d`), it writes only to the file.

Workerman cuts the file by itself when it gets bigger than 10 MB.
It keeps the second half.
You cannot change this size in the bundle config.
So the file does not grow without end.

A process that fails again and again writes many lines.
In our test, a process that threw an error at once wrote 2 MB in 30 seconds.
See [Supervisor](supervisor.md).

## The stdout file

In daemon mode, Workerman closes the console.
It sends all output of your code to `stdout_file`.
There you find `echo` output and PHP warnings:

```text
echo line

Warning: user warning line in /app/src/Controller/LogController.php on line 24
```

In the foreground, `stdout_file` is not used.
The output goes to the console.

## Logs of your app (Monolog)

The bundle does not change how Symfony logs.
Use `symfony/monolog-bundle` and set the handlers as you like.
The lines of your app do not go to the Workerman log.

In a container, send the logs to stderr:

```yaml
# config/packages/monolog.yaml
monolog:
  handlers:
    main:
      type: stream
      path: php://stderr
      level: info
```

We ran this in Docker, and `docker logs` showed the lines of `$logger->info()` and the exception of a controller.

Use a file when you run the server as a daemon:

```yaml
# config/packages/monolog.yaml
monolog:
  handlers:
    main:
      type: stream
      path: '%kernel.logs_dir%/app.log'
      level: info
```

Do not use `php://stderr` in daemon mode.
Workerman closes stderr there, and each request after the first one fails with an empty reply (see [issue 990](https://github.com/crazy-goat/workerman-bundle/issues/990)).

### Tasks and processes

The error of a task goes to the channel `task`.
The error of a process goes to the channel `process`.
Both are `critical` lines (see [Events](events.md)).
You can send them to their own file:

```yaml
# config/packages/monolog.yaml
monolog:
  handlers:
    background:
      type: stream
      path: '%kernel.logs_dir%/background.log'
      level: info
      channels: ['task', 'process']
    main:
      type: stream
      path: php://stderr
      level: info
      channels: ['!task', '!process']
```

We ran a task and a process that throw an error.
The lines went to `background.log`, with `task.CRITICAL` and `process.CRITICAL` in front.
(The second handler writes to stderr, so use a file there if you run a daemon.)

### PHP errors and `error_log()`

Some errors of the bundle are written with the PHP function `error_log()`.
It writes to stderr, unless you set the PHP setting `error_log`.
In daemon mode, these lines are lost (see [issue 917](https://github.com/crazy-goat/workerman-bundle/issues/917)).
So set `error_log` in daemon mode:

```bash
php -d error_log=/var/www/myapp/var/log/php-error.log bin/console workerman:server start -d
```

We ran this, and the lines went to the file.
You can also set `error_log` in `php.ini`.

## Log rotation

Workerman opens the log file for each line and adds to the end.
So you can rotate the file while the server runs.
We tested this by hand:

- After `mv workerman.log workerman.log.1`, the next line made a new `workerman.log`.
- After we emptied the file (`: > workerman.log`), the next line was added to the empty file.

This is a `logrotate` config that uses the second way.
Put it in `/etc/logrotate.d/myapp`:

```text
/var/www/myapp/shared/var/log/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

- `copytruncate` copies the file and then empties it.
  A line that is written between the two steps can be lost.
- The log directory has mode 0700, but `logrotate` runs as `root` and can read it.
- Workerman already cuts `workerman.log` at 10 MB, so you only need rotation to keep old lines.
- For the Monolog files, you can also use the Monolog handler type `rotating_file`.

We did not run `logrotate` itself.

## Monitoring

### The status command

`workerman:server status` shows the master and each worker:

```text
---------------------------------------------------GLOBAL STATUS---------------------------------------------------------
Workerman/5.2.2         PHP/8.3.35 (JIT off)          Linux/6.8.0-117-generic
start time:2026-10-03 18:11:22   run 0 days 0 hours   load average: 0.82, 0.77, 0.46
1 workers    2 processes
name             event-loop     exit_status     exit_count
[Server] web     event          0               4
[Server] web     event          9               1
---------------------------------------------------PROCESS STATUS--------------------------------------------------------
pid	memory  listening           name         connections send_fail timers  total_request qps    status
123	2.01M   http://0.0.0.0:8080 [Server] web 0           0         1       3
115	1.69M   http://0.0.0.0:8080 [Server] web 0           0         1       4
```

- `exit_status` and `exit_count` count how workers ended.
  Status `0` is a normal end, for example after a reload.
  Any other number is a crash or a kill (here, 1 worker was killed with status 9).
- `memory` is the memory of one worker. If it grows all the time, use the `memory` or `max_requests` [reload strategies](reload-strategies.md).
- `connections` is the number of open connections of the worker.
- `total_request` is the number of requests of the worker since it started.

If no server runs, `status` prints an error and exits with code 1.
The command waits for the answer as long as `status_timeout` (5 seconds by default).
Read more in [Commands](commands.md#status).

`workerman:server connections` lists every open connection.
It is good for a look at a problem, for example many connections that stay open.
See [Commands](commands.md#connections).

The bundle has no metrics endpoint.
The `status` output is text for people.
Do not parse it in a script that runs every second: each call sends a signal to the master.

### A health check

A load balancer, Docker or Kubernetes needs a cheap check.
Make a route in your app:

```php
#[Route('/health')]
public function health(): Response
{
    return new Response('ok');
}
```

The bundle has no health route.
Do not call a database in this route if the check only tells that the process is alive.

Call it from the same machine:

```bash
php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") === "ok" ? 0 : 1);'
```

If you set `trusted_hosts`, a request with another `Host` gets status 400.
Then send a `Host` from your list:

```bash
php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health", false, stream_context_create(["http" => ["header" => "Host: example.com"]])) === "ok" ? 0 : 1);'
```

We saw the container turn `unhealthy` with `trusted_hosts` and the first command.
With the `Host` header it was healthy.
See [Trusted hosts](reverse-proxy.md#trusted-hosts).

A worker answers one request at a time.
If all workers are busy, the check waits or fails.
That is a good sign that you need more `processes`.

### What to watch

- The lines `exit with status` in the Workerman log (a worker crashed or was killed).
- `critical` lines of the channels `task` and `process`.
- Status 502, 503 and 504 at the [reverse proxy](reverse-proxy.md).
- The memory of the workers in `status`.
- A restart of the unit in `systemctl status myapp` (see [Deployment](deployment.md#systemd-unit)).
