<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Timer;

final class HttpRequestHandler implements StaticFileHandlerInterface, MiddlewareDispatchInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    private readonly SymfonyController $controller;
    private ?int $terminateTimerId = null;

    public function __construct(
        private readonly KernelInterface         $kernel,
        private readonly RebootStrategyInterface $rebootStrategy,
        private readonly ResponseConverter       $responseConverter,
    ) {
        $this->controller = new SymfonyController($this->kernel, $this->responseConverter);
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
        \memory_reset_peak_usage();
        $shouldCloseConnection = $request->protocolVersion() === '1.0' || $request->header('Connection', '') === 'close';

        $next = fn(Request $input): Http\Response => ($this->controller)($input, $connection);
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = fn(Request $input): Http\Response => $middleware($input, $next);
        }

        $response = $next($request);

        $connection->send(Http::encode($response, $connection), true);

        // Cancel any pending terminate timer from previous request (safety)
        if ($this->terminateTimerId !== null) {
            Timer::del($this->terminateTimerId);
            $this->terminateTimerId = null;
        }

        // Defer terminate() to next event loop tick - non-blocking
        $timerId = Timer::add(0, function (): void {
            try {
                $this->controller->terminateIfNeeded();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'Kernel termination failed: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }, persistent: false);
        $this->terminateTimerId = $timerId;

        if ($shouldCloseConnection) {
            $connection->close();
        }

        // Ensure terminate completes before reboot to avoid race conditions
        if ($this->rebootStrategy->shouldReboot()) {
            Timer::del($timerId);
            $this->terminateTimerId = null;
            // Call terminate synchronously before reboot
            try {
                $this->controller->terminateIfNeeded();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'Kernel termination failed during reboot: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
            Utils::reboot();
        }
    }
}
