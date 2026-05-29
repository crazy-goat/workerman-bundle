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

    private const CACHE_MAX_SIZE = 1024;
    private const CACHE_TTL = 60;
    private const CACHE_NEGATIVE_TTL = 5;

    private string $rootRealPath;
    /** @var string[] */
    private array $allowedExtensions;


    /**
     * @param string[] $allowedExtensions
     */
    public function __construct(string $rootDirectory, array $allowedExtensions = [], private bool $followSymlinks = false)
    {
        if ($this->isPharPath($rootDirectory)) {
            $this->rootRealPath = $rootDirectory;
        } else {
            $resolved = realpath($rootDirectory);
            if ($resolved === false) {
                throw new StaticFileMiddlewareException(
                    sprintf('Root directory does not exist: %s', $rootDirectory),
                );
            }
            $this->rootRealPath = $resolved;
        }
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

        $fileMtime = filemtime($filePath);
        if ($fileMtime === false) {
            return (new Response())->withFile($filePath);
        }

        $etag = $this->generateEtag($filePath, $fileMtime);

        if ($this->isNotModified($request, $etag, $fileMtime)) {
            return new Response(304);
        }

        return (new Response())
            ->withFile($filePath)
            ->header('Last-Modified', $this->formatHttpDate($fileMtime))
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=3600, must-revalidate');
    }

    private function isNotModified(Request $request, string $etag, int $fileMtime): bool
    {
        $ifNoneMatch = $request->header('if-none-match');
        if (is_string($ifNoneMatch) && $ifNoneMatch !== '') {
            $trimmed = trim($ifNoneMatch);
            if ($trimmed === '*') {
                return true;
            }

            $stripped = trim($etag, '"');
            $matchValues = explode(',', $trimmed);
            foreach ($matchValues as $value) {
                if (trim($value, '" ') === $stripped) {
                    return true;
                }
            }
        }

        $ifModifiedSince = $request->header('if-modified-since');
        if (is_string($ifModifiedSince) && $ifModifiedSince !== '') {
            $ifModifiedSinceTime = strtotime($ifModifiedSince);
            if ($ifModifiedSinceTime !== false && $ifModifiedSinceTime >= $fileMtime) {
                return true;
            }
        }

        return false;
    }

    private function generateEtag(string $filePath, int $fileMtime): string
    {
        return sprintf('"%x-%x"', $fileMtime, crc32($filePath));
    }

    private function formatHttpDate(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
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

        $resolved = $this->resolveRealPath($path);

        if ($resolved === false) {
            return false;
        }

        if (!str_starts_with($resolved . DIRECTORY_SEPARATOR, $this->rootRealPath . DIRECTORY_SEPARATOR)) {
            return false;
        }

        return $resolved;
    }

    private function resolveRealPath(string $cacheKey): string|false
    {
        $now = time();

        $cache = &$this->getRealPathCache();

        $cacheIndex = $cacheKey . "\0" . ($this->followSymlinks ? '1' : '0') . "\0" . $this->rootRealPath;

        if (isset($cache[$cacheIndex])) {
            $cached = $cache[$cacheIndex];
            $ttl = $cached['path'] === false ? self::CACHE_NEGATIVE_TTL : self::CACHE_TTL;
            if ($now - $cached['time'] < $ttl) {
                unset($cache[$cacheIndex]);
                $cache[$cacheIndex] = $cached;

                return $cached['path'];
            }
            unset($cache[$cacheIndex]);
        }

        if ($this->isPharPath($this->rootRealPath)) {
            $resolved = $this->rootRealPath . DIRECTORY_SEPARATOR . ltrim($cacheKey, '/');
            if (!file_exists($resolved)) {
                $resolved = false;
            }
        } else {
            $path = $this->rootRealPath . DIRECTORY_SEPARATOR . ltrim($cacheKey, '/');

            if (!$this->followSymlinks) {
                $components = explode('/', ltrim($cacheKey, '/'));
                $checkPath = $this->rootRealPath;
                foreach ($components as $component) {
                    if ($component === '' || $component === '.') {
                        continue;
                    }
                    $checkPath .= DIRECTORY_SEPARATOR . $component;
                    if (is_link($checkPath)) {
                        $cache[$cacheIndex] = [
                            'path' => false,
                            'time' => $now,
                        ];

                        return false;
                    }
                }
            }

            $resolved = realpath($path);
        }

        $cache[$cacheIndex] = [
            'path' => $resolved,
            'time' => $now,
        ];

        if (count($cache) > self::CACHE_MAX_SIZE) {
            array_shift($cache);
        }

        return $resolved;
    }

    private function isPharPath(string $path): bool
    {
        return str_starts_with($path, 'phar://');
    }

    /**
     * @return array<string, array{path: string|false, time: int}>
     */
    private function &getRealPathCache(): array
    {
        static $cache = [];

        return $cache;
    }
}
