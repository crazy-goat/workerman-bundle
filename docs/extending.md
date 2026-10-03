# Extending the bundle

You can add your own parts to the bundle with service tags.
A tag is a label on a service. The bundle finds all services with the label.

| Tag | What it is | Page |
|---|---|---|
| `workerman.task` | A scheduled task. | [Scheduler](scheduler.md) |
| `workerman.process` | A supervised process. | [Supervisor](supervisor.md) |
| `workerman.reboot_strategy` | A rule that reloads a worker. | [Reload strategies](reload-strategies.md#write-your-own-strategy) |
| `workerman.response_converter.strategy` | A way to send a Symfony response. | [Below](#response-strategies) |

For HTTP middlewares, see [Middlewares](middlewares.md).
For events, see [Events](events.md).

## Response strategies

After the controller returns a Symfony response, the bundle turns it into a Workerman response.
A response strategy does this for one kind of response.
You can add your own strategy, for example for a special response class.

The built-in strategies are:

| Strategy | Priority | Handles |
|---|---|---|
| `BinaryFileResponseStrategy` | `100` | `BinaryFileResponse` |
| `StreamedResponseStrategy` | `50` | `StreamedResponse` |
| `DefaultResponseStrategy` | `0` | Everything else |

### Write a strategy

Write a service that implements `CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface`.
It has two methods:

- `supports(SymfonyResponse $response): bool` says if the strategy can handle this response.
- `convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse` makes the Workerman response.

The bundle has already read the headers for you, and they are in `$headers`.
`Content-Length`, `Accept-Ranges` and `Transfer-Encoding` are removed.
They belong to the transport, and Workerman sets them.

If your strategy must know the request method, implement `RequestMethodAwareResponseConverterStrategyInterface` instead.
Its `convert()` method has two more arguments: `string $requestMethod = 'GET'` and `bool $shouldClose = false`.
A strategy for files needs this for `HEAD` requests, which must have no body.

Add the tag `workerman.response_converter.strategy` and a `priority`:

```php
<?php

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

#[AutoconfigureTag('workerman.response_converter.strategy', ['priority' => 10])]
final class CsvResponseStrategy implements ResponseConverterStrategyInterface
{
    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof CsvResponse;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse
    {
        return new WorkermanResponse($response->getStatusCode(), $headers, (string) $response->getContent());
    }
}
```

The same tag in YAML:

```yaml
services:
  App\Http\CsvResponseStrategy:
    tags:
      - { name: workerman.response_converter.strategy, priority: 10 }
```

### Priority

The bundle asks the strategies from the highest priority to the lowest.
The first strategy where `supports()` is `true` is used.
If you do not set a priority, it is `0`.

`DefaultResponseStrategy` handles every response and has priority `0`.
So give your strategy a priority above `0`, or it may never be asked.
Use a priority above `100` if your strategy must go before the file strategy.
If no strategy supports a response, the bundle throws `NoResponseStrategyException`.

## Is this a public API?

Yes. The two strategy interfaces and the tags in the table above are part of the public API.
Changes that break your code are written down in [UPGRADE.md](../UPGRADE.md).
