<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Exception\ClientInputExceptionInterface;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Utils;
use Psr\Log\LoggerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response as WorkermanResponse;

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
     * Classify a throwable as a client-caused error (400) or a server fault (500).
     *
     * Client errors are caused by malformed input that the bundle rejects
     * during request conversion (control bytes in headers, malformed
     * multipart uploads, invalid URI/method). They are marked with
     * ClientInputExceptionInterface and logged at debug level to prevent
     * log flooding by an unauthenticated attacker (see issue #577).
     *
     * Server faults are everything else: middleware bugs, response
     * conversion failures, misconfiguration, kernel errors that escaped
     * HttpKernel::handle()'s own try block, etc. They are logged at error
     * level because they indicate a real defect. Notably a middleware
     * throwing \InvalidArgumentException is a server fault — only
     * bundle-internal conversion exceptions implement
     * ClientInputExceptionInterface.
     */
    private function isClientError(\Throwable $e): bool
    {
        return $e instanceof ClientInputExceptionInterface;
    }

    /**
     * Build a Workerman error response for a throwable caught in the
     * request lifecycle.
     */
    private function buildErrorResponse(bool $clientError): WorkermanResponse
    {
        if ($clientError) {
            return new WorkermanResponse(400, [], 'Bad Request');
        }

        return new WorkermanResponse(500, [], 'Internal Server Error');
    }

    /**
     * Log a throwable caught in the request lifecycle.
     *
     * Client errors are logged at debug level so an attacker cannot flood
     * the log. Server faults are logged at error level with the full
     * exception. When no PSR-3 logger is configured, only server faults
     * fall back to error_log(); client errors are silent.
     */
    private function logThrowable(\Throwable $e, bool $clientError): void
    {
        if ($this->logger instanceof LoggerInterface) {
            if ($clientError) {
                $this->logger->debug('Client request rejected: ' . $e->getMessage(), [
                    'exception' => $e,
                ]);
            } else {
                $this->logger->error('Request lifecycle failed', [
                    'exception' => $e,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        } elseif (!$clientError) {
            error_log(sprintf(
                'Request lifecycle failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }
    }

    /**
     * Handle one Workerman HTTP request through the full lifecycle.
     *
     * The entire pipeline → send → terminate → close → reboot chain is
     * wrapped in a single try/catch so that any throwable — whether from
     * request conversion, a middleware, response conversion, or response
     * preparation — is converted into a 400/500 response instead of
     * escaping into Workerman's TcpConnection error handler, which (in
     * the absence of an installed errorHandler) terminates the worker
     * process. See issue #577.
     *
     * On the failure path, doTerminate() and the reboot check still run,
     * because the kernel may have partially handled the request before
     * the throwable escaped, and services_resetter must be invoked to
     * avoid state leakage into the next request (see issue #572).
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

        $controllerCall = fn(Request $input): Http\Response => ($this->controller)($input, $connection);
        $pipeline = $this->getPipeline();

        try {
            $response = $pipeline($request, $controllerCall);
            $this->sendResponse($connection, $response);
        } catch (\Throwable $e) {
            $clientError = $this->isClientError($e);
            $this->logThrowable($e, $clientError);
            try {
                $this->sendResponse($connection, $this->buildErrorResponse($clientError));
            } catch (\Throwable $sendError) {
                // Best-effort: if even the error-response send fails, log
                // and close. We must not let the throwable escape __invoke()
                // — otherwise doTerminate() and the reboot check (which
                // run after this block) would be skipped, regressing #572,
                // and Workerman's TcpConnection error handler would fire.
                $this->logThrowable($sendError, false);
                $connection->close();
            }
        }

        // Terminate synchronously on both success and failure paths.
        // On the failure path the controller may not have set its
        // request/response, in which case terminateIfNeeded() is a no-op.
        $this->doTerminate();

        // Close connection if protocol demands it.
        if ($this->shouldCloseConnection($request)) {
            $connection->close();
        }

        // Reload if strategy signals — runs on both success and failure paths.
        if ($this->rebootStrategy->shouldReboot()) {
            Utils::reload();
        }
    }
}
