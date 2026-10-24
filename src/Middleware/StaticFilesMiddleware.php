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

    /**
     * Maps a precompressed encoding name to the filename suffix of its
     * pre-compressed variant (`app.js` + `.br`).
     *
     * @var array<string, string>
     */
    private const PRECOMPRESSED_SUFFIXES = [
        'br' => '.br',
        'gzip' => '.gz',
    ];

    private const CACHE_MAX_SIZE = 1024;
    private const CACHE_TTL = 60;
    private const CACHE_NEGATIVE_TTL = 5;
    // DEC-014 plausibility skip: keys larger than this (request paths are data
    // the bundle does not control) are probed on every request and never enter
    // the cache — mirroring Workerman's MAX_CACHE_STRING_LENGTH approach in
    // HeaderNameNormalizer. Servable files are unaffected (fail-open, DEC-013).
    private const CACHE_KEY_MAX_BYTES = 512;

    private string $rootRealPath;
    /** @var string[] */
    private array $allowedExtensions;
    /** @var string[] pre-compressed encodings to serve, in preference order */
    private array $precompressed;


    /**
     * @param string[] $allowedExtensions
     * @param bool     $followSymlinks When false (default), symlinks under the root directory are not followed.
     *                                 Set to true to allow serving files through symlinks.
     * @param string[] $precompressed  Pre-compressed encodings to serve when the client
     *                                 advertises them via `Accept-Encoding`, in preference
     *                                 order (e.g. `['br', 'gzip']`). Unknown names are
     *                                 ignored. Defaults to `[]`, which keeps serving the
     *                                 plain file for every request.
     */
    public function __construct(string $rootDirectory, array $allowedExtensions = [], private bool $followSymlinks = false, array $precompressed = [])
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
        $this->precompressed = [];
        foreach ($precompressed as $encoding) {
            $encoding = strtolower((string) $encoding);
            if (isset(self::PRECOMPRESSED_SUFFIXES[$encoding]) && !in_array($encoding, $this->precompressed, true)) {
                $this->precompressed[] = $encoding;
            }
        }
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if ($this->isAllowlistExtensionMismatch($request->path())) {
            return new Response(404);
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
            return (new Response())->withFile($filePath);
        }

        // Serve a pre-compressed variant (`.br`, `.gz`) when the option is
        // enabled, the client advertises the encoding and the variant file
        // exists next to the plain file. The plain file must exist too — a
        // lone `app.js.gz` is never served.
        $servePath = $filePath;
        $serveMtime = $fileMtime;
        $contentEncoding = null;
        if ($this->precompressed !== []) {
            $variant = $this->selectPrecompressedVariant($request, $filePath);
            if ($variant !== null) {
                $variantMtime = filemtime($variant['path']);
                if ($variantMtime !== false) {
                    $servePath = $variant['path'];
                    $serveMtime = $variantMtime;
                    $contentEncoding = $variant['encoding'];
                }
            }
        }

        $etag = $this->generateEtag($servePath, $serveMtime, $contentEncoding);

        if ($this->isNotModified($request, $etag, $serveMtime)) {
            $notModified = new Response(304);
            if ($this->precompressed !== []) {
                $notModified->header('Vary', 'Accept-Encoding');
            }

            return $notModified;
        }

        $response = (new Response())
            ->withFile($servePath)
            ->header('Last-Modified', $this->formatHttpDate($serveMtime))
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=3600, must-revalidate');

        if ($this->precompressed !== []) {
            // The representation now varies by Accept-Encoding, so caches
            // must key on it.
            $response->header('Vary', 'Accept-Encoding');
        }

        if ($contentEncoding !== null) {
            // Workerman guesses the Content-Type from the extension of the
            // file being sent; for `app.js.br` that would be
            // `application/octet-stream` (plus a `Content-Disposition:
            // attachment`). Serve the type of the original file instead.
            $response->header('Content-Encoding', $contentEncoding);
            $response->header(
                'Content-Type',
                $response->getMimeType(strtolower(pathinfo($filePath, PATHINFO_EXTENSION))),
            );
        }

        return $response;
    }

    /**
     * Pick a pre-compressed variant of a plain file for the request.
     *
     * Encodings are tried in the configured `$precompressed` order against
     * the client's `Accept-Encoding` (an offer with `q=0` does not count).
     * The variant must exist next to the plain file and pass the same block
     * checks as the plain file, so e.g. an allowlisted `app.js` never serves
     * `app.js.gz` (whose final extension is not allowlisted) and `x.php.gz`
     * stays blocked.
     *
     * @return array{path: string, encoding: string}|null
     */
    private function selectPrecompressedVariant(Request $request, string $filePath): ?array
    {
        $acceptEncoding = $request->header('accept-encoding');
        if (!is_string($acceptEncoding) || $acceptEncoding === '') {
            return null;
        }

        $offered = [];
        foreach (explode(',', $acceptEncoding) as $part) {
            $segments = explode(';', $part);
            $token = strtolower(trim($segments[0]));
            if ($token === '') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($segments, 1) as $parameter) {
                $parameter = trim($parameter);
                if (str_starts_with($parameter, 'q=')) {
                    $quality = (float) substr($parameter, 2);
                }
            }
            if ($quality > 0.0) {
                $offered[$token] = true;
            }
        }

        foreach ($this->precompressed as $encoding) {
            if (!isset($offered[$encoding])) {
                continue;
            }
            $variantPath = $filePath . self::PRECOMPRESSED_SUFFIXES[$encoding];
            if (!is_file($variantPath)) {
                continue;
            }
            if ($this->isFilePathBlocked($variantPath)) {
                continue;
            }

            return ['path' => $variantPath, 'encoding' => $encoding];
        }

        return null;
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

    private function generateEtag(string $filePath, int $fileMtime, ?string $contentEncoding = null): string
    {
        // Each pre-compressed variant gets its own ETag (the encoding is
        // part of it), so caches can never mix the plain file with a
        // `.br`/`.gz` variant of it.
        if ($contentEncoding !== null) {
            return sprintf('"%x-%x-%s"', $fileMtime, crc32($filePath), $contentEncoding);
        }

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
        // checks above, and the allowlist below when configured. Directory
        // components never reach this branch: the file/directory distinction
        // is carried by the $isFile flag, so no per-component stat is needed.
        if ($isFile && in_array($ext, self::RESIDUE_EXTENSIONS, true)) {
            return true;
        }

        if (in_array(strtolower($name), self::BLOCKED_FILENAMES, true)) {
            return true;
        }

        // The allowlist applies to files only: directory components have no
        // extension and must never be denied by it (issue #637).
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

    /**
     * Reject an obviously disallowed final-file extension before filesystem work.
     * Paths that are ambiguous or may be directories continue through the normal
     * resolver and component checks (fail-open, DEC-013; FAQ-004).
     */
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

        // DEC-014 plausibility skip: an implausibly long key would occupy a
        // cache slot with a multi-KB string payload. Probe without touching
        // the cache — the file is still served, it just is not cached.
        if (strlen($cacheKey) > self::CACHE_KEY_MAX_BYTES) {
            return $this->probeRealPath($cacheKey);
        }

        $cache = &StaticFilesRealPathCache::all();

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

        return $this->cacheStore($cache, $cacheIndex, $this->probeRealPath($cacheKey), $now);
    }

    /**
     * Resolves the real path of a request path without touching the cache.
     * Returns the absolute path for an existing file, false otherwise
     * (missing file, symlink in the component chain when followSymlinks is
     * off — the middleware never follows symlinks by default).
     */
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
}
