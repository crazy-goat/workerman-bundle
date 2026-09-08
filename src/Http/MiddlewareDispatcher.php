<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

/**
 * Re-entrant, index-based middleware dispatcher.
 *
 * Replaces the nested-closure pipeline composition with a single object
 * that walks the middleware array by index. Created once per request and
 * passed as the {@see $next} callable to every middleware, so the
 * per-request allocation count is **one object** regardless of how many
 * middlewares are registered — not one closure per layer as the old
 * composition did (issue #563).
 *
 * The dispatcher is stateful (it advances {@see $index} on each re-entry)
 * but its state is per-request: it is created inside the cached pipeline
 * closure on every dispatch and discarded when the pipeline returns, so
 * nothing leaks across requests. The handler itself remains stateless.
 *
 * The index is saved and restored around each middleware call via
 * try/finally, so a middleware that calls {@see $next} more than once
 * (not part of the contract, but not forbidden either) dispatches the
 * remaining chain from the same position each time — matching the
 * semantics of the old nested-closure composition.
 *
 * @internal
 */
final class MiddlewareDispatcher
{
    /** @var int<0, max> */
    private int $index = 0;

    /**
     * @param MiddlewareInterface[] $middlewares Middlewares in execution order
     *                                            (first registered = first executed)
     * @param callable              $controller   The innermost controller callable:
     *                                            fn(Request): Http\Response
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly mixed $controller,
    ) {
    }

    /**
     * Dispatch the request through the middleware chain by index.
     *
     * This method is the {@see $next} callable that middlewares receive.
     * Each invocation advances to the next middleware (or the controller
     * when the index exceeds the array), then restores the index on
     * return so re-entrant calls start from the correct position.
     */
    public function __invoke(Request $request): Response
    {
        if ($this->index >= \count($this->middlewares)) {
            return ($this->controller)($request);
        }

        $i = $this->index;
        ++$this->index;
        try {
            return ($this->middlewares[$i])($request, $this);
        } finally {
            $this->index = $i;
        }
    }
}
