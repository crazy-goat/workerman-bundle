<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;
use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Benchmark HttpRequestHandler::__invoke — the composed middleware chain
 * that represents the full per-request dispatch hot path.
 *
 * The pipeline is built once and cached; only the controller closure
 * (capturing the connection) is created per-request.
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

        $this->request = new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n");
        $this->connection = new class extends TcpConnection {
            public function __construct()
            {
                // Avoid parent constructor socket operations
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                return true;
            }

            public function close(mixed $data = null, bool $raw = false): void
            {
            }
        };
    }

    public function benchNoMiddleware(): void
    {
        ($this->handlerNoMiddleware)($this->connection, $this->request);
    }

    public function benchWithMiddleware(): void
    {
        ($this->handlerWithMiddleware)($this->connection, $this->request);
    }
}

/**
 * Minimal kernel implementation for benchmarking.
 */
final class BenchKernel implements KernelInterface, TerminableInterface
{
    public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
    }

    public function boot(): void
    {
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return new SymfonyResponse('Bench response');
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'bench';
    }

    public function isDebug(): bool
    {
        return false;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getStartTime(): float
    {
        return 0.0;
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }
}

/**
 * No-op reboot strategy for benchmarking.
 */
final class BenchRebootStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool
    {
        return false;
    }

    public function needsPeakMemory(): bool
    {
        return false;
    }
}

/**
 * Simple header-adding middleware for benchmarking.
 */
final readonly class BenchMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $header,
        private string $value,
    ) {
    }

    public function __invoke(Request $request, callable $next): WorkermanResponse
    {
        $request->setHeader($this->header, $this->value);

        return $next($request);
    }
}
