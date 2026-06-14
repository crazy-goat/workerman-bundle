<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Psr\Log\LoggerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;

/**
 * Handles the per-request lifecycle for the HTTP worker.
 *
 * Each incoming Workerman request flows through these stages:
 *
 * 1. **Middleware pipeline dispatch** — The Workerman Request runs through a
 *    pre-composed middleware chain (see getPipeline()). The pipeline is built
 *    ONCE in reverse middleware order and cached across requests, eliminating
 *    per-request array_reverse + closure allocation churn. The innermost layer
 *    is the controller callable that delegates to SymfonyController.
 *
 * 2. **Response send** — The Http\Response returned by the pipeline is encoded
 *    and sent via TcpConnection::send(). If a middleware already sent the
 *    response directly (e.g. StaticFilesMiddleware for static assets), this
 *    step is skipped via the responseSentDirectly context flag.
 *
 * 3. **Kernel termination** — TerminateIfNeeded() is called synchronously.
 *    The method is run inline after send() because send() is non-blocking.
 *    Errors are logged but never propagated.
 *
 * 4. **Connection close** — If the request uses HTTP/1.0 or carries a
 *    Connection: close header, the TCP connection is closed immediately.
 *
 * 5. **Reload check** — After the response is fully handled, the reboot
 *    strategy is consulted. If shouldReboot() returns true, Utils::reload()
 *    sends SIGUSR1 to trigger a graceful worker restart.
 *
 * Middleware composition: middlewares are added via withMiddlewares() /
 * withRootDirectory(). The pipeline is invalidated on every setter call and
 * rebuilt lazily on the next request. A middleware that sets
 * $connection->context->responseSentDirectly = true can fully short-circuit
 * the response-send step.
 *
 * Per-request allocations: the only per-request allocation is the thin
 * controller closure (fn(Request): Http\Response) which captures the
 * current TcpConnection. The middleware pipeline closure and all middleware
 * instances are reused across requests.
 */
final class HttpRequestHandler implements StaticFileHandlerInterface, MiddlewareDispatchInterface
{
    /** @var MiddlewareInterface[] */
    private array $middlewares = [];
    /** @var array<string, mixed> */
    private array $staticFileConfig = [];

    /**
     * Pre-composed middleware dispatch pipeline.
     *
     * Built once and cached across requests to eliminate per-request
     * array_reverse + closure allocations. Invalidated whenever
     * the middleware set changes (withMiddlewares / withRootDirectory).
     *
     * Signature: fn(Request $request, callable $controller): Http\Response
     */
    private ?\Closure $pipeline = null;

    /**
     * Whether to call memory_reset_peak_usage() on each request.
     * Determined at construction time by querying the reboot strategy.
     */
    private readonly bool $resetPeakUsage;

    public function __construct(
        private readonly SymfonyController         $controller,
        private readonly RebootStrategyInterface   $rebootStrategy,
        private readonly ?LoggerInterface          $logger = null,
    ) {
        $this->resetPeakUsage = $rebootStrategy->needsPeakMemory();
    }

    public function withMiddlewares(MiddlewareInterface ...$middlewares): self
    {
        $this->middlewares = $middlewares;
        $this->pipeline = null; // invalidate cached pipeline

        return $this;
    }

    public function withRootDirectory(?string $rootDirectory): self
    {
        if ($rootDirectory === null) {
            return $this;
        }
        $allowedExtensions = $this->staticFileConfig['allowed_extensions'] ?? [];
        $this->middlewares[] = new StaticFilesMiddleware(rtrim($rootDirectory, '/'), $allowedExtensions);
        $this->pipeline = null; // invalidate cached pipeline

        return $this;
    }

    public function withStaticFileConfig(array $staticFileConfig): self
    {
        $this->staticFileConfig = $staticFileConfig;
        return $this;
    }

    /**
     * Get or build the cached middleware dispatch pipeline.
     *
     * The pipeline is a closure: fn(Request, callable $controller): Http\Response
     * that runs the request through all middlewares and finally the controller.
     * It is composed ONCE and reused across requests, eliminating per-request
     * array_reverse and closure allocation churn documented in issue #266.
     *
     * Only the controller callable (which captures the per-request TcpConnection)
     * is created fresh on each invocation.
     */
    private function getPipeline(): \Closure
    {
        if ($this->pipeline instanceof \Closure) {
            return $this->pipeline;
        }

        // Build from the innermost (controller) outward
        $pipeline = (fn(Request $request, callable $controller): Http\Response => $controller($request));

        foreach (array_reverse($this->middlewares) as $mw) {
            $previous = $pipeline;
            $pipeline = (fn(Request $request, callable $controller): Http\Response => $mw($request, fn(Request $req): Http\Response => $previous($req, $controller)));
        }

        $this->pipeline = $pipeline;

        return $pipeline;
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
     * ensuring consistent error handling on every request.
     */
    private function doTerminate(string $errorPrefix = 'Kernel termination failed'): void
    {
        try {
            $this->controller->terminateIfNeeded();
        } catch (\Throwable $e) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error($errorPrefix, [
                    'exception' => $e,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } else {
                error_log(sprintf(
                    '%s: %s in %s:%d',
                    $errorPrefix,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }
    }

    /**
     * Determine if the connection should be closed after the response is sent.
     */
    private function shouldCloseConnection(Request $request): bool
    {
        return $request->protocolVersion() === '1.0'
            || strcasecmp((string) $request->header('Connection', ''), 'close') === 0;
    }

    /**
     * Handle one Workerman HTTP request through the full lifecycle.
     *
     * @param TcpConnection $connection The incoming TCP connection.
     * @param Request       $request    The Workerman HTTP request (extended by
     *                                  this bundle with setHeader/withHeader).
     *                                  Symfony conversion happens inside
     *                                  SymfonyController.
     */
    public function __invoke(TcpConnection $connection, Request $request): void
    {
        if ($this->resetPeakUsage) {
            \memory_reset_peak_usage();
        }

        // 1. Dispatch through middleware chain → controller
        $controllerCall = fn(Request $input): Http\Response => ($this->controller)($input, $connection);
        $pipeline = $this->getPipeline();
        $response = $pipeline($request, $controllerCall);

        // 2. Send response
        $this->sendResponse($connection, $response);

        // 3. Terminate synchronously (send() is non-blocking, no timer needed)
        $this->doTerminate();

        // 4. Close connection if protocol demands it
        if ($this->shouldCloseConnection($request)) {
            $connection->close();
        }

        // 5. Reload if strategy signals — terminates synchronously before reload
        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reload();
        }
    }
}
