<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Workerman\Connection\TcpConnection;

/**
 * Benchmark HttpRequestHandler::__invoke — the composed middleware chain
 * that represents the full per-request dispatch hot path.
 *
 * The pipeline is built once and cached; per-request allocations are one
 * controller closure (capturing the connection) and one MiddlewareDispatcher
 * object — independent of the middleware count (issue #563).
 *
 * @BeforeMethods("init")
 * @Revs(1000)
 * @Iterations(5)
 * @Warmup(1)
 */
final class HttpRequestHandlerBench
{
    private HttpRequestHandler $handlerNoMiddleware;
    private HttpRequestHandler $handlerWithMiddleware;
    private HttpRequestHandler $handlerWithFiveMiddleware;
    private Request $request;
    private TcpConnection $connection;

    public function init(): void
    {
        $kernel = new BenchKernel();
        $responseConverter = new ResponseConverter([new DefaultResponseStrategy()]);
        $controller = new SymfonyController($kernel, $responseConverter);
        $rebootStrategy = new BenchRebootStrategy();

        $this->handlerNoMiddleware = new HttpRequestHandler($controller, $rebootStrategy);
        $this->handlerWithMiddleware = (new HttpRequestHandler($controller, $rebootStrategy))
            ->withMiddlewares(
                new BenchMiddleware('X-Bench-1', 'value-1'),
                new BenchMiddleware('X-Bench-2', 'value-2'),
                new BenchMiddleware('X-Bench-3', 'value-3'),
            );
        $this->handlerWithFiveMiddleware = (new HttpRequestHandler($controller, $rebootStrategy))
            ->withMiddlewares(
                new BenchMiddleware('X-Bench-1', 'value-1'),
                new BenchMiddleware('X-Bench-2', 'value-2'),
                new BenchMiddleware('X-Bench-3', 'value-3'),
                new BenchMiddleware('X-Bench-4', 'value-4'),
                new BenchMiddleware('X-Bench-5', 'value-5'),
            );

        $this->request = new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n");
        $this->connection = new BenchTcpConnection();
    }

    public function benchNoMiddleware(): void
    {
        ($this->handlerNoMiddleware)($this->connection, $this->request);
    }

    public function benchWithMiddleware(): void
    {
        ($this->handlerWithMiddleware)($this->connection, $this->request);
    }

    public function benchWithFiveMiddleware(): void
    {
        ($this->handlerWithFiveMiddleware)($this->connection, $this->request);
    }
}
