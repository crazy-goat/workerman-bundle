# Reload strategies

A worker is a child process that handles requests.
It keeps the Symfony kernel and the services in memory between requests.
This is fast, but some things go wrong over time: memory grows, a service keeps bad state after an error, or you change the code and the worker still runs the old code.
A reload strategy tells the server when to replace a worker with a new one.

You can turn each strategy on or off.
Use different strategies for `dev` and `prod`.
The config keys and their defaults are in the [configuration reference](configuration.md#reload_strategy).

## The strategies

| Strategy | What it does | Use it |
|----------|--------------|--------|
| `exception` | Reloads the worker after a request that threw an error. | In `prod`. It is on by default. |
| `max_requests` | Reloads the worker after about N requests. | In `prod`, as a safety net against memory leaks and stale state. |
| `memory` | Reloads the worker when it uses too much memory. | In `prod`, to stop a worker before the system kills it. |
| `file_monitor` | Reloads all workers when a file changes. | In `dev` only. |
| `always` | Reloads the worker after each request. | To test, or when each request needs a clean worker. It is slow. |

You can turn on many strategies at the same time.
The worker reloads when at least one strategy says so.
For example, `prod` often uses `exception`, `max_requests` and `memory`.

```yaml
# config/packages/workerman.yaml
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
```

`exception`, `max_requests`, `memory` and `always` run on the HTTP workers.
The server asks each strategy after it has sent the response and finished the request.
If one says yes, the worker reloads itself.
The next request goes to another worker.
Scheduler and supervisor processes do not use these strategies.

## exception

The worker reloads after a request that threw an error, because a service can be in a bad state after an error.
Only errors of the main request count.
An error that is in `allowed_exceptions` does not reload the worker.
By default these are the Symfony `HttpExceptionInterface` (for example a 404 page) and all Symfony Serializer errors.
See the default list in the [configuration reference](configuration.md#reload_strategy).

## max_requests

The worker reloads after about `requests` requests.
With `dispersion`, each worker picks its own number between `requests` minus `dispersion` percent and `requests`.
Then the workers do not all reload at the same time.
With `requests: 1000` and `dispersion: 20`, a worker reloads after about 800 to 1000 requests.
Use `dispersion: 0` for the same number in every worker.

## memory

The worker reloads when the memory is above `limit`.
The default is 128 MB.
The bundle measures PHP memory with `memory_get_usage()`.
This is not the memory that the system shows for the process.

Before it reloads, the worker can try to free memory.
When the memory is above `gc_limit` (default 96 MB), the worker runs `gc_collect_cycles()`.
It does this at most once in `gc_cooldown` seconds (default 60).

- If the memory is already above `limit`, the collection runs at once. The worker decides with the memory after the collection. So a collection that frees enough memory avoids the reload.
- If the memory is only above `gc_limit`, the collection runs a little later, so the request is not slower. It helps the next requests.
- If the cooldown is not over, the worker decides with the current memory.

```yaml
workerman:
  reload_strategy:
    memory:
      active: true
      limit: 268435456       # 256 MB
      gc_limit: 201326592    # 192 MB
      gc_cooldown: 180       # at least 3 minutes between two collections
```

## file_monitor

A separate process watches your files.
The process starts only in debug mode (`APP_DEBUG=1`).
It is off in a PHAR file. There the server writes a log line, and you must use `restart` to load new code.
When a file changes, it reloads all workers.
Use it in `dev` only.

With the `inotify` PHP extension, the watch is fast and cheap.
It waits a short time (a third of a second) after a change, so one save that changes many files gives one reload.
Without it, the process looks at the files every `polling_interval` seconds.
This can use much CPU and disk in a big project.
Install `php-inotify` for a big project.
Set `source_dir` and `file_pattern` to watch only the files that matter.
Without `inotify`, `max_files_per_tick` is the most directory entries that one check looks at.

## always

The worker reloads after each request.
Each request gets a clean worker, but every request pays the start time of the kernel.

```yaml
workerman:
  reload_strategy:
    always:
      active: true
```

## Write your own strategy

Write a service that implements `CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface`.
Add the tag `workerman.reboot_strategy`.
The method `shouldReboot()` runs after each request.
Return `true` to reload the worker.
The service lives as long as the worker, so it can keep a counter or a time.

```php
<?php

use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('workerman.reboot_strategy')]
final class MaxAgeRebootStrategy implements RebootStrategyInterface
{
    private readonly int $startedAt;

    public function __construct()
    {
        $this->startedAt = time();
    }

    public function shouldReboot(): bool
    {
        // Reload a worker that is older than one hour.
        return time() - $this->startedAt > 3600;
    }
}
```

## Reload from your code and from the command line

Your code can reload the worker with `Utils::reload()`.
The command `bin/console workerman:server reload` reloads all workers.
See [commands.md](commands.md#reload-from-your-code).

## See also

- [Troubleshooting](troubleshooting.md): why long-running workers need reloads.
- [Configuration reference](configuration.md#reload_strategy): every key and default.
