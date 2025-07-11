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

final class HttpRequestHandler implements StaticFileHandlerInterface
{
    /**
     * @param array<MiddlewareInterface> $middlewares
     */
    public function __construct(
        private readonly KernelInterface         $kernel,
        private readonly RebootStrategyInterface $rebootStrategy,
        private array $middlewares = [],
    ) {
    }

    public function withRootDirectory(?string $rootDirectory): self
    {
        if ($rootDirectory === null) {
            return $this;
        }
        $this->middlewares[] = new StaticFilesMiddleware(rtrim($rootDirectory, '/'));
        return $this;
    }

    public function __invoke(TcpConnection $connection, Http\Request  $request): void
    {
        if (PHP_VERSION_ID >= 80200) {
            \memory_reset_peak_usage();
        }
        $shouldCloseConnection = $this->shouldCloseConnection($request);

        $next = new SymfonyController($this->kernel);
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = fn(Http\Request $input): Http\Response => $middleware($request, $next);
        }

        $response = $next($request);
        $this->sendAndClose($connection, $response, $shouldCloseConnection);

        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reboot();
        }
    }

    private function sendAndClose(TcpConnection $connection, Http\Response $response, bool $shouldCloseConnection): void
    {
        $connection->send(Http::encode($response, $connection), true);

        if ($shouldCloseConnection) {
            $connection->close();
        }
    }

    public function shouldCloseConnection(Http\Request $request): bool
    {
        return $request->protocolVersion() === '1.0' || $request->header('Connection', '') === 'close';
    }
}
