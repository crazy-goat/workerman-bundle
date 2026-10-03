# Scheduler

The scheduler runs your code again and again, on a plan.
It is like cron, but it lives inside the bundle.
You do not need a system crontab.

The scheduler is one extra process named `[Scheduler]`.
There is only one scheduler process.
It starts with `workerman:server start`.

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

In YAML, the key `name` is the name of the tag, so you cannot set the task name there.
The task name is the service ID.
Set `name` with the `#[AsTask]` attribute if you want another name.

Write `jitter` as a number, not as a string.

## Schedule formats

| Format | Example | Meaning |
|---|---|---|
| Integer | `60` | Every 60 seconds. |
| ISO 8601 duration | `PT1M` | Every minute. |
| Relative date | `1 minute` | Every minute. It is read by PHP `DateInterval`. |
| ISO 8601 date and time | `2023-08-01T01:00:00+08:00` | One time, at this moment. |
| Cron expression | `*/1 * * * *` | Like cron. |

Notes:

- A date and time runs one time only. If the moment is in the past, the task never runs.
- A cron expression needs the package `dragonmantank/cron-expression`. Install it with `composer require dragonmantank/cron-expression`. Without it, the text is read as a relative date, and it is most likely not valid.
- Cron uses the PHP default time zone of the server. Set it with `date.timezone` in `php.ini` or with `date_default_timezone_set()`. An ISO 8601 date and time has its own offset.
- In PHP code, you can also give a `DateInterval` with a fraction, for example `DateInterval::createFromDateString('500 ms')`. This is not possible in `#[AsTask]`, because attributes take only text and numbers.

## Fixed-rate runs

Interval schedules are fixed-rate.
Integer, ISO 8601 duration and relative date are interval schedules.
The task runs at fixed times.
The first run sets the times.
A slow run does not move the next run.
If a run is very slow, the scheduler skips the missed times.
It does not run them later.

Example: the schedule is `60` and a run needs 90 seconds.
The next run at second 60 was missed, so it is skipped.
The scheduler waits for the time at second 120.

## Jitter

`jitter` is the largest random delay in seconds.
The scheduler adds a delay from 0 to `jitter` seconds to every run.
Use it when many tasks have the same schedule and should not all start at the same moment.

With an interval schedule, jitter does not move the fixed times.
Only the start of each run is delayed.

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

The scheduler does not use the [reload strategies](reload-strategies.md).

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

After a failed task, the child exits with code 1.
The scheduler logs a child that exits with a code other than 0 or that is killed by a signal.

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
      - { name: workerman.task, schedule: 300, jitter: 60 }
```

This task runs every 5 minutes. Each run starts up to 60 seconds late.
