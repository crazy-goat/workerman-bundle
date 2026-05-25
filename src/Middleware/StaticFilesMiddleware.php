<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\Exception\StaticFileMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\Request;
use Workerman\Protocols\Http\Response;

final readonly class StaticFilesMiddleware implements MiddlewareInterface
{
    private const BLOCKED_EXTENSIONS = ['php', 'phar', 'phtml'];

    private const BLOCKED_FILENAMES = [
        '.htaccess',
        '.htpasswd',
        'composer.json',
        'composer.lock',
        'package.json',
    ];

    private string $rootRealPath;
    /** @var string[] */
    private array $allowedExtensions;

    /**
     * @param string[] $allowedExtensions
     */
    public function __construct(string $rootDirectory, array $allowedExtensions = [])
    {
        $resolved = realpath($rootDirectory);
        if ($resolved === false) {
            throw new StaticFileMiddlewareException(
                sprintf('Root directory does not exist: %s', $rootDirectory),
            );
        }
        $this->rootRealPath = $resolved;
        $this->allowedExtensions = array_map(strtolower(...), $allowedExtensions);
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $filePath = $this->getPublicPathFile($request);
        if ($filePath === false || !is_file($filePath)) {
            return $next($request);
        }

        if ($this->isFilePathBlocked($filePath)) {
            return new Response(404);
        }

        return (new Response())->withFile($filePath);
    }

    private function isFilePathBlocked(string $filePath): bool
    {
        $relativePath = substr($filePath, strlen($this->rootRealPath));

        $components = explode('/', ltrim($relativePath, '/'));
        foreach ($components as $component) {
            if ($component !== '' && $this->isComponentBlocked($component)) {
                return true;
            }
        }

        return false;
    }

    private function isComponentBlocked(string $name): bool
    {
        if (str_starts_with($name, '.')) {
            return true;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            return true;
        }

        if (in_array(strtolower($name), self::BLOCKED_FILENAMES, true)) {
            return true;
        }

        return $this->allowedExtensions !== [] && $ext !== '' && !in_array($ext, $this->allowedExtensions, true);
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
