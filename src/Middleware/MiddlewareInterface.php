<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

interface MiddlewareInterface
{
    public function __invoke(Request $request, callable $next): Response;
}
