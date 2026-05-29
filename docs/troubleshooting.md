# Troubleshooting

Workerman keeps the Symfony kernel and DI container alive across requests. This is the source of its performance advantage — and also the source of the most common surprises when moving from a traditional PHP-FPM setup.

This page documents the long-running worker gotchas, how to recognise them, and what to do about them.

## Symfony Container Reuse and Service State Pollution

### The problem

In PHP-FPM each request gets a fresh container. In Workerman the same container instance lives for the lifetime of the worker (potentially thousands of requests). **Any service that accumulates state will carry that state into the next request.**

Commonly affected services:

- **Doctrine's `EntityManager`**: It has an internal identity map. After the first request, entities loaded in that request remain in the identity map. Subsequent requests see stale data unless `EntityManager::clear()` is called.
- **Monolog handlers with buffering**: Memory or stream handlers that buffer log entries will grow unbounded.
- **Custom repositories / services that cache results in instance properties**: Any service that stores request-scoped data in a property will leak data between requests.
- **Kernel state**: The Symfony kernel itself can accumulate state — e.g. the `_request_stack` can grow if requests are not properly popped.

### Detection

- Data from one user's request appears in another user's response
- Entities that should have been updated in the database still return old values
- Monotonically growing memory usage that never drops after a response is sent

### Mitigation

