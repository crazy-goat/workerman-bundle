<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewarePipelinePassesModifiedRequestToSubsequentMiddleware(): void
    {
        $tracker = new MiddlewareTracker();

        $firstMiddleware = new AddHeaderMiddleware('X-First-Modified', 'value-from-first', $tracker, 'first_header');
        $secondMiddleware = new ReadHeaderMiddleware('x-first-modified', $tracker, 'second_saw_first', 'second_first_value');

        $this->executeMiddlewarePipeline([$firstMiddleware, $secondMiddleware]);

        self::assertSame('value-from-first', $tracker->get('first_header'));
        self::assertTrue($tracker->get('second_saw_first'));
        self::assertSame('value-from-first', $tracker->get('second_first_value'));
    }

    public function testThreeMiddlewarePipelinePassesModifiedRequest(): void
    {
        $tracker = new MiddlewareTracker();

        $middleware1 = new AddHeaderMiddleware('X-Header-1', 'value-1', $tracker, null);
        $middleware2 = new ReadAndAddHeaderMiddleware('x-header-1', $tracker, 'm2_header1', 'X-Header-2', 'value-2');
        $middleware3 = new ReadTwoHeadersMiddleware('x-header-1', 'x-header-2', $tracker, 'm3_header1', 'm3_header2');

        $this->executeMiddlewarePipeline([$middleware1, $middleware2, $middleware3]);

        self::assertSame('value-1', $tracker->get('m2_header1'));
        self::assertSame('value-1', $tracker->get('m3_header1'));
        self::assertSame('value-2', $tracker->get('m3_header2'));
    }

    /**
     * @param MiddlewareInterface[] $middlewares
     */
    private function executeMiddlewarePipeline(array $middlewares): void
    {
        $finalHandler = static fn(Request $request): WorkermanResponse => new WorkermanResponse(200, [], 'Final');

        $next = $finalHandler;
        foreach (array_reverse($middlewares) as $middleware) {
            $next = static fn(Request $input) => $middleware($input, $next);
        }

        $request = new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $next($request);
    }
}

final class MiddlewareTracker
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}

final readonly class AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $header,
        private string $value,
        private MiddlewareTracker $tracker,
        private ?string $trackKey,
    ) {
    }

    public function __invoke(Request $request, callable $next): WorkermanResponse
    {
        $request->withHeader($this->header, $this->value);
        if ($this->trackKey !== null) {
            $this->tracker->set($this->trackKey, $request->header(strtolower($this->header)));
        }
        return $next($request);
    }
}

final readonly class ReadHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $headerName,
        private MiddlewareTracker $tracker,
        private string $sawKey,
        private string $valueKey,
    ) {
    }

    public function __invoke(Request $request, callable $next): WorkermanResponse
    {
        $this->tracker->set($this->sawKey, $request->header($this->headerName) !== null);
        $this->tracker->set($this->valueKey, $request->header($this->headerName));
        return $next($request);
    }
}

final readonly class ReadAndAddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $readHeaderName,
        private MiddlewareTracker $tracker,
        private string $trackKey,
        private string $addHeader,
        private string $addValue,
    ) {
    }

    public function __invoke(Request $request, callable $next): WorkermanResponse
    {
        $this->tracker->set($this->trackKey, $request->header($this->readHeaderName));
        $request->withHeader($this->addHeader, $this->addValue);
        return $next($request);
    }
}

final readonly class ReadTwoHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $header1,
        private string $header2,
        private MiddlewareTracker $tracker,
        private string $key1,
        private string $key2,
    ) {
    }

    public function __invoke(Request $request, callable $next): WorkermanResponse
    {
        $this->tracker->set($this->key1, $request->header($this->header1));
        $this->tracker->set($this->key2, $request->header($this->header2));
        return $next($request);
    }
}
