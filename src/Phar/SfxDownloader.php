<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

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
     * @throws \RuntimeException on any download/extract/verification failure
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

    private function extractZip(string $zipPath, string $destinationDir): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The zip extension is required to extract the downloaded SFX archive.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException(sprintf('Failed to open zip archive "%s".', $zipPath));
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        if (!$zip->extractTo($destinationDir)) {
            $zip->close();
            throw new \RuntimeException(sprintf('Failed to extract zip archive "%s".', $zipPath));
        }
        $zip->close();

        $extracted = rtrim($destinationDir, '/') . '/' . str_replace('.zip', '', basename($zipPath));
        if (is_file($extracted)) {
            return $extracted;
        }

        // Fall back to the first regular file entry from the archive.
        foreach ($names as $name) {
            $candidate = rtrim($destinationDir, '/') . '/' . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf('Could not locate extracted SFX file in "%s".', $destinationDir));
    }
}