- **Ensure services are stateless or properly reset**. The best approach is to mark request-scoped services as `kernel.reset`-aware by implementing `Symfony\Component\HttpKernel\DependencyInjection\ResettableServiceInterface` (or adding a `reset()` method with the `kernel.reset` tag). Workerman calls `kernel.reset` after each request.
- **Use `EntityManager::clear()`** before each request in a middleware or event listener (or rely on the Doctrine bundle's `Doctrine\Bundle\DoctrineBundle\ManagerConfigurator` when it is correctly configured for the Workerman runtime).
- **Use the `max_requests` or `always` reload strategy** to periodically recreate the container as a safety net:
  ```yaml
  workerman:
    reload_strategy:
      max_requests:
        active: true
        max_requests: 1000
  ```
  See [reload strategies](../README.md#reload-strategies) for details.

## Static and Global State That Survives Requests

### The problem

Static class properties, global variables, and closures that capture variables by reference persist across requests. Code that works correctly in PHP-FPM (where the process is killed after the request) can silently leak state in a long-running worker.

### Detection

- Values unexpectedly persisting across requests
- Singletons that return different results for different calls without an obvious reason
- Test code that passes in isolation but fails when run in sequence

### Mitigation

- **Avoid static state entirely** for request-scoped data.
- If you must use static properties (e.g. for a memoisation cache), ensure they are scoped per worker and cleared when no longer needed.
- **Prefer dependency injection** over global state.
- Use the `exception` reload strategy to restart the worker when an unexpected exception occurs:
  ```yaml
  workerman:
    reload_strategy:
      exception:
        active: true
  ```

## Blocking IO on the Event Loop

### The problem

Workerman is single-threaded per worker process and uses an event loop. **Any blocking call in a request handler stalls the entire worker** — no other request can be processed until the blocking call completes.

Common blocking operations:

- **`sleep()`** — this suspends the whole worker. Use Workerman's `Timer` or Symfony's scheduler instead.
- **`file_get_contents()` / `file()` on slow remote URLs** — the worker cannot accept other requests while waiting.
- **`curl_exec()`** in synchronous mode.
- **Heavy file operations** on slow disks or NFS mounts.
- **`usleep()`** — same as `sleep()` but at microsecond resolution.

### Detection

- All requests to a particular worker hang simultaneously
- The server becomes unresponsive under load
- Response times are highly variable depending on which requests land on which worker

### Mitigation

- **Do not use `sleep()`, `usleep()`, or `time_nanosleep()` in request handlers.** Use Workerman's `Timer::add()` or the bundle's [scheduler](../README.md#scheduler) for periodic work.
- **Use non-blocking HTTP clients** such as `React\Http\Browser` or Guzzle with the `curl` handler in parallel mode.
- **Offload slow operations** to a separate worker process running as a [supervised process](../README.md#supervisor).
- **Increase the number of worker processes** (`processes: N`) so that other workers can handle requests while one is blocked. This treats the symptom, not the cause — still aim to fix blocking calls.
- Consider using the `php-event` extension for better event-loop performance.

## Database Connections Going Stale

### The problem

Database servers close idle connections after a timeout (`wait_timeout` in MySQL, `idle_in_transaction_session_timeout` in PostgreSQL). In a long-running worker, a DB connection opened at worker start will be silently closed by the server if no query is executed within the timeout window. The worker will then throw a `MySQL has gone away` or similar error on the next query.

This also affects Doctrine's `EntityManager` which holds a reference to the connection.

### Detection

- Intermittent `MySQL has gone away` / `SQLSTATE[HY000] [2006]` errors
- Errors that disappear when you reload the worker (because the connection is re-established)
- `PDOException: SQLSTATE[HY000]: General error: 2006 MySQL server has gone away`

### Mitigation

- **Enable the `max_requests` reload strategy** so workers are recycled before connections go stale:
  ```yaml
  workerman:
    reload_strategy:
      max_requests:
        active: true
        max_requests: 500
  ```
- **Configure Doctrine to reconnect automatically** — Doctrine's `ping` middleware can test the connection before executing a query. In `config/packages/doctrine.yaml`:
  ```yaml
  doctrine:
    dbal:
      options:
        # Disable the stale-connection check that prevents reconnect
        x.use_savepoints: false
      # Or catch the exception and reconnect manually
  ```
  A more robust approach is to register an event listener that calls `EntityManager::getConnection()->ping()` before each request.
- **Set `wait_timeout` appropriately** on your MySQL server (at least higher than your `max_requests` × average request duration).
- **Use a middleware** that calls `EntityManager::clear()` and reconnects if the connection is closed:
  ```php
  use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
  use Doctrine\ORM\EntityManagerInterface;

  final readonly class DoctrinePingMiddleware implements MiddlewareInterface
  {
      public function __construct(
          private EntityManagerInterface $entityManager,
      ) {}

      public function __invoke(Request $request, callable $next): Response
      {
          $connection = $this->entityManager->getConnection();
          if (!$connection->ping()) {
              $connection->close();
              $connection->connect();
          }

          return $next($request);
      }
  }
  ```

## Opcache Caveats

### The problem

PHP's opcache caches compiled PHP files in shared memory. In a long-running worker:

1. **File-monitor reload vs. opcache** — When using `file_monitor` reload strategy, file changes are detected and the worker reloads. However, opcache's `validate_timestamps` setting controls whether PHP checks if files have changed on disk. If `opcache.validate_timestamps=0` (common in production for performance), the old compiled code runs even after a reload, and you must call `opcache_reset()` explicitly after deploying new code.

2. **Development workflow** — In dev mode with `file_monitor` active, you need `opcache.validate_timestamps=1` and `opcache.revalidate_freq=0` so that code changes are picked up immediately.

3. **Memory exhaustion** — Opcache uses a fixed-size shared memory buffer. If your application grows significantly, opcache may run out of memory and stop caching new files, silently falling back to interpreting them.

### Detection

- Code changes are not reflected after a reload (stale opcache)
- After deploying new code, running `bin/console workerman:server reload` does not pick up the changes
- `opcache.status` shows a full shared memory pool

### Mitigation

- **Development** — Set in your `php.ini`:
  ```ini
  opcache.enable=1
  opcache.validate_timestamps=1
  opcache.revalidate_freq=0
  opcache.memory_consumption=256
  ```
- **Production** — If `opcache.validate_timestamps=0` for maximum performance:
  - Call `opcache_reset()` in your deploy script after moving the new release into place, before reloading workers.
  - Or use `bin/console workerman:server restart` (which starts a completely new set of workers) instead of `reload`.
  - Or set `opcache.validate_timestamps=1` with `opcache.revalidate_freq=2` (check every 2 seconds — acceptable for most deployments).
- **Monitor opcache memory** — Use `opcache_get_status()['memory_usage']['free_memory']` in a health-check endpoint or (for prometheus) expose it via the [symfony-opcache-metrics](https://github.com/ovrflo/symfony-opcache-metrics) bundle.
- **Use the `always` reload strategy during an active deploy window** to guarantee every worker picks up fresh code.

## gRPC Extension and Fork Safety

### The problem

If you have the `grpc` PHP extension installed, it spawns internal background threads. Forking a worker process (which happens in the scheduler when tasks run in forked child processes) will **deadlock** in these extension threads unless fork support is explicitly enabled.

### Symptoms

- Scheduled tasks that run in a forked child process hang indefinitely
- Worker processes become unresponsive after executing scheduled tasks
- No error logged — the process simply stops responding

### Mitigation

- **Set `GRPC_ENABLE_FORK_SUPPORT=1`** in your environment before starting the Workerman server:
  ```bash
  export GRPC_ENABLE_FORK_SUPPORT=1
  bin/console workerman:server start
  ```
  Or add it to your `.env` file:
  ```dotenv
  GRPC_ENABLE_FORK_SUPPORT=1
  ```

## Reload Strategies Reference

Consider which restart strategy matches your deployment model:

| Strategy | When to use | Frequency |
|----------|-------------|-----------|
| [`exception`](../README.md#reload-strategies) | Catch unexpected service state corruption after exceptions | On exception |
| [`max_requests`](../README.md#reload-strategies) | Safety net for memory leaks, stale connections, state pollution | Every N requests |
| [`file_monitor`](../README.md#reload-strategies) | Development: pick up code changes without restarting | On file change |
| [`always`](../README.md#reload-strategies) | Highest isolation — every request gets a fresh worker | Every request |
| [`memory`](../README.md#reload-strategies) | Stop runaway memory before OOM | When RSS exceeds limit |

Combine multiple strategies. For example, production typically runs `exception` + `max_requests` + `memory`.
