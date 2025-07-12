<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\App;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

class TestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $headerName, private readonly string $headerValue)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $request->withHeader($this->headerName, $this->headerValue);
        $request->withHeader('X-Test-Middleware-request-order', $request->header('X-Test-Middleware-request-order') . $this->headerName . '|');
        $response = $next($request);
        $response->header($this->headerName, $this->headerValue);
        return $response;
    }
}
