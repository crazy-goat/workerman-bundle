<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;

interface MiddlewareDispatchInterface
{
    public function withMiddlewares(MiddlewareInterface ...$middlewares): self;
}
