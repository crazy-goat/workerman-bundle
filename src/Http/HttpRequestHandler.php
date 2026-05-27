<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Timer;

final class HttpRequestHandler implements StaticFileHandlerInterface, MiddlewareDispatchInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    /** @var array<string, mixed> */
    private array $staticFileConfig = [];
    private ?int $terminateTimerId = null;

    public function __construct(
        private readonly SymfonyController         $controller,
        private readonly RebootStrategyInterface   $rebootStrategy,
    ) {
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
        $allowedExtensions = $this->staticFileConfig['allowed_extensions'] ?? [];
        $this->middlewares[] = new StaticFilesMiddleware(rtrim($rootDirectory, '/'), $allowedExtensions);
        return $this;
    }

    public function withStaticFileConfig(array $staticFileConfig): self
    {
        $this->staticFileConfig = $staticFileConfig;
        return $this;
    }

    /**
     * Build the middleware dispatch chain.
     *
     * Composes middlewares around the controller into a single callable.
     * The chain is built on each invocation so middlewares can be reconfigured
     * between requests (e.g. via withRootDirectory).
     *
     * @return callable(Request): Http\Response
     */
    private function buildMiddlewareChain(TcpConnection $connection): callable
    {
        $next = fn(Request $input): Http\Response => ($this->controller)($input, $connection);
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = fn(Request $input): Http\Response => $middleware($input, $next);
        }

        return $next;
    }

    /**
     * Send the response to the connection, unless already sent by a middleware.
     */
    private function sendResponse(TcpConnection $connection, Http\Response $response): void
    {
        $responseAlreadySent = $connection->context instanceof \stdClass
            && isset($connection->context->responseSentDirectly);
        if ($responseAlreadySent) {
            unset($connection->context->responseSentDirectly);

            return;
        }

        $connection->send(Http::encode($response, $connection), true);
    }

    /**
     * Execute kernel termination with error logging.
     *
     * This is the single location where terminateIfNeeded() is called,
     * ensuring consistent error handling whether invoked deferred or synchronously.
     */
    private function doTerminate(string $errorPrefix = 'Kernel termination failed'): void
    {
        try {
            $this->controller->terminateIfNeeded();
        } catch (\Throwable $e) {
            error_log(sprintf(
                '%s: %s in %s:%d',
                $errorPrefix,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }
    }

    /**
     * Schedule kernel termination on the next event-loop tick.
     *
     * Cancels any previously pending terminate timer as a safety guard
     * against stale timers from prior requests.
     */
    private function scheduleTerminate(): void
    {
        if ($this->terminateTimerId !== null) {
            Timer::del($this->terminateTimerId);
            $this->terminateTimerId = null;
        }

        $this->terminateTimerId = Timer::add(0, function (): void {
            $this->doTerminate();
        }, persistent: false);
    }

    /**
     * Determine if the connection should be closed after the response is sent.
     */
    private function shouldCloseConnection(Request $request): bool
    {
        return $request->protocolVersion() === '1.0'
            || $request->header('Connection', '') === 'close';
    }

    public function __invoke(TcpConnection $connection, Request $request): void
    {
        \memory_reset_peak_usage();

        // 1. Dispatch through middleware chain → controller
        $chain = $this->buildMiddlewareChain($connection);
        $response = $chain($request);

        // 2. Send response
        $this->sendResponse($connection, $response);

        // 3. Schedule deferred terminate on next event-loop tick
        $this->scheduleTerminate();

        // 4. Close connection if protocol demands it
        if ($this->shouldCloseConnection($request)) {
            $connection->close();
        }

        // 5. Reload if strategy signals — terminates synchronously before reload
        if ($this->rebootStrategy->shouldReboot()) {
            if ($this->terminateTimerId !== null) {
                Timer::del($this->terminateTimerId);
                $this->terminateTimerId = null;
            }
            $this->doTerminate('Kernel termination failed during reload');
            Utils::reload();
        }
    }
}
