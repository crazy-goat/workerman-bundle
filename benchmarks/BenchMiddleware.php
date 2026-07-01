<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Benchmark;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response as WorkermanResponse;

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
