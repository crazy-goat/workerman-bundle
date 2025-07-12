<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;

final class HttpRequestHandler implements StaticFileHandlerInterface, MiddlewareDispatchInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private readonly SymfonyController $controller;

    /**
     */
    public function __construct(
        private readonly KernelInterface         $kernel,
        private readonly RebootStrategyInterface $rebootStrategy,
    ) {
        $this->controller = new SymfonyController($this->kernel);
    }

    public function withMiddlewares(MiddlewareInterface ...$middlewares): self
    {
        $this->middlewares = $middlewares;

        return $this;
    }

    public function withRootDirectory(?string $rootDirectory): self
    {
        if ($rootDirectory === null) {
            return $this;
        }
        $this->middlewares[] = new StaticFilesMiddleware(rtrim($rootDirectory, '/'));
        return $this;
    }

    public function __invoke(TcpConnection $connection, Request $request): void
    {
        if (PHP_VERSION_ID >= 80200) {
            \memory_reset_peak_usage();
        }
        $shouldCloseConnection = $request->protocolVersion() === '1.0' || $request->header('Connection', '') === 'close';

        $next = $this->controller;
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = fn(Request $input): Http\Response => $middleware($request, $next);
        }

        $response = $next($request);

        $connection->send(Http::encode($response, $connection), true);

        if ($shouldCloseConnection) {
            $connection->close();
        }

        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reboot();
        }
    }
}
