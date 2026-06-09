<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\Http\Request;
use Workerman\Protocols\Http\Response;

/**
 * Middleware for the HTTP request/response pipeline.
 *
 * Implementations are composed into a chain via
 * MiddlewareDispatchInterface::withMiddlewares() and executed in FIFO order
 * (first added = first executed). Each middleware receives the incoming
 * Request and a callable $next that dispatches to the next middleware in
 * the chain (or to the Symfony controller for the innermost layer).
 *
 * A middleware can:
 *  - Pass the request to $next and return its Response (possibly modified).
 *  - Short-circuit the pipeline by returning a Response without calling $next.
 *  - Set $connection->context->responseSentDirectly = true to skip the
 *    automatic response send step (used by StaticFilesMiddleware for
 *    streaming large files).
 *
 * The middleware pipeline is built ONCE and cached across requests, so
 * middleware instances are reused. Per-request state should be stored on
 * $connection->context, not on the middleware instance itself.
 *
 * Lifecycle: per-request. The __invoke method is called for every incoming
 * HTTP request after the pipeline has been composed.
 *
 * @see MiddlewareDispatchInterface::withMiddlewares() for adding middlewares.
 * @see StaticFilesMiddleware for an example implementation.
 * @see SymfonyController for the innermost controller middleware.
 */
interface MiddlewareInterface
{
    /**
     * Handle the incoming request.
     *
     * Called for each HTTP request during pipeline dispatch. Implementations
     * should either:
     *
     * 1. Forward the request by calling $next($request) and returning its
     *    Response (possibly after modifying it).
     * 2. Return a Response directly, short-circuiting the rest of the chain.
     * 3. Throw an exception (caught by the error handling layer).
     *
     * @param Request  $request The incoming Workerman HTTP request. The
     *                          bundle's extended Request class adds
     *                          setHeader()/withHeader() for header manipulation.
     * @param callable $next    The next middleware in the pipeline. Signature:
     *                          fn(Request $request): Http\Response. The
     *                          innermost $next delegates to SymfonyController.
     *
     * @return Response The HTTP response to send back to the client.
     */
    public function __invoke(Request $request, callable $next): Response;
}
