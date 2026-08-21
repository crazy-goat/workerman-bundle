<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

use CrazyGoat\WorkermanBundle\Exception\SfxExtractionException;

/**
 * Downloads phpmicro.sfx (the static PHP runtime used to build standalone
 * binaries) from an HTTPS mirror, optionally verifying a SHA-256 digest.
 *
 * Redirects are always followed manually (never by the PHP http wrapper) so
 * that every hop can be scheme-checked; `$allowInsecure` only disables TLS
 * peer verification.
 *
 * @internal
 */
final readonly class SfxDownloader
{
    private const DOWNLOAD_CHUNK = 1 << 16;

    private const DEFAULT_MAX_DOWNLOAD_BYTES = 256 * 1024 * 1024;

    /**
     * @param int $maxDownloadBytes maximum accepted download size in bytes
     */
    public function __construct(private int $maxDownloadBytes = self::DEFAULT_MAX_DOWNLOAD_BYTES)
    {
        if ($maxDownloadBytes <= 0) {
            throw new \InvalidArgumentException('maxDownloadBytes must be a positive number of bytes.');
        }
    }

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

        // Verify the downloaded artifact before any extractor runs over its
        // bytes: a tampered archive must never reach ZipArchive.
        if (is_string($expectedSha256) && $expectedSha256 !== '') {
            try {
                self::verifyChecksum($destination, $expectedSha256);
            } catch (\RuntimeException $e) {
                // Never leave a failed artifact behind: without unlink(),
                // every subsequent fetch() re-verifies the same bad bytes
                // and never retries the download. Only claim the removal in
                // the message when it actually succeeded.
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

        // If the upstream artifact is a zip, extract it.
        if (str_ends_with($destination, '.zip')) {
            try {
                $destination = $this->extractZip($destination, $destinationDir);
            } catch (\RuntimeException $e) {
                // A failed extraction — corrupt archive, malicious entry,
                // or no usable SFX entry — would poison every later fetch()
                // the same way a bad checksum would: remove the archive and
                // rethrow the original exception unchanged (type/message).
                // If the removal itself fails, the bad artifact stays on
                // disk and every later fetch() fails on it again; error_log()
                // the failure so the operator knows to remove it by hand.
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
        // Redirects are always followed manually so every hop can be
        // scheme-checked; $allowInsecure only controls TLS peer verification.
        $this->downloadWithRedirectCheck($url, $destination, $allowInsecure);
    }

    private function downloadWithRedirectCheck(string $url, string $destination, bool $allowInsecure): void
    {
        $maxRedirects = 5;
        $currentUrl = $url;

        for ($i = 0; $i <= $maxRedirects; $i++) {
            $context = $this->buildContext($currentUrl, $allowInsecure);

            // Pre-declare $http_response_header so the http wrapper populates
            // it in the fopen() scope (it is only set when the variable is
            // already declared there). On PHP 8.4+ this can be replaced with
            // http_get_last_response_headers(); the fallback must stay for
            // PHP 8.2/8.3.
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
                // Only the first matching header is used; obs-fold continuation
                // lines are not supported and are simply ignored.
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
     * Enforce the redirect scheme policy for a single hop.
     *
     * @throws \RuntimeException when the hop targets a non-HTTP(S) scheme or
     *                           downgrades from HTTPS to plain HTTP
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

        // Any other absolute scheme (file://, php://, ftp://, ...) must never
        // be passed back to fopen(): reject it before resolving relative
        // locations, so a non-HTTP(S) value can never be returned.
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

        // parse_url() preserves the case of the scheme; normalize it so the
        // rebuilt URL always uses a lowercase scheme prefix.
        $scheme = strtolower($parts['scheme']);

        // Protocol-relative location ("//cdn.example/path"): same scheme,
        // different host.
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
        $out = fopen($destination, 'wb');
        if (!is_resource($out)) {
            fclose($in);
            throw new \RuntimeException(sprintf('Unable to open "%s" for writing.', $destination));
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

            // Never leave a partial artifact behind: fetch() treats an
            // existing destination file as a complete download.
            if ($failed && is_file($destination)) {
                unlink($destination);
            }
        }
    }

    /**
     * @return resource|null
     */
    private function buildContext(string $url, bool $allowInsecure)
    {
        // Scheme prefixes are matched case-insensitively: PHP's http wrapper
        // accepts mixed-case schemes, and a redirect to "HTTPS://..." must
        // not fall through to the default context (which follows redirects).
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
     * Extract a phpmicro.sfx zip archive.
     *
     * Stages:
     *  1. Open the zip archive with integrity checks.
     *  2. List all entry names (validating each against zip-slip) — a failing
     *     entry aborts before anything is extracted (all-or-nothing).
     *  3. Extract all entries to the destination directory, one at a time:
     *     each entry is re-validated by name and its resolved target must
     *     stay inside the destination directory (containment backstop).
     *     Successfully extracted entries are tracked so they can be removed
     *     if a later stage fails, leaving the destination as it was.
     *  4. Locate the SFX entry:
     *     a. Try the entry whose basename matches the zip filename (minus .zip).
     *     b. Fall back to the first regular file entry from the archive.
     *     If no usable entry is found, all extracted entries are removed
     *     before the exception propagates.
     *
     * @throws SfxExtractionException when no suitable SFX entry is found
     * @throws \RuntimeException on archive open, validation, or extraction failures
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
     * Extract all zip entries to the destination directory, one entry at a time.
     *
     * Every entry is checked immediately before it is extracted: the name
     * rules from {@see validateEntryName()} are applied to exactly the
     * entries that get extracted, and the resolved target must stay inside
     * the destination directory (containment backstop). Note that
     * ZipArchive::extractTo() materialises symlink entries as regular files
     * rather than creating links — the containment check guards the
     * destination's pre-existing state, which name rules alone cannot see.
     *
     * @return string[] Names of the entries that were successfully extracted,
     *                   in extraction order (for rollback on a later failure)
     *
     * @throws \RuntimeException if the destination cannot be resolved, an
     *                           entry fails validation, escapes the destination,
     *                           or extraction fails
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

                // Name-level zip-slip rules stay the first line of defence; they
                // run here against exactly the entry being extracted.
                $this->validateEntryName($name);

                $this->assertEntryContainedIn($destinationBase, $name, $zipPath);

                if (!$zip->extractTo($destinationDir, $name)) {
                    throw new \RuntimeException(sprintf(
                        'Failed to extract entry "%s" from zip archive "%s".',
                        $name,
                        $zipPath,
                    ));
                }

                $extractedEntries[] = $name;
            }
        } catch (\RuntimeException $e) {
            // A mid-extraction failure leaves entries 1..N-1 on disk; remove
            // them so a failed fetch leaves the destination as it was.
            $this->removeExtractedEntries($extractedEntries, $destinationDir);
            throw $e;
        }

        return $extractedEntries;
    }

    /**
     * Remove entries that were extracted during a failed fetch.
     *
     * Both files and directories created by the extraction are removed.
     * Tracked entries and auto-created parent directories are merged and
     * sorted by path depth descending so that deeper directories are
     * removed before their parents (e.g. "sub/nested/" is removed before
     * "sub/"). Auto-created parent directories that have no corresponding
     * zip entry are also removed when they become empty. Any entry that is
     * no longer on disk (already gone, or never materialised for empty-dir
     * entries on some platforms) is silently skipped. Pre-existing entries
     * are never touched: we only remove paths whose name matches an
     * extracted entry or was auto-created as a parent of one. Removal
     * failures are logged via error_log() so the operator can investigate
     * leftover partial files — the same principle as the archive unlink
     * in {@see fetch()}.
     *
     * @param string[] $extractedEntries Names of successfully extracted entries
     * @param string   $destinationDir   Directory entries were extracted into
     */
    private function removeExtractedEntries(array $extractedEntries, string $destinationDir): void
    {
        $base = rtrim($destinationDir, '/\\');

        // Collect all parent directories of extracted entries — these are
        // auto-created by ZipArchive::extractTo() and have no corresponding
        // zip entry, so they must be cleaned up alongside tracked entries.
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

        // Sort all paths by depth descending so that deeper directories are
        // removed before their parents (rmdir requires an empty directory).
        // Tracked entries and auto-created parents are merged and sorted
        // together by the number of path separators.
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
                    // rmdir fails on non-empty dirs — this is expected for
                    // parents whose children haven't been removed yet, or
                    // for pre-existing directories we didn't create. Only
                    // log when the directory is empty but still can't be
                    // removed (permissions, etc.).
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
     * Assert that an entry's target resolves inside the destination directory.
     *
     * The entry name has already passed {@see validateEntryName()}, so a
     * lexical escape ("..", absolute path, drive letter, backslash) is not
     * possible; this check is the containment backstop for escapes that
     * lexical rules cannot see — most importantly a pre-existing symlink
     * inside the destination tree (entry "sub/evil.bin" where "sub" is a
     * symlink to a directory outside the destination). The deepest
     * already-existing ancestor of the target is resolved with realpath()
     * and must itself be inside the destination; the destination directory
     * exists at this point, so the ancestor walk always terminates.
     *
     * @throws \RuntimeException if the entry resolves outside the destination
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
