<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\Exception\StaticFileMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\Request;
use Workerman\Protocols\Http\Response;

final readonly class StaticFilesMiddleware implements MiddlewareInterface
{
    private string $rootRealPath;

    public function __construct(string $rootDirectory)
    {
        $resolved = realpath($rootDirectory);
        if ($resolved === false) {
            throw new StaticFileMiddlewareException(
                sprintf('Root directory does not exist: %s', $rootDirectory),
            );
        }
        $this->rootRealPath = $resolved;
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
        $path = $request->path();

        if (str_contains($path, "\0") || str_contains($path, '%00') || str_contains($path, '\\')) {
            return false;
        }

        $resolved = realpath($this->rootRealPath . DIRECTORY_SEPARATOR . ltrim($path, '/'));

        if ($resolved === false) {
            return false;
        }

        if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $this->rootRealPath . DIRECTORY_SEPARATOR)) {
            return false;
        }

        return $resolved;
    }
}
