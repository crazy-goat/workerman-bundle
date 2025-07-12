<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class StaticFilesMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $rootDirectory)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if (is_file($file = $this->getPublicPathFile($request))) {
            return (new Response())->withFile($file);
        }

        return $next($request);
    }

    private function getPublicPathFile(Request $request): string
    {
        return str_replace(
            '..',
            '/',
            "{$this->rootDirectory}{$request->path()}",
        );
    }
}
