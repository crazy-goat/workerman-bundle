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
        $context = $this->buildContext($url, $allowInsecure);

        $in = @fopen($url, 'rb', false, $context);
        if (!is_resource($in)) {
            $err = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException(sprintf('Failed to open "%s" for download: %s', $url, $err));
        }

        $out = fopen($destination, 'wb');
        if (!is_resource($out)) {
            fclose($in);
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $destination));
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, self::DOWNLOAD_CHUNK);
                if ($chunk === false) {
                    throw new \RuntimeException(sprintf('Failed to read from "%s".', $url));
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
                'follow_location' => 1,
                'max_redirects' => 5,
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
