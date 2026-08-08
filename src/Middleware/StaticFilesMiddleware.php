<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\Exception\StaticFileMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\Request;
use Workerman\Protocols\Http\Response;

final readonly class StaticFilesMiddleware implements MiddlewareInterface
{
    private const LEAK_EXTENSIONS = [
        // PHP source spellings — the middleware never executes PHP, so
        // blocking these is about source disclosure, not execution. A leak
        // signal wherever it appears in a compound suffix (`x.php.bak`,
        // `x.phar.gz`), for directories and files alike.
        'php',
        'phar',
        'phtml',
        'phps',
        'inc',
        // Credentials, dumps and logs — also leak signals in any position.
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
        // Editor backup and deploy residue — vim/emacs backups, conflicted
        // merges, interrupted saves. These appear in production directories
        // without anyone intending them to, and often contain the source
        // (and credentials) of the file they back up. A leak signal only as
        // the *final* extension of a *file*: a directory named `assets.dist`
        // or `backup.bak` is legitimate and must not deny its contents.
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

    private string $rootRealPath;
    /** @var string[] */
    private array $allowedExtensions;


    /**
     * @param string[] $allowedExtensions
     * @param bool     $followSymlinks When false (default), symlinks under the root directory are not followed.
     *                                 Set to true to allow serving files through symlinks.
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
        $relativePath = str_replace('\\', '/', $relativePath);

        $components = explode('/', ltrim($relativePath, '/'));
        $componentPath = $this->rootRealPath;
        foreach ($components as $component) {
            if ($component === '') {
                continue;
            }
            $componentPath .= DIRECTORY_SEPARATOR . $component;
            if ($this->isComponentBlocked($component, $componentPath)) {
                return true;
            }
        }

        return false;
    }

    private function isComponentBlocked(string $name, ?string $componentPath = null): bool
    {
        if (str_starts_with($name, '.')) {
            return true;
        }

        // Editor backup residue: `index.php~` (vim/emacs) and
        // `#index.php#` (emacs autosave) bypass the extension checks below.
        if (str_ends_with($name, '~') || (str_starts_with($name, '#') && str_ends_with($name, '#'))) {
            return true;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Leak extensions are blocked wherever they appear in the suffix
        // chain (after the *first* dot), so a blocked extension is caught
        // in every position of a compound name (`x.php.bak`, `x.php.txt`,
        // `x.phar.gz`) — `pathinfo()` alone only sees the segment after the
        // last dot.
        $firstDot = strpos($name, '.');
        if ($firstDot !== false) {
            $chain = strtolower(substr($name, $firstDot + 1));
            foreach (explode('.', $chain) as $segment) {
                if (in_array($segment, self::LEAK_EXTENSIONS, true)) {
                    return true;
                }
            }
        }

        // Residue suffixes are a leak signal only as the final extension of
        // a file. An interior or directory occurrence (`app.dist.js`,
        // `assets.dist/`) is not — its contents are still protected by the
        // checks above, and the allowlist below when configured. The
        // directory stat is only paid when the final extension is a residue
        // candidate, keeping the common asset path stat-free.
        if (in_array($ext, self::RESIDUE_EXTENSIONS, true)
            && ($componentPath === null || !is_dir($componentPath))
        ) {
            return true;
        }

        if (in_array(strtolower($name), self::BLOCKED_FILENAMES, true)) {
            return true;
        }

        return $this->allowedExtensions !== [] && !in_array($ext, $this->allowedExtensions, true);
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
                // Refresh hit preserves the original timestamp: TTL stays fixed, only LRU position moves.
                return $this->cacheStore($cache, $cacheIndex, $cached['path'], $cached['time']);
            }
            unset($cache[$cacheIndex]);
        }

        $path = $this->joinPaths($this->rootRealPath, $cacheKey);

        if ($this->isPharPath($this->rootRealPath)) {
            $resolved = file_exists($path) ? $path : false;
        } else {
            if (!$this->followSymlinks) {
                $checkPath = $this->rootRealPath;
                foreach (explode('/', ltrim($cacheKey, '/')) as $component) {
                    if ($component === '' || $component === '.') {
                        continue;
                    }
                    $checkPath .= DIRECTORY_SEPARATOR . $component;
                    if (is_link($checkPath)) {
                        return $this->cacheStore($cache, $cacheIndex, false, $now);
                    }
                }
            }

            $resolved = realpath($path);
        }

        return $this->cacheStore($cache, $cacheIndex, $resolved, $now);
    }

    /**
     * Inserts an entry into the realpath cache, enforcing CACHE_MAX_SIZE on every insert.
     *
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

    /**
     * @return array<string, array{path: string|false, time: int}>
     */
    private function &getRealPathCache(): array
    {
        static $cache = [];

        return $cache;
    }
}
