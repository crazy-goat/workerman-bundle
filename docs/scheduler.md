# Scheduler

The scheduler runs your code again and again, on a plan.
It is like cron, but it lives inside the bundle.
You do not need a system crontab.

The scheduler is one extra process named `[Scheduler]`.
There is only one scheduler process.
It starts with `workerman:server start`, but only if at least one service has the `workerman.task` tag.

## Make a task

A task is a service of your application.
You mark it with the `#[AsTask]` attribute.

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsTask;

#[AsTask(name: 'Clean old files', schedule: '1 hour')]
final class CleanOldFiles
{
    public function __invoke(): void
    {
        // ...
    }
}
```

The attribute has these parameters. All of them are optional.

| Parameter | What it does |
|---|---|
| `name` | The name of the task. It is used in logs. If you do not set it, the service ID is the name. |
| `schedule` | When the task runs. See [Schedule formats](#schedule-formats). |
| `method` | The method to call. The default is `__invoke`. |
| `jitter` | A random delay in seconds. See [Jitter](#jitter). |

A task without a `schedule` is skipped. See [Bad or missing schedule](#bad-or-missing-schedule).

The method gets no arguments.
Ask for what you need in the constructor of the service.

## The tag in YAML

`#[AsTask]` adds the tag `workerman.task` for you.
You can add the same tag in a service file instead.
The tag has the attributes `schedule`, `method` and `jitter`.

```yaml
services:
  App\Task\CleanOldFiles:
    tags:
      - { name: workerman.task, schedule: '1 hour', method: run, jitter: 30 }
```

To set the task name in YAML, use the second form of a tag.
The tag name is the key, and the attributes are the value:

```yaml
services:
  App\Task\CleanOldFiles:
    tags:
      - workerman.task: { name: 'Clean old files', schedule: '1 hour' }
```

In the first form, `name` is the name of the tag, so it cannot be the task name.
Then the task name is the service ID.

`jitter` must be a whole number of seconds. A numeric string such as `'30'` is cast to a number. Any other value (for example `'abc'` or `1.5`) stops the container build with an error that names the service.

If a task cannot be set up when the scheduler starts, the scheduler writes a `skipped` line to the log and goes on with the other tasks.

## Schedule formats

| Format | Example | Meaning |
|---|---|---|
| Integer | `60` | Every 60 seconds. Only in YAML, as a number without quotes. |
| ISO 8601 duration | `PT1M` | Every minute. |
| Relative date | `1 minute` | Every minute. It is read by PHP `DateInterval`. |
| ISO 8601 date and time | `2023-08-01T01:00:00+08:00` | One time, at this moment. |
| Cron expression | `*/1 * * * *` | Like cron. |

Notes:

- In `#[AsTask]` the schedule is always a string, and the text `'60'` is not accepted ([#971](https://github.com/crazy-goat/workerman-bundle/issues/971)). Write `'60 seconds'` or `'PT60S'` instead.
- A date and time runs one time only. If the moment is in the past, the task never runs.
- A cron expression needs the package `dragonmantank/cron-expression`. Install it with `composer require dragonmantank/cron-expression`. Without it, the text is read as a relative date, and it is most likely not valid.
- Cron uses the PHP default time zone of the server. Set it with `date.timezone` in `php.ini` or with `date_default_timezone_set()`. An ISO 8601 date and time has its own offset.

## Fixed-rate runs

Interval schedules are fixed-rate.
Integer, ISO 8601 duration and relative date are interval schedules.
The task runs at fixed times.
The times start when the scheduler process starts.
A reload or a restart starts them again.
A slow run does not move the next run.
If a run is very slow, the scheduler skips the missed times.
It does not run them later.

Example: the schedule is `60 seconds` and a run needs 90 seconds.
The run at second 60 starts.
The run at second 120 is skipped, because the lock is still taken (see below).
The next run is at second 180.

## Jitter

`jitter` is the largest random delay in seconds.
The scheduler adds a delay from 0 to `jitter` seconds to every run.
Use it when many tasks have the same schedule and should not all start at the same moment.

With an interval schedule, the delay is part of the stored time of the run.
The next time is counted from it.
So the delays add up, and the gap between two runs is on average the interval plus `jitter / 2` seconds.
This is a known problem: [#969](https://github.com/crazy-goat/workerman-bundle/issues/969).
With a cron schedule, the times do not move.

## One run is one child process

For every run, the scheduler makes a child process with `pcntl_fork()`.
The child runs your method and then ends.
The title of the child is `[Scheduler] <task name>`.
Your task cannot block the scheduler, and a crash in the task does not stop the scheduler.

Every task has a lock file.
It is in the same directory as the Workerman PID file, and its name starts with `workerman.task.`.
Before a run, the scheduler takes the lock.
If the lock is not free, the last run is still going.
Then the scheduler skips this run and plans the next one.
So two runs of the same task never work at the same time.

The scheduler does not check the [reload strategies](reload-strategies.md) `exception`, `max_requests`, `memory` and `always`.
But `workerman:server reload` and a `file_monitor` reload restart it, and then the fixed times start again.

## Bad or missing schedule

The scheduler does not stop for a bad task.
It writes one line to the log, skips the task and goes on with the other tasks.

- No schedule: `Task "<name>" skipped. Trigger has not been set`
- Bad schedule: `Task "<name>" skipped. Trigger "<schedule>" is incorrect: <reason>`

When a task is planned, you see the line `Task "<name>" scheduled. Trigger: "<trigger>"`.
The scheduler reads the tasks only when it starts.
After you change a schedule, clear the cache and restart the server.

## Errors and events

If your method throws an exception, the task fails.
The scheduler dispatches two Symfony events:

- `CrazyGoat\WorkermanBundle\Event\TaskStartEvent` before the method runs.
- `CrazyGoat\WorkermanBundle\Event\TaskErrorEvent` when the method throws.

Both events have `getTaskName()` and `getServiceClass()`.
`TaskErrorEvent` also has `getError()`.

The bundle has a listener for `TaskErrorEvent`.
It writes the error to the Monolog channel `task`.
You can add your own listener, for example to send an alert.

```php
<?php

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class AlertOnTaskError
{
    public function __invoke(TaskErrorEvent $event): void
    {
        // $event->getTaskName(), $event->getError()
    }
}
```

A task that throws an exception is reported with `TaskErrorEvent`, and the child still exits with code 0.
A method that does not exist is reported in the same way, with `TaskErrorEvent`.
The child exits with code 1 only when the service cannot be loaded or an event listener throws.
The scheduler logs a child that exits with a code other than 0 or that is killed by a signal (`SIGKILL` is not logged when the `grpc` extension is loaded).

If the `grpc` extension is loaded, the child ends with `SIGKILL` and does not run destructors.
See [Troubleshooting](troubleshooting.md#grpc-extension-and-fork-safety).

## Examples

A cleanup task every hour:

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsTask;

#[AsTask(name: 'Cleanup', schedule: 'PT1H')]
final class Cleanup
{
    public function __invoke(): void
    {
        // delete old files
    }
}
```

A report every night at 02:30, with cron:

```php
<?php

use CrazyGoat\WorkermanBundle\Attribute\AsTask;

#[AsTask(name: 'Nightly report', schedule: '30 2 * * *', method: 'build')]
final class NightlyReport
{
    public function build(): void
    {
        // build and send the report
    }
}
```

A task with jitter, in YAML:

```yaml
services:
  App\Task\SyncPrices:
    tags:
      - { name: workerman.task, schedule: '*/5 * * * *', jitter: 60 }
```

This task runs every 5 minutes. Each run starts up to 60 seconds late.
