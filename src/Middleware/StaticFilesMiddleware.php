<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\Http\Request;
use Workerman\Protocols\Http\Response;

class StaticFilesMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $rootDirectory)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $filePath = $this->getPublicPathFile($request);
        if ($filePath === false || !is_file($filePath)) {
            return $next($request);
        }

        return (new Response())->withFile($filePath);
    }

    private function getPublicPathFile(Request $request): string|false
    {
        $resolved = realpath($this->rootDirectory . $request->path());
        $rootRealPath = realpath($this->rootDirectory);
        
        if ($resolved === false || $rootRealPath === false) {
            return false;
        }
        
        if (!str_starts_with($resolved, $rootRealPath . DIRECTORY_SEPARATOR)) {
            return false;
        }
        
        return $resolved;
    }
}
