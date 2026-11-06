# Supervisor

The supervisor runs your own long-running code next to the HTTP server.
Examples are a queue consumer or a loop that reads a stream.
The bundle keeps this code alive.
When it ends, the bundle starts it again.

Each supervised process is a Workerman worker named `[Process]`.
The processes start with `workerman:server start`.
The server needs at least one service with the `workerman.process` tag, or the supervisor does not start.

## Make a process

A process is a service of your application.
You mark it with the `#[AsProcess]` attribute.

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;

#[AsProcess(name: 'Queue consumer', processes: 2)]
final class QueueConsumer
{
    public function __invoke(): void
    {
        // read the queue in a loop
    }
}
```

The attribute has these parameters. All of them are optional.

| Parameter | What it does |
|---|---|
| `name` | The name of the process. It is used in logs. If you do not set it, the service ID is the name. |
| `processes` | How many copies run at the same time. The default is `1`. A value of `0` or less means the service is skipped. |
| `method` | The method to call. The default is `__invoke`. |
| `restartDelay` | The first wait before a restart, in seconds. It overrides `workerman.restart.delay` for this process. |
| `maxRestartDelay` | The longest wait before a restart, in seconds. It overrides `workerman.restart.max_delay` for this process. |

The method gets no arguments.
Ask for what you need in the constructor of the service.

## The tag in YAML

`#[AsProcess]` adds the tag `workerman.process` for you.
You can add the same tag in a service file instead.
The tag has the attributes `name`, `processes`, `method`, `restart_delay` and `max_restart_delay`.

```yaml
services:
  App\Process\QueueConsumer:
    tags:
      - { name: workerman.process, processes: 2, method: run }
```

To set the process name in YAML, use the second form of a tag.
The tag name is the key, and the attributes are the value:

```yaml
services:
  App\Process\QueueConsumer:
    tags:
      - workerman.process: { name: 'Queue consumer', processes: 2 }
```

In the first form, `name` is the name of the tag, so it cannot be the process name.
Then the process name is the service ID.

Write `processes` as a number, not as a string.

## What happens when the method ends

Every process runs your method one time, in the worker.
The method is expected to run forever.
If it returns, the bundle writes the log line `Process "<name>" (service: <id>::<method>) finished unexpectedly`.
Then the process exits with code 1, and Workerman starts a new process.
With the default configuration there is no pause between restarts.
Turn on the [restart delay](#restart-delay) to add one.

If your method throws an exception, it is the same.
The exception is caught and reported with `ProcessErrorEvent`.
Then the method counts as ended, and the process is started again.

So a method that returns or fails at once is started again and again, many times a second.
Turn on the restart delay, or add a `sleep()` or wait for new work inside your loop.
A `sleep()` is fine here, because the process has no HTTP requests to serve.

The bundle also writes the log line `[Process] "<name>" started` each time a process starts.

## Restart delay

The restart delay stops a broken process from starting again many times a second.
It works for supervised processes and for HTTP workers that exit fast,
for example when the kernel boot fails.

```yaml
# config/packages/workerman.yaml
workerman:
    restart:
        delay: 1              # first wait in seconds; 0 turns the backoff off (default)
        max_delay: 60         # the wait doubles up to this many seconds
        max_failures: 10      # write one "unhealthy" log line after this many fast failures
        healthy_after: 30     # a run this long resets the counter
```

The bundle keeps one small state file per process slot in the `restart/`
subdirectory of the PID file directory (`var/run/restart/` by default).
The file holds the failure count, the last start time and whether the unhealthy line was written.
The master creates `restart/` before it forks the workers and gives it to the
configured `user`/`group`, so the workers can write it after dropping privileges.

- The first start does not wait.
- After one fast failure the next start waits `delay` seconds.
- The wait doubles after each fast failure, up to `max_delay`.
- A run at least `healthy_after` seconds long resets the counter.
- After `max_failures` fast failures the bundle writes one line per worker slot, for example
  `Process "Queue consumer" is unhealthy: 10 fast restarts`.
  The line is written once, not on every restart.
- Set `delay: 0` to keep the old behaviour. `max_failures: 0` turns the line off.
- `healthy_after: 0` treats every start as healthy, so the backoff never waits.
  A `max_delay` smaller than `delay` is raised to `delay`.
- An intentional reload (manual, file monitor or a reload strategy) forgets the
  slot's failure count, so a healthy worker that recycles is not reported as unhealthy.

A process can set its own first and maximum wait with `restartDelay` and
`maxRestartDelay`, or with the tag attributes `restart_delay` and
`max_restart_delay`. The global `max_failures` and `healthy_after` still apply.

The wait is made of short sleeps and calls `pcntl_signal_dispatch()` between them,
so a stop or reload ends the waiting child at once instead of after the full delay.

## Errors and events

The supervisor dispatches two Symfony events:

- `CrazyGoat\WorkermanBundle\Event\ProcessStartEvent` before the method runs.
- `CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent` when the method throws.

Both events have `getProcessName()` and `getServiceClass()`.
`ProcessErrorEvent` also has `getError()`.

The bundle has a listener for `ProcessErrorEvent`.
It writes the error to the Monolog channel `process`.
See [Events](events.md) for details.

## Stop and reload

Your method runs inside the start of the worker and does not return.
So the worker cannot react to the stop and reload signals.
After `workerman:server stop` or `workerman:server reload`, the master kills the process with `SIGKILL` when `stop_timeout` seconds are over.
The default is 2 seconds.
Destructors and shutdown functions do not run.
After a reload, the process starts again.

Do not use the graceful option `-g` with `stop` or `reload` when you have supervised processes.
A graceful stop or reload does not kill the process, so the process never ends.
See [#911](https://github.com/crazy-goat/workerman-bundle/issues/911).

Save the state of your work often, because the process can be killed at any time.
The supervisor does not use the [reload strategies](reload-strategies.md).

## gRPC and SIGKILL

If the `grpc` extension is loaded, a process that ends is stopped with `SIGKILL` and does not run destructors.
See [Troubleshooting](troubleshooting.md#grpc-extension-and-fork-safety).

## Examples

A queue consumer with two copies:

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;

#[AsProcess(name: 'Queue consumer', processes: 2, method: 'consume')]
final class QueueConsumer
{
    public function consume(): void
    {
        while (true) {
            // take one message and handle it
        }
    }
}
```

A long loop with a sleep, in YAML:

```yaml
services:
  App\Process\PriceWatcher:
    tags:
      - workerman.process: { name: 'Price watcher', method: watch }
```

```php
<?php

final class PriceWatcher
{
    public function watch(): void
    {
        while (true) {
            // read the prices and save them
            sleep(5);
        }
    }
}
```
