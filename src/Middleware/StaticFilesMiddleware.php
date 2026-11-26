<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\Exception\StaticFileMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\HeadResponse;
use Workerman\Protocols\Http\Response;

final readonly class StaticFilesMiddleware implements MiddlewareInterface
{
    private const LEAK_EXTENSIONS = [
        'php',
        'phar',
        'phtml',
        'phps',
        'inc',
        'sql',
        'log',
        'pem',
        'key',
        'crt',
        'sqlite',
        'sqlite3',
        'db',
    ];

    private const RESIDUE_EXTENSIONS = [
        'bak',
        'orig',
        'rej',
        'save',
        'swp',
        'swo',
        'tmp',
        'old',
        'dist',
    ];

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
    private const CACHE_KEY_MAX_BYTES = 512;

    private string $rootRealPath;
    /** @var string[] */
    private array $allowedExtensions;


    /**
     * @param string[] $allowedExtensions
     */
    public function __construct(string $rootDirectory, array $allowedExtensions = [], private bool $followSymlinks = false)
    {
        if ($this->isPharPath($rootDirectory)) {
            $this->rootRealPath = rtrim($rootDirectory, '\\/');
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
        $method = $request->method();
        $isHead = strcasecmp($method, 'HEAD') === 0;

        if (!$isHead && strcasecmp($method, 'GET') !== 0) {
            return $next($request);
        }

        if ($this->isAllowlistExtensionMismatch($request->path())) {
            // Fail open (DEC-013): the prefilter must never shadow an app
            // route. Only answer 404 when the path resolves to an existing
            // file; a disallowed extension with no file on disk falls
            // through to the application (issue #1118).
            $filePath = $this->getPublicPathFile($request);
            if ($filePath !== false && is_file($filePath)) {
                return new Response(404);
            }

            return $next($request);
        }

        $filePath = $this->getPublicPathFile($request);
        if ($filePath === false || !is_file($filePath)) {
            return $next($request);
        }

        if ($this->isFilePathBlocked($filePath)) {
            return new Response(404);
        }

        $fileMtime = filemtime($filePath);
        if ($fileMtime === false) {
            if ($isHead) {
                return $this->headResponse($filePath, []);
            }

            return (new Response())->withFile($filePath);
        }

        $etag = $this->generateEtag($filePath, $fileMtime);

        if ($this->isNotModified($request, $etag, $fileMtime)) {
            return new Response(304);
        }

        if ($isHead) {
            return $this->headResponse($filePath, [
                'Last-Modified' => $this->formatHttpDate($fileMtime),
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=3600, must-revalidate',
            ]);
        }

        return (new Response())
            ->withFile($filePath)
            ->header('Last-Modified', $this->formatHttpDate($fileMtime))
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=3600, must-revalidate');
    }

    /**
     * @param array<string, string> $headers
     */
    private function headResponse(string $filePath, array $headers): Response
    {
        $size = filesize($filePath);
        if ($size === false) {
            return new Response(404);
        }

        $mime = (new Response())->getMimeType(pathinfo($filePath, PATHINFO_EXTENSION));
        $headers['Content-Type'] = $mime;
        $headers['Accept-Ranges'] = 'bytes';
        if ($mime === 'application/octet-stream') {
            $baseName = (string) preg_replace('/["\\\\\x00-\x1F\x7F]/', '', basename($filePath));
            $headers['Content-Disposition'] = 'attachment; filename="' . ($baseName !== '' ? $baseName : 'unknown') . '"';
        }

        return new HeadResponse(200, $headers, $size);
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
        $relativePath = str_replace('\\', '/', $relativePath);

        $components = explode('/', ltrim($relativePath, '/'));
        $lastIndex = array_key_last($components);
        foreach ($components as $index => $component) {
            if ($component === '') {
                continue;
            }
            if ($this->isComponentBlocked($component, $index === $lastIndex)) {
                return true;
            }
        }

        return false;
    }

    private function isComponentBlocked(string $name, bool $isFile = false): bool
    {
        if (str_starts_with($name, '.')) {
            return true;
        }

        if (str_ends_with($name, '~') || (str_starts_with($name, '#') && str_ends_with($name, '#'))) {
            return true;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $firstDot = strpos($name, '.');
        if ($firstDot !== false) {
            $chain = strtolower(substr($name, $firstDot + 1));
            foreach (explode('.', $chain) as $segment) {
                if (in_array($segment, self::LEAK_EXTENSIONS, true)) {
                    return true;
                }
            }
        }

        if ($isFile && in_array($ext, self::RESIDUE_EXTENSIONS, true)) {
            return true;
        }

        if (in_array(strtolower($name), self::BLOCKED_FILENAMES, true)) {
            return true;
        }

        return $isFile && $this->allowedExtensions !== [] && !in_array($ext, $this->allowedExtensions, true);
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

    private function isAllowlistExtensionMismatch(string $path): bool
    {
        if ($this->allowedExtensions === [] || $path === '' || str_ends_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, '%00')) {
            return false;
        }

        $basename = basename($path);
        if ($basename === '' || str_starts_with($basename, '.')) {
            return false;
        }

        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, self::LEAK_EXTENSIONS, true)
            || in_array($extension, self::RESIDUE_EXTENSIONS, true)
        ) {
            return false;
        }

        return !in_array($extension, $this->allowedExtensions, true);
    }

    private function resolveRealPath(string $cacheKey): string|false
    {
        $now = time();

        if (strlen($cacheKey) > self::CACHE_KEY_MAX_BYTES) {
            return $this->probeRealPath($cacheKey);
        }

        $cache = &StaticFilesRealPathCache::all();

        $cacheIndex = $cacheKey . "\0" . ($this->followSymlinks ? '1' : '0') . "\0" . $this->rootRealPath;

        if (isset($cache[$cacheIndex])) {
            $cached = $cache[$cacheIndex];
            $ttl = $cached['path'] === false ? self::CACHE_NEGATIVE_TTL : self::CACHE_TTL;
            if ($now - $cached['time'] < $ttl) {
                return $this->cacheStore($cache, $cacheIndex, $cached['path'], $cached['time']);
            }
            unset($cache[$cacheIndex]);
        }

        return $this->cacheStore($cache, $cacheIndex, $this->probeRealPath($cacheKey), $now);
    }

    private function probeRealPath(string $cacheKey): string|false
    {
        $path = $this->joinPaths($this->rootRealPath, $cacheKey);

        if ($this->isPharPath($this->rootRealPath)) {
            return file_exists($path) ? $path : false;
        }

        if (!$this->followSymlinks) {
            $checkPath = $this->rootRealPath;
            foreach (explode('/', ltrim($cacheKey, '/')) as $component) {
                if ($component === '' || $component === '.') {
                    continue;
                }
                $checkPath .= DIRECTORY_SEPARATOR . $component;
                if (is_link($checkPath)) {
                    return false;
                }
            }
        }

        return realpath($path);
    }

    /**
     * @param array<string, array{path: string|false, time: int}> $cache
     */
    private function cacheStore(array &$cache, string $index, string|false $path, int $now): string|false
    {
        unset($cache[$index]);
        $cache[$index] = [
            'path' => $path,
            'time' => $now,
        ];

        if (count($cache) > self::CACHE_MAX_SIZE) {
            $oldest = array_key_first($cache);
            if ($oldest !== null) {
                unset($cache[$oldest]);
            }
        }

        return $path;
    }

    private function joinPaths(string $root, string $path): string
    {
        return rtrim($root, '\\/') . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }

    private function isPharPath(string $path): bool
    {
        return str_starts_with($path, 'phar://');
    }
}
