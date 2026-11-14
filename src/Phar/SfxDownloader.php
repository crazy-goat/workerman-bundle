<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

use CrazyGoat\WorkermanBundle\Exception\SfxExtractionException;

/**
 * @internal
 */
final readonly class SfxDownloader
{
    private const DOWNLOAD_CHUNK = 1 << 16;

    private const DEFAULT_MAX_DOWNLOAD_BYTES = 256 * 1024 * 1024;

    public function __construct(private int $maxDownloadBytes = self::DEFAULT_MAX_DOWNLOAD_BYTES)
    {
        if ($maxDownloadBytes <= 0) {
            throw new \InvalidArgumentException('maxDownloadBytes must be a positive number of bytes.');
        }
    }

    /**
     * @throws SfxExtractionException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    public function fetch(
        string $url,
        string $destinationDir,
        ?string $expectedSha256 = null,
        bool $allowInsecure = false,
    ): string {
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException(sprintf('Unable to create destination directory "%s".', $destinationDir));
        }

        $filename = self::filenameFromUrl($url);
        $destination = rtrim($destinationDir, '/') . '/' . $filename;

        if (!is_file($destination) && !is_writable($destinationDir)) {
            throw $this->notWritable($destinationDir, 'downloading');
        }

        if (!is_file($destination)) {
            $this->downloadTo($url, $destination, $allowInsecure);
        }

        if (is_string($expectedSha256) && $expectedSha256 !== '') {
            try {
                self::verifyChecksum($destination, $expectedSha256);
            } catch (\RuntimeException $e) {
                $removed = false;
                if (is_file($destination)) {
                    $removed = @unlink($destination);
                }
                $message = $e->getMessage();
                if ($removed) {
                    $message .= ' The failed artifact was removed, so the next fetch() will re-download it.';
                }
                throw new \RuntimeException($message, $e->getCode(), $e);
            }
        }

        if (str_ends_with($destination, '.zip')) {
            $this->assertExtractDestinationWritable($destination, $destinationDir);

            try {
                $destination = $this->extractZip($destination, $destinationDir);
            } catch (\Throwable $e) {
                if (is_file($destination)) {
                    $removed = @unlink($destination);
                    if (!$removed) {
                        error_log(sprintf(
                            'Unable to remove failed SFX archive "%s"; the bad artifact stays on disk and every subsequent fetch() will fail on it. Remove it manually.',
                            $destination,
                        ));
                    }
                }
                throw $e;
            }
        }

        if (!is_file($destination)) {
            throw new \RuntimeException(sprintf('Failed to obtain phpmicro.sfx (resolved path "%s" does not exist).', $destination));
        }

        return $destination;
    }

    public static function filenameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = basename($path);

        return $basename !== '' ? $basename : 'phpmicro.sfx';
    }

    public static function verifyChecksum(string $path, string $expectedSha256): void
    {
        $actual = hash_file('sha256', $path);
        if ($actual === false) {
            throw new \RuntimeException(sprintf('Failed to hash "%s".', $path));
        }

        $expected = strtolower(trim($expectedSha256));
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                'SHA-256 mismatch for "%s": expected %s, got %s.',
                $path,
                $expected,
                $actual,
            ));
        }
    }

    private function downloadTo(string $url, string $destination, bool $allowInsecure): void
    {
        $this->downloadWithRedirectCheck($url, $destination, $allowInsecure);
    }

    private function downloadWithRedirectCheck(string $url, string $destination, bool $allowInsecure): void
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $context = $this->buildContext($currentUrl, $allowInsecure);

            $http_response_header = [];
            $in = @fopen($currentUrl, 'rb', false, $context);
            if (!is_resource($in)) {
                $err = error_get_last()['message'] ?? 'unknown error';
                throw new \RuntimeException(sprintf('Failed to open "%s" for download: %s', $currentUrl, $err));
            }

            $responseHeaders = $http_response_header;

            $httpCode = 0;
            if (isset($responseHeaders[0]) && preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $responseHeaders[0], $m)) {
                $httpCode = (int) $m[1];
            }

            if ($httpCode < 300 || $httpCode >= 400) {
                $this->writeStream($in, $destination);

                return;
            }

            fclose($in);

            $location = null;
            foreach ($responseHeaders as $header) {
                if (str_starts_with(strtolower($header), 'location:')) {
                    $location = trim(substr($header, 9));
                    break;
                }
            }

            if ($location === null) {
                throw new \RuntimeException(sprintf('Redirect response (%d) without Location header from "%s".', $httpCode, $currentUrl));
            }

            $location = $this->resolveRedirectUrl($currentUrl, $location);

            $this->assertAllowedRedirect($currentUrl, $location);

            $currentUrl = $location;
        }

        throw new \RuntimeException(sprintf('Too many redirects (max %d) for "%s".', $maxRedirects, $url));
    }

    /**
     * @throws \RuntimeException
     */
    private function assertAllowedRedirect(string $currentUrl, string $location): void
    {
        if (!preg_match('#^https?://#i', $location)) {
            throw new \RuntimeException(sprintf(
                'Blocked redirect to non-HTTP(S) scheme "%s". Disable redirects or use a trusted mirror.',
                $location,
            ));
        }

        if (str_starts_with(strtolower($currentUrl), 'https://') && str_starts_with(strtolower($location), 'http://')) {
            throw new \RuntimeException(sprintf(
                'Blocked cross-scheme redirect from HTTPS to "%s". Disable redirects or use a trusted mirror.',
                $location,
            ));
        }
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $location)) {
            throw new \RuntimeException(sprintf(
                'Blocked redirect to non-HTTP(S) scheme "%s". Disable redirects or use a trusted mirror.',
                $location,
            ));
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException(sprintf(
                'Unable to resolve redirect location "%s" against base URL "%s".',
                $location,
                $base,
            ));
        }

        $scheme = strtolower($parts['scheme']);

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if ($location === '' || $location[0] !== '/') {
            $basePath = isset($parts['path']) ? dirname($parts['path']) : '/';

            return $scheme . '://' . $host . $port . rtrim($basePath, '/') . '/' . $location;
        }

        return $scheme . '://' . $host . $port . $location;
    }

    private function writeStream(mixed $in, string $destination): void
    {
        $out = @fopen($destination, 'wb');
        if (!is_resource($out)) {
            fclose($in);
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing: %s', $destination, $err));
        }

        $totalBytes = 0;
        $failed = false;
        try {
            while (!feof($in)) {
                $chunk = fread($in, self::DOWNLOAD_CHUNK);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed to read from stream.');
                }
                if ($chunk === '') {
                    continue;
                }

                $totalBytes += strlen($chunk);
                if ($totalBytes > $this->maxDownloadBytes) {
                    throw new \RuntimeException(sprintf(
                        'Download exceeds the maximum allowed size of %d bytes.',
                        $this->maxDownloadBytes,
                    ));
                }

                $written = fwrite($out, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new \RuntimeException(sprintf(
                        'Failed to write to "%s" (short write).',
                        $destination,
                    ));
                }
            }
        } catch (\Throwable $e) {
            $failed = true;
            throw $e;
        } finally {
            fclose($in);
            fclose($out);

            if ($failed && is_file($destination)) {
                $removed = @unlink($destination);
                if (!$removed) {
                    error_log(sprintf(
                        'Unable to remove partial SFX download "%s"; the truncated file stays on disk and the next fetch() will trust it as a complete download. Remove it manually.',
                        $destination,
                    ));
                }
            }
        }
    }

    /**
     * @return resource|null
     */
    private function buildContext(string $url, bool $allowInsecure)
    {
        $lower = strtolower($url);

        if (str_starts_with($lower, 'http://')) {
            return stream_context_create([
                'http' => [
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'timeout' => 60,
                ],
            ]);
        }

        if (!str_starts_with($lower, 'https://')) {
            return null;
        }

        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('The openssl extension is required to download phpmicro.sfx over HTTPS.');
        }

        $sslOptions = [
            'verify_peer' => !$allowInsecure,
            'verify_peer_name' => !$allowInsecure,
        ];

        return stream_context_create([
            'ssl' => $sslOptions,
            'http' => [
                'follow_location' => 0,
                'max_redirects' => 0,
                'timeout' => 60,
            ],
        ]);
    }

    /**
     * @throws \RuntimeException
     */
    private function assertExtractDestinationWritable(string $zipPath, string $destinationDir): void
    {
        if (is_writable($destinationDir)) {
            return;
        }

        try {
            $zip = $this->openArchive($zipPath);
        } catch (\RuntimeException) {
            return;
        }

        try {
            $base = rtrim($destinationDir, '/') . '/';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '') {
                    return;
                }

                $target = $base . $name;
                $inPlace = str_ends_with($name, '/')
                    ? is_dir($target)
                    : is_file($target) && is_writable($target);
                if (!$inPlace) {
                    throw $this->notWritable($destinationDir, 'extracting');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function notWritable(string $destinationDir, string $step): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'Destination directory "%s" is not writable; check its permissions before %s.',
            $destinationDir,
            $step,
        ));
    }

    /**
     * @throws SfxExtractionException
     * @throws \RuntimeException
     * @throws \Throwable
     */
    private function extractZip(string $zipPath, string $destinationDir): string
    {
        $zip = $this->openArchive($zipPath);

        try {
            $entryNames = $this->listEntryNames($zip);
            $extractedEntries = $this->extractToDirectory($zip, $zipPath, $destinationDir);
        } finally {
            $zip->close();
        }

        try {
            return $this->locateSfxEntry($entryNames, $zipPath, $destinationDir);
        } catch (SfxExtractionException $e) {
            $this->removeExtractedEntries($extractedEntries, $destinationDir);
            throw $e;
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function openArchive(string $zipPath): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The zip extension is required to extract the downloaded SFX archive.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException(sprintf('Failed to open zip archive "%s".', $zipPath));
        }

        return $zip;
    }

    /**
     * @return string[]
     * @throws \RuntimeException
     */
    private function listEntryNames(\ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && $name !== '') {
                $this->validateEntryName($name);
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return string[]
     * @throws \RuntimeException
     * @throws \Throwable
     */
    private function extractToDirectory(\ZipArchive $zip, string $zipPath, string $destinationDir): array
    {
        $resolvedDestination = realpath($destinationDir);
        if ($resolvedDestination === false) {
            throw new \RuntimeException(sprintf('Unable to resolve destination directory "%s".', $destinationDir));
        }
        $destinationBase = rtrim($resolvedDestination, '/\\');

        $extractedEntries = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '') {
                    throw new \RuntimeException(sprintf(
                        'Zip archive "%s" contains an entry with an unreadable name (index %d); refusing to extract it.',
                        $zipPath,
                        $i,
                    ));
                }

                $this->validateEntryName($name);

                $this->assertEntryContainedIn($destinationBase, $name, $zipPath);

                if (!@$zip->extractTo($destinationDir, $name)) {
                    $error = error_get_last()['message'] ?? 'unknown error';
                    throw new \RuntimeException(sprintf(
                        'Failed to extract entry "%s" from zip archive "%s": %s',
                        $name,
                        $zipPath,
                        $error,
                    ));
                }

                $extractedEntries[] = $name;
            }
        } catch (\Throwable $e) {
            $this->removeExtractedEntries($extractedEntries, $destinationDir);
            throw $e;
        }

        return $extractedEntries;
    }

    /**
     * @param string[] $extractedEntries
     */
    private function removeExtractedEntries(array $extractedEntries, string $destinationDir): void
    {
        $base = rtrim($destinationDir, '/\\');

        $parents = [];
        foreach ($extractedEntries as $name) {
            $dir = ltrim(dirname($name), '/');
            while ($dir !== '' && $dir !== '.') {
                $parents[$dir] = true;
                $dir = dirname($dir);
                if ($dir === '.' || $dir === '/') {
                    break;
                }
            }
        }

        $allPaths = array_merge($extractedEntries, array_keys($parents));
        usort(
            $allPaths,
            static fn(string $a, string $b): int =>
            substr_count(rtrim($b, '/'), '/') <=> substr_count(rtrim($a, '/'), '/'),
        );

        foreach ($allPaths as $name) {
            $path = $base . DIRECTORY_SEPARATOR . rtrim($name, '/');

            if (is_link($path)) {
                if (!@unlink($path)) {
                    error_log(sprintf(
                        'Unable to remove extracted symlink "%s" during cleanup; the entry stays on disk. Remove it manually.',
                        $path,
                    ));
                }
            } elseif (is_dir($path)) {
                if (!@rmdir($path)) {
                    $items = @scandir($path);
                    if ($items !== false && count($items) <= 2) {
                        error_log(sprintf(
                            'Unable to remove extracted directory "%s" during cleanup; the directory stays on disk. Remove it manually.',
                            $path,
                        ));
                    }
                }
            } elseif (is_file($path)) {
                if (!@unlink($path)) {
                    error_log(sprintf(
                        'Unable to remove extracted file "%s" during cleanup; the entry stays on disk and may be trusted by a later fetch(). Remove it manually.',
                        $path,
                    ));
                }
            }
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function assertEntryContainedIn(string $destinationBase, string $entryName, string $zipPath): void
    {
        $target = $destinationBase . DIRECTORY_SEPARATOR . rtrim($entryName, '/');
        $ancestor = $target;

        do {
            $resolved = realpath($ancestor);
            if ($resolved !== false) {
                $ancestor = $resolved;
                break;
            }
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        } while (true);

        if (!str_starts_with($ancestor . DIRECTORY_SEPARATOR, $destinationBase . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" in archive "%s" resolves outside the destination directory "%s" and is rejected.',
                $entryName,
                $zipPath,
                $destinationBase,
            ));
        }
    }

    /**
     * @param string[] $entryNames
     * @throws SfxExtractionException
     */
    private function locateSfxEntry(array $entryNames, string $zipPath, string $destinationDir): string
    {
        $basename = basename($zipPath);
        $expectedName = str_ends_with($basename, '.zip') ? substr($basename, 0, -4) : $basename;
        $expected = rtrim($destinationDir, '/') . '/' . $expectedName;
        if (is_file($expected)) {
            return $expected;
        }

        foreach ($entryNames as $name) {
            $candidate = rtrim($destinationDir, '/') . '/' . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new SfxExtractionException(sprintf(
            'Could not locate extracted SFX file in "%s". Archive entries: ["%s"]',
            $destinationDir,
            implode('", "', $entryNames),
        ));
    }

    /**
     * @throws \RuntimeException
     */
    private function validateEntryName(string $entryName): void
    {
        if (str_contains($entryName, '\\')) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" contains backslashes and is rejected.',
                $entryName,
            ));
        }

        if (str_starts_with($entryName, '/')) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" is an absolute path and is rejected.',
                $entryName,
            ));
        }

        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $entryName) === 1) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" contains a drive letter and is rejected.',
                $entryName,
            ));
        }

        $normalized = $this->normalizePath($entryName);
        if (in_array('..', explode('/', $normalized), true)) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" contains path traversal segments and is rejected.',
                $entryName,
            ));
        }
    }

    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $filtered = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            $filtered[] = $part;
        }

        return implode('/', $filtered);
    }
}
