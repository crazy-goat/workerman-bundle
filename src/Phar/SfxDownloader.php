<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

use CrazyGoat\WorkermanBundle\Exception\SfxExtractionException;

/**
 * Downloads phpmicro.sfx (the static PHP runtime used to build standalone
 * binaries) from an HTTPS mirror, optionally verifying a SHA-256 digest.
 *
 * @internal
 */
final class SfxDownloader
{
    private const DOWNLOAD_CHUNK = 1 << 16;

    /**
     * Resolve an existing SFX file or download one.
     *
     * @return string Path to the resolved SFX file on disk
     *
     * @throws SfxExtractionException when the extracted zip contains no usable SFX entry
     * @throws \RuntimeException on any other download/extract/verification failure
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

        if (!is_file($destination)) {
            $this->downloadTo($url, $destination, $allowInsecure);
        }

        // If the upstream artifact is a zip, extract it.
        if (str_ends_with($destination, '.zip')) {
            $destination = $this->extractZip($destination, $destinationDir);
        }

        if (!is_file($destination)) {
            throw new \RuntimeException(sprintf('Failed to obtain phpmicro.sfx (resolved path "%s" does not exist).', $destination));
        }

        if (is_string($expectedSha256) && $expectedSha256 !== '') {
            self::verifyChecksum($destination, $expectedSha256);
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
        if ($allowInsecure) {
            $this->downloadWithRedirectCheck($url, $destination, $allowInsecure);

            return;
        }

        $context = $this->buildContext($url, $allowInsecure);
        $this->downloadStream($url, $destination, $context);
    }

    private function downloadWithRedirectCheck(string $url, string $destination, bool $allowInsecure): void
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $context = $this->buildContext($currentUrl, $allowInsecure);

            $in = @fopen($currentUrl, 'rb', false, $context);
            if (!is_resource($in)) {
                $err = error_get_last()['message'] ?? 'unknown error';
                throw new \RuntimeException(sprintf('Failed to open "%s" for download: %s', $currentUrl, $err));
            }

            $responseHeaders = $this->getHttpResponseHeaders(get_defined_vars());

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

            if (str_starts_with($currentUrl, 'https://') && str_starts_with(strtolower($location), 'http://')) {
                throw new \RuntimeException(sprintf(
                    'Blocked cross-scheme redirect from HTTPS to "%s". Disable redirects or use a trusted mirror.',
                    $location,
                ));
            }

            $currentUrl = $location;
        }

        throw new \RuntimeException(sprintf('Too many redirects (max %d) for "%s".', $maxRedirects, $url));
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if ($location === '' || $location[0] !== '/') {
            $basePath = isset($parts['path']) ? dirname($parts['path']) : '/';

            return $scheme . '://' . $host . $port . rtrim($basePath, '/') . '/' . $location;
        }

        return $scheme . '://' . $host . $port . $location;
    }

    private function downloadStream(string $url, string $destination, mixed $context): void
    {
        $in = @fopen($url, 'rb', false, $context);
        if (!is_resource($in)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException(sprintf('Failed to open "%s" for download: %s', $url, $err));
        }

        $this->writeStream($in, $destination);
    }

    private function writeStream(mixed $in, string $destination): void
    {
        $out = fopen($destination, 'wb');
        if (!is_resource($out)) {
            fclose($in);
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $destination));
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, self::DOWNLOAD_CHUNK);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed to read from stream.');
                }
                if ($chunk === '') {
                    continue;
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * @return resource|null
     */
    private function buildContext(string $url, bool $allowInsecure)
    {
        if (!str_starts_with($url, 'https://')) {
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
                'follow_location' => $allowInsecure ? 0 : 1,
                'max_redirects' => $allowInsecure ? 0 : 5,
                'timeout' => 60,
            ],
        ]);
    }

    /**
     * Extract a phpmicro.sfx zip archive.
     *
     * Stages:
     *  1. Open the zip archive with integrity checks.
     *  2. List all entry names (validating each against zip-slip).
     *  3. Extract all entries to the destination directory.
     *  4. Locate the SFX entry:
     *     a. Try the entry whose basename matches the zip filename (minus .zip).
     *     b. Fall back to the first regular file entry from the archive.
     *
     * @throws SfxExtractionException when no suitable SFX entry is found
     * @throws \RuntimeException on archive open, validation, or extraction failures
     */
    private function extractZip(string $zipPath, string $destinationDir): string
    {
        $zip = $this->openArchive($zipPath);

        try {
            $entryNames = $this->listEntryNames($zip);
            $this->extractToDirectory($zip, $zipPath, $destinationDir);
        } finally {
            $zip->close();
        }

        return $this->locateSfxEntry($entryNames, $zipPath, $destinationDir);
    }

    /**
     * Open a zip archive with integrity checks.
     *
     * @return \ZipArchive The opened archive (caller must close)
     *
     * @throws \RuntimeException if the zip extension is missing or the archive cannot be opened
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
     * List all entry names in a zip archive, validating each against zip-slip attacks.
     *
     * @return string[] Non-empty entry names
     *
     * @throws \RuntimeException if any entry name fails validation
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
     * Extract all zip entries to the destination directory.
     *
     * @throws \RuntimeException if extraction fails
     */
    private function extractToDirectory(\ZipArchive $zip, string $zipPath, string $destinationDir): void
    {
        if (!$zip->extractTo($destinationDir)) {
            throw new \RuntimeException(sprintf('Failed to extract zip archive "%s" to "%s".', $zipPath, $destinationDir));
        }
    }

    /**
     * Locate the extracted SFX entry on disk.
     *
     * Detection rules:
     *  1. Try the entry whose basename matches the zip filename (minus .zip extension),
     *     resolved under the destination directory.
     *  2. Fall back to the first regular file entry from the archive
     *     that exists on disk after extraction.
     *
     * @param string[] $entryNames Validated entry names from the archive
     *
     * @throws SfxExtractionException when no suitable SFX entry can be found
     */
    private function locateSfxEntry(array $entryNames, string $zipPath, string $destinationDir): string
    {
        $expected = rtrim($destinationDir, '/') . '/' . str_replace('.zip', '', basename($zipPath));
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
     * Validate a zip entry name against zip-slip path traversal attacks.
     *
     * @throws \RuntimeException if the entry name is invalid
     */
    private function validateEntryName(string $entryName): void
    {
        // Reject entries containing backslashes (Windows path separators).
        if (str_contains($entryName, '\\')) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" contains backslashes and is rejected.',
                $entryName,
            ));
        }

        // Reject absolute paths (starting with / or a drive letter).
        if (str_starts_with($entryName, '/')) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" is an absolute path and is rejected.',
                $entryName,
            ));
        }

        // Reject Windows drive letters (e.g., C:\).
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $entryName) === 1) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" contains a drive letter and is rejected.',
                $entryName,
            ));
        }

        // Normalize and check for path traversal (.. segments).
        $normalized = $this->normalizePath($entryName);
        if (in_array('..', explode('/', $normalized), true)) {
            throw new \RuntimeException(sprintf(
                'Zip entry "%s" contains path traversal segments and is rejected.',
                $entryName,
            ));
        }
    }

    /**
     * @param array<string, mixed> $definedVars
     *
     * @return list<string>
     */
    private function getHttpResponseHeaders(array $definedVars): array
    {
        $headerVar = 'http_response_header';

        return $definedVars[$headerVar] ?? [];
    }

    /**
     * Normalize a path: collapse repeated slashes and resolve '.' segments.
     *
     * Note: '..' segments are NOT resolved here; they are checked separately.
     */
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
