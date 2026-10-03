# Events

The scheduler and the supervisor send Symfony events.
You can listen to them, for example to send an alert when a task fails.
The events are sent on the normal Symfony event dispatcher, so you use them like all other Symfony events.

## The events

| Event | Sent | Methods |
|---|---|---|
| `CrazyGoat\WorkermanBundle\Event\TaskStartEvent` | Before a scheduled task method runs. | `getTaskName()`, `getServiceClass()` |
| `CrazyGoat\WorkermanBundle\Event\TaskErrorEvent` | When a scheduled task method throws. | `getTaskName()`, `getServiceClass()`, `getError()` |
| `CrazyGoat\WorkermanBundle\Event\ProcessStartEvent` | Before a supervised process method runs. | `getProcessName()`, `getServiceClass()` |
| `CrazyGoat\WorkermanBundle\Event\ProcessErrorEvent` | When a supervised process method throws. | `getProcessName()`, `getServiceClass()`, `getError()`, `setError()` |

The task or process name is the `name` of the attribute.
If you did not set a name, it is the service ID.
`getServiceClass()` is the class of the service.
`getError()` is the exception that was thrown.

See [Scheduler](scheduler.md) and [Supervisor](supervisor.md) for how tasks and processes run.

## When an event is sent

The start event is sent after the service is created and just before your method is called.
The error event is sent when your method throws any `Throwable`.
It is also sent when the configured method does not exist on the service.
The exception is caught and not thrown again.

- For a task, the child process still ends with exit code 0.
- For a process, the method counts as ended, and the process starts again (see [Supervisor](supervisor.md#what-happens-when-the-method-ends)).

If the service cannot be created, for example when its constructor throws, no event is sent.
The start event and the error event are both sent only after the service exists.
A task child then ends with exit code 1, and the scheduler writes a `Task "<name>" failed` line to the log.

## Built-in listeners

The bundle has one listener for each error event.
Both run with priority `-128`, so your listeners with the default priority `0` run before them.

- `TaskErrorEvent`: writes a `critical` log line to the Monolog channel `task`.
- `ProcessErrorEvent`: writes a `critical` log line to the Monolog channel `process`.

The log line has the name and the message of the error, and the exception in the context.
`ProcessErrorEvent` has `setError()`.
If your listener sets another error, the built-in listener logs that one.

## Example: send task errors to Sentry

```php
<?php

use CrazyGoat\WorkermanBundle\Event\TaskErrorEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class SendTaskErrorToSentry
{
    public function __invoke(TaskErrorEvent $event): void
    {
        \Sentry\withScope(static function (\Sentry\State\Scope $scope) use ($event): void {
            $scope->setTag('task', $event->getTaskName());
            \Sentry\captureException($event->getError());
        });
    }
}
```

The same works for `ProcessErrorEvent`, with `getProcessName()`.
