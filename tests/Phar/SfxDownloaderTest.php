<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Exception\SfxExtractionException;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use PHPUnit\Framework\TestCase;

/**
 * @group network
 */

final class SfxDownloaderTest extends TestCase
{
    private static ?int $serverPort = null;

    /** @var resource|null */
    private static $serverProcess;

    private static ?string $routerFile = null;

    private string $tempDir;

    /** @var list<string> Directories created outside $this->tempDir that tearDown() must remove */
    private array $cleanupDirs = [];

    public static function setUpBeforeClass(): void
    {
        self::$serverPort = self::allocatePort();
        self::startRedirectServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        if (is_string(self::$routerFile)) {
            @unlink(self::$routerFile);
            self::$routerFile = null;
        }
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sfx-downloader-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
        foreach ($this->cleanupDirs as $dir) {
            $this->rmdirRecursive($dir);
        }
        $this->cleanupDirs = [];
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                // Never follow a symlink into its target: the tree may
                // contain links planted by the tests to outside directories.
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Create a zip archive containing a single symlink entry.
     *
     * ZipArchive has no API to mark an entry as a symlink, so the archive is
     * assembled by hand: one STORED entry whose Unix external attributes
     * carry the symlink mode (S_IFLNK | 0777) and whose content is the link
     * target path.
     */
    private function createZipWithSymlinkEntry(string $zipPath, string $linkName, string $linkTarget): void
    {
        $name = $linkName;
        $content = $linkTarget;
        $crc = hexdec(hash('crc32b', $content));
        $size = strlen($content);

        // One STORED entry: local file header followed by the raw content at
        // offset 0; the central directory points back to that header.
        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50, // local file header signature
            20,         // version needed to extract
            0x0800,     // general purpose bit flags (UTF-8)
            0,          // compression method: stored
            0,          // last mod time
            0,          // last mod date
            $crc,
            $size,      // compressed size
            $size,      // uncompressed size
            strlen($name),
            0,          // extra field length
        ) . $name;

        // version made by: 0x031E (Unix, 3.0); symlink mode (S_IFLNK | 0777)
        // lives in the upper 16 bits of the external attributes.
        $externalAttrs = (0120000 | 0777) << 16;

        $centralDirectory = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,   // central directory signature
            0x031E,       // version made by (Unix, 3.0)
            20,           // version needed to extract
            0x0800,       // general purpose bit flags (UTF-8)
            0,            // compression method: stored
            0,            // last mod time
            0,            // last mod date
            $crc,
            $size,
            $size,
            strlen($name),
            0,            // extra field length
            0,            // file comment length
            0,            // disk number start
            0,            // internal attributes
            $externalAttrs,
            0,            // local header offset
        ) . $name;

        $endOfCentralDirectory = pack(
            'VvvvvVVv',
            0x06054b50,   // end of central directory signature
            0,            // disk number
            0,            // disk with central directory
            1,            // entries on this disk
            1,            // total entries
            strlen($centralDirectory),
            strlen($localHeader) + strlen($content), // central directory offset
            0,            // comment length
        );

        file_put_contents($zipPath, $localHeader . $content . $centralDirectory . $endOfCentralDirectory);
    }

    /**
     * @param array<string, string> $entries Map of entry name to content
     */
    private function createZipWithEntry(string $zipPath, array $entries): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create test zip: ' . $zipPath);
        }

        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }

        $zip->close();
    }

    public function testFilenameFromUrlExtractsBasename(): void
    {
        self::assertSame('php8.3.micro.sfx', SfxDownloader::filenameFromUrl('https://x/y/php8.3.micro.sfx'));
        self::assertSame('a.sfx.zip', SfxDownloader::filenameFromUrl('https://x/a.sfx.zip?token=abc'));
        self::assertSame('phpmicro.sfx', SfxDownloader::filenameFromUrl('https://x/'));
    }

    public function testVerifyChecksumPassesOnMatch(): void
    {
        $file = $this->tempDir . '/data';
        file_put_contents($file, 'hello world');

        $expected = hash('sha256', 'hello world');

        SfxDownloader::verifyChecksum($file, $expected);
        SfxDownloader::verifyChecksum($file, strtoupper($expected)); // case-insensitive
        $this->addToAssertionCount(2);
    }

    public function testVerifyChecksumThrowsOnMismatch(): void
    {
        $file = $this->tempDir . '/data';
        file_put_contents($file, 'hello world');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');

        SfxDownloader::verifyChecksum($file, str_repeat('0', 64));
    }

    public function testFetchReusesExistingFileAndVerifiesChecksum(): void
    {
        $existing = $this->tempDir . '/php8.3.micro.sfx';
        file_put_contents($existing, 'static-php-bytes');

        $expected = hash('sha256', 'static-php-bytes');

        $path = (new SfxDownloader())->fetch(
            'https://example.invalid/php8.3.micro.sfx',
            $this->tempDir,
            $expected,
        );

        self::assertSame($existing, $path);
    }

    public function testFetchFailsWhenExistingFileChecksumDiffers(): void
    {
        $existing = $this->tempDir . '/php8.3.micro.sfx';
        file_put_contents($existing, 'static-php-bytes');

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/php8.3.micro.sfx',
                $this->tempDir,
                str_repeat('a', 64),
            );
            self::fail('Expected a RuntimeException for the checksum mismatch.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SHA-256 mismatch', $e->getMessage());
            self::assertStringContainsString('removed', $e->getMessage());
        }

        // The poisoned artifact must not survive for a later fetch() to trust.
        self::assertFileDoesNotExist($existing);
    }

    public function testFetchRetriesDownloadAfterChecksumMismatch(): void
    {
        // First fetch fails the checksum; the failed artifact is unlinked, so
        // the second fetch re-downloads instead of re-verifying bad bytes.
        $url = sprintf('http://127.0.0.1:%d/sfx', self::$serverPort);
        $destination = $this->tempDir . '/sfx';

        try {
            (new SfxDownloader())->fetch($url, $this->tempDir, str_repeat('0', 64));
            self::fail('Expected a RuntimeException for the first checksum mismatch.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SHA-256 mismatch', $e->getMessage());
        }

        self::assertFileDoesNotExist($destination);

        $path = (new SfxDownloader())->fetch($url, $this->tempDir, hash('sha256', 'sfx-content'));
        self::assertSame($destination, $path);
        self::assertStringEqualsFile($path, 'sfx-content');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function maliciousEntryProvider(): array
    {
        return [
            'path traversal (../evil.bin)' => ['../evil.bin', 'path traversal'],
            'absolute path (/etc/evil)' => ['/etc/evil', 'absolute path'],
            'windows drive letter (C:/evil.dll)' => ['C:/windows/evil.dll', 'drive letter'],
            'backslash in entry (subdir\\evil.bin)' => ["subdir\\evil.bin", 'backslash'],
        ];
    }

    /**
     * @dataProvider maliciousEntryProvider
     * @requires extension zip
     */
    public function testExtractZipRejectsMaliciousEntry(string $entryName, string $expectedMessage): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            $entryName => 'malicious-content',
        ]);

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $this->tempDir,
            );
            self::fail('Expected a RuntimeException for the malicious zip entry.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
        }

        // The poisoned artifact must not survive for a later fetch() to trust.
        self::assertFileDoesNotExist($zipPath);
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipRejectsEntryWithSubdirectoryTraversal(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'subdir/../../evil.bin' => 'malicious-content',
        ]);

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $this->tempDir,
            );
            self::fail('Expected a RuntimeException for the traversal zip entry.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('path traversal', $e->getMessage());
        }

        // The poisoned artifact must not survive for a later fetch() to trust.
        self::assertFileDoesNotExist($zipPath);
    }

    /**
     * An entry that would resolve outside the destination directory via a
     * pre-existing symlink inside the destination tree must be rejected: the
     * name rules alone cannot see it (no "..", no absolute path), so the
     * destination-containment backstop has to catch it.
     *
     * @requires extension zip
     */
    public function testExtractZipRejectsEntryEscapingViaSymlinkedSubdirectory(): void
    {
        $outside = sys_get_temp_dir() . '/sfx-outside-' . uniqid();
        mkdir($outside, 0755, true);
        $this->cleanupDirs[] = $outside;

        if (!@symlink($outside, $this->tempDir . '/sub')) {
            self::markTestSkipped('symlink() is not available on this platform.');
        }

        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'sub/evil.bin' => 'escaped-content',
        ]);

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $this->tempDir,
            );
            self::fail('Expected a RuntimeException for the escaping zip entry.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('resolves outside the destination directory', $e->getMessage());
        }

        // The poisoned artifact must not survive for a later fetch() to trust.
        self::assertFileDoesNotExist($zipPath);
    }

    /**
     * A corrupt archive whose ZipArchive::open() fails must be unlinked even
     * when no checksum is configured, so a later fetch() re-downloads
     * instead of failing on the same bad bytes forever.
     *
     * @requires extension zip
     */
    public function testExtractZipRemovesCorruptArchiveWhenOpenFails(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        file_put_contents($zipPath, str_repeat('not-a-zip-', 100));

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $this->tempDir,
            );
            self::fail('Expected a RuntimeException for the corrupt archive.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to open zip archive', $e->getMessage());
        }

        // The corrupt artifact must not survive for a later fetch() to trust.
        self::assertFileDoesNotExist($zipPath);
    }

    /**
     * A crafted archive containing a symlink entry must not produce a symlink
     * on disk: ZipArchive::extractTo() materialises link entries as regular
     * files containing the link target, which the current extraction design
     * depends on.
     *
     * @requires extension zip
     */
    public function testExtractZipDoesNotCreateSymlinkFromSymlinkEntry(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithSymlinkEntry($zipPath, 'phpmicro.sfx', '../../outside/passwd');

        $result = (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );

        self::assertFileExists($result);
        self::assertFalse(is_link($result), 'A symlink entry must not produce a symlink on disk.');
        self::assertSame('../../outside/passwd', file_get_contents($result));
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipSucceedsWithLegitimateArchive(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'phpmicro.sfx' => 'static-php-binary-content',
        ]);

        $result = (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );

        self::assertFileExists($result);
        self::assertStringContainsString('phpmicro.sfx', $result);
        self::assertStringEqualsFile($result, 'static-php-binary-content');
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipSucceedsWithEntryInSubdirectory(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'bin/phpmicro.sfx' => 'static-php-binary-content',
        ]);

        $result = (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );

        self::assertFileExists($result);
        self::assertStringContainsString('phpmicro.sfx', $result);
        self::assertStringEqualsFile($result, 'static-php-binary-content');
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipDoesNotRejectDotsInFilename(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'v2.0.1.sfx' => 'static-php-binary-content',
        ]);

        $result = (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );

        self::assertFileExists($result);
        self::assertStringEqualsFile($result, 'static-php-binary-content');
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipThrowsTypedExceptionWhenNoEntryFound(): void
    {
        $zipPath = $this->tempDir . '/orphan.sfx.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create test zip: ' . $zipPath);
        }
        $zip->addEmptyDir('subdir');
        $zip->close();

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/orphan.sfx.zip',
                $this->tempDir,
            );
            self::fail('Expected SfxExtractionException for an archive with no usable entry.');
        } catch (SfxExtractionException $e) {
            self::assertStringContainsString('Could not locate extracted SFX file', $e->getMessage());
        }

        // The useless archive must not survive for a later fetch() to trust.
        self::assertFileDoesNotExist($zipPath);
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipPrimaryRuleMatchesZipBasename(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'phpmicro.sfx' => 'primary-rule-match',
            'other.bin' => 'should-not-be-picked',
        ]);

        $result = (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );

        self::assertStringEndsWith('phpmicro.sfx', $result);
        self::assertStringEqualsFile($result, 'primary-rule-match');
    }

    /**
     * @requires extension zip
     */
    public function testExtractZipFallbackPicksFirstFileEntry(): void
    {
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'release.sfx' => 'fallback-entry-content',
        ]);

        $result = (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );

        self::assertFileExists($result);
        self::assertStringEqualsFile($result, 'fallback-entry-content');
    }

    public function testBuildContextDisablesRedirectFollowingForHttpUrl(): void
    {
        $context = $this->invokeBuildContext('http://example.com/file.sfx', false);
        self::assertNotNull($context);

        $options = stream_context_get_options($context);
        self::assertArrayHasKey('http', $options);
        self::assertSame(0, $options['http']['follow_location']);
        self::assertSame(0, $options['http']['max_redirects']);
        self::assertArrayNotHasKey('ssl', $options);
    }

    public function testBuildContextSchemeDetectionIsCaseInsensitive(): void
    {
        $httpContext = $this->invokeBuildContext('HTTP://example.com/file.sfx', false);
        self::assertNotNull($httpContext);
        self::assertSame(0, stream_context_get_options($httpContext)['http']['follow_location']);

        $httpsContext = $this->invokeBuildContext('HTTPS://example.com/file.sfx', false);
        self::assertNotNull($httpsContext);
        $options = stream_context_get_options($httpsContext);
        self::assertSame(0, $options['http']['follow_location']);
        self::assertTrue($options['ssl']['verify_peer']);
    }

    public function testBuildContextHttpUrlOptionsIndependentOfAllowInsecure(): void
    {
        $secure = stream_context_get_options($this->invokeBuildContext('http://example.com/file.sfx', false));
        $insecure = stream_context_get_options($this->invokeBuildContext('http://example.com/file.sfx', true));

        self::assertSame($secure, $insecure);
    }

    public function testBuildContextDisablesFollowLocationWhenInsecure(): void
    {
        $context = $this->invokeBuildContext('https://example.com/file.sfx', true);
        self::assertNotNull($context);

        $options = stream_context_get_options($context);
        self::assertArrayHasKey('http', $options);
        self::assertSame(0, $options['http']['follow_location']);
        self::assertSame(0, $options['http']['max_redirects']);
        self::assertFalse($options['ssl']['verify_peer']);
        self::assertFalse($options['ssl']['verify_peer_name']);
    }

    public function testBuildContextDisablesFollowLocationWhenSecure(): void
    {
        $context = $this->invokeBuildContext('https://example.com/file.sfx', false);
        self::assertNotNull($context);

        $options = stream_context_get_options($context);
        self::assertArrayHasKey('http', $options);
        self::assertSame(0, $options['http']['follow_location']);
        self::assertSame(0, $options['http']['max_redirects']);
        self::assertTrue($options['ssl']['verify_peer']);
        self::assertTrue($options['ssl']['verify_peer_name']);
    }

    public function testInsecureDiffersFromDefaultOnlyInPeerVerification(): void
    {
        $secure = stream_context_get_options($this->invokeBuildContext('https://example.com/file.sfx', false));
        $insecure = stream_context_get_options($this->invokeBuildContext('https://example.com/file.sfx', true));

        self::assertSame($secure['http'], $insecure['http']);
        self::assertSame(0, $secure['http']['follow_location']);
        self::assertTrue($secure['ssl']['verify_peer']);
        self::assertFalse($insecure['ssl']['verify_peer']);
        self::assertTrue($secure['ssl']['verify_peer_name']);
        self::assertFalse($insecure['ssl']['verify_peer_name']);
    }

    public function testResolveRedirectUrlAbsolute(): void
    {
        $result = $this->invokePrivateSfxMethod('resolveRedirectUrl', 'https://example.com/a/b/c.sfx', 'https://other.com/d.sfx');

        self::assertSame('https://other.com/d.sfx', $result);
    }

    public function testResolveRedirectUrlRelativePath(): void
    {
        $result = $this->invokePrivateSfxMethod('resolveRedirectUrl', 'https://example.com/a/b/c.sfx', 'd.sfx');

        self::assertSame('https://example.com/a/b/d.sfx', $result);
    }

    public function testResolveRedirectUrlAbsolutePath(): void
    {
        $result = $this->invokePrivateSfxMethod('resolveRedirectUrl', 'https://example.com/a/b/c.sfx', '/d.sfx');

        self::assertSame('https://example.com/d.sfx', $result);
    }

    public function testResolveRedirectUrlWithPort(): void
    {
        $result = $this->invokePrivateSfxMethod('resolveRedirectUrl', 'https://example.com:8443/a/b/c.sfx', '/d.sfx');

        self::assertSame('https://example.com:8443/d.sfx', $result);
    }

    public function testResolveRedirectUrlProtocolRelative(): void
    {
        $result = $this->invokePrivateSfxMethod('resolveRedirectUrl', 'https://example.com/a/b/c.sfx', '//cdn.example.com/d.sfx');

        self::assertSame('https://cdn.example.com/d.sfx', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonHttpSchemeLocationProvider(): array
    {
        return [
            'ftp' => ['ftp://example.invalid/evil.sfx'],
            'file' => ['file:///etc/passwd'],
            'php' => ['php://filter/resource=/etc/passwd'],
        ];
    }

    /**
     * @dataProvider nonHttpSchemeLocationProvider
     */
    public function testResolveRedirectUrlRejectsNonHttpScheme(string $location): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Blocked redirect to non-HTTP(S) scheme "%s"', $location));

        $this->invokePrivateSfxMethod('resolveRedirectUrl', 'https://example.com/a/b/c.sfx', $location);
    }

    public function testResolveRedirectUrlThrowsWhenBaseUrlCannotBeParsed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve redirect location "d.sfx" against base URL "no-scheme/path".');

        $this->invokePrivateSfxMethod('resolveRedirectUrl', 'no-scheme/path', 'd.sfx');
    }

    public function testHttpsToHttpDowngradeRejectedInDefaultMode(): void
    {
        // The scheme policy is applied on every hop in every mode: the check
        // does not depend on $allowInsecure at all.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blocked cross-scheme redirect from HTTPS to "http://attacker.example/sfx". Disable redirects or use a trusted mirror.');

        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'https://mirror.example/php.sfx', 'http://attacker.example/sfx');
    }

    public function testHttpsToHttpDowngradeRejectedWhenInsecure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blocked cross-scheme redirect from HTTPS to "http://attacker.example/sfx".');

        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'https://mirror.example/php.sfx', 'http://attacker.example/sfx');
    }

    public function testHttpsToHttpDowngradeRejectedWithMixedCaseSchemes(): void
    {
        // A hostile mirror may emit an uppercase-scheme Location header; the
        // policy must not be bypassable via case differences.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blocked cross-scheme redirect from HTTPS to "HTTP://attacker.example/sfx".');

        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'HTTPS://mirror.example/php.sfx', 'HTTP://attacker.example/sfx');
    }

    /**
     * @dataProvider nonHttpSchemeLocationProvider
     */
    public function testNonHttpSchemeRejectedFromHttpsOrigin(string $location): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Blocked redirect to non-HTTP(S) scheme "%s"', $location));

        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'https://mirror.example/php.sfx', $location);
    }

    public function testSameSchemeRedirectsAllowed(): void
    {
        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'https://mirror.example/a.sfx', 'https://mirror2.example/b.sfx');
        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'http://mirror.example/a.sfx', 'http://mirror2.example/b.sfx');
        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'http://mirror.example/a.sfx', 'https://mirror2.example/b.sfx');
        $this->addToAssertionCount(3);
    }

    public function testDownloadWithRedirectCheckExtractsSfxFilename(): void
    {
        $tempFile = $this->tempDir . '/downloaded.sfx';
        file_put_contents($tempFile, 'sfx-content');

        $result = (new SfxDownloader())->fetch(
            'file://' . $tempFile,
            $this->tempDir . '/out',
            null,
            true,
        );

        self::assertFileExists($result);
        self::assertStringEqualsFile($result, 'sfx-content');
    }

    public function testSameSchemeRedirectChainFollowedUpToLimit(): void
    {
        $result = (new SfxDownloader())->fetch(
            sprintf('http://127.0.0.1:%d/r1', self::$serverPort),
            $this->tempDir,
        );

        self::assertFileExists($result);
        self::assertStringEqualsFile($result, 'sfx-content');
    }

    public function testRedirectChainExceedingLimitErrors(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many redirects (max 5)');

        (new SfxDownloader())->fetch(
            sprintf('http://127.0.0.1:%d/r6', self::$serverPort),
            $this->tempDir,
        );
    }

    public function testRedirectLimitEnforcedForMixedCaseSchemeUrl(): void
    {
        // An uppercase-scheme URL must still get the manual redirect loop
        // (follow_location => 0), not the default context that follows
        // redirects inside the wrapper.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many redirects (max 5)');

        (new SfxDownloader())->fetch(
            sprintf('HTTP://127.0.0.1:%d/r6', self::$serverPort),
            $this->tempDir,
        );
    }

    public function testConstructorRejectsNonPositiveMaxDownloadBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxDownloadBytes must be a positive number of bytes.');

        new SfxDownloader(0);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonHttpSchemePathProvider(): array
    {
        return [
            'ftp' => ['/ftp', 'ftp://example.invalid/evil.sfx'],
            'file' => ['/file', 'file:///etc/passwd'],
            'php' => ['/php', 'php://filter/resource=/etc/passwd'],
        ];
    }

    /**
     * @dataProvider nonHttpSchemePathProvider
     */
    public function testRedirectToNonHttpSchemeRejectedFromHttpOrigin(string $path, string $location): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Blocked redirect to non-HTTP(S) scheme "%s"', $location));

        (new SfxDownloader())->fetch(
            sprintf('http://127.0.0.1:%d%s', self::$serverPort, $path),
            $this->tempDir,
        );
    }

    public function testDownloadExceedingMaxSizeIsAborted(): void
    {
        try {
            (new SfxDownloader(1024))->fetch(
                sprintf('http://127.0.0.1:%d/big', self::$serverPort),
                $this->tempDir,
            );
            self::fail('Expected a RuntimeException for the oversized download.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('maximum allowed size of 1024 bytes', $e->getMessage());
        }

        // The partial artifact must not be left behind for a later fetch() to reuse.
        self::assertFileDoesNotExist($this->tempDir . '/big');
    }

    public function testChecksumIsVerifiedBeforeZipExtraction(): void
    {
        // A corrupt archive that would fail ZipArchive::open(): the SHA-256
        // mismatch must surface before any extraction is attempted.
        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        file_put_contents($zipPath, 'PK' . random_bytes(64));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');

        (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
            str_repeat('0', 64),
        );
    }

    private static function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (!is_resource($socket)) {
            throw new \RuntimeException('Failed to allocate a test port.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private static function startRedirectServer(): void
    {
        self::$routerFile = sys_get_temp_dir() . '/sfx-router-' . uniqid('', true) . '.php';
        file_put_contents(self::$routerFile, <<<'PHP_WRAP'
<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$redirect = static function (string $location): never {
    header('Location: ' . $location, true, 302);
    exit;
};

switch ($path) {
    case '/r1':
        $redirect('/r2');
    case '/r2':
        $redirect('/r3');
    case '/r3':
        $redirect('/r4');
    case '/r4':
        $redirect('/r5');
    case '/r5':
        $redirect('/sfx');
    case '/r6':
        $redirect('/r6');
    case '/ftp':
        $redirect('ftp://example.invalid/evil.sfx');
    case '/file':
        $redirect('file:///etc/passwd');
    case '/php':
        $redirect('php://filter/resource=/etc/passwd');
    case '/big':
        echo str_repeat('x', 65536);
        exit;
    case '/sfx':
    default:
        echo 'sfx-content';
        exit;
}
PHP_WRAP);

        $command = [PHP_BINARY, '-S', sprintf('127.0.0.1:%d', self::$serverPort), self::$routerFile];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start the test HTTP server.');
        }
        self::$serverProcess = $process;

        // Wait until the server accepts connections.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $errno = 0;
            $errstr = '';
            $sock = @fsockopen('127.0.0.1', (int) self::$serverPort, $errno, $errstr, 0.2);
            if ($sock !== false) {
                fclose($sock);

                return;
            }
            usleep(50000);
        }

        throw new \RuntimeException(sprintf(
            'Test HTTP server did not start on port %d.',
            self::$serverPort,
        ));
    }

    private function invokeBuildContext(string $url, bool $allowInsecure): mixed
    {
        return $this->invokePrivateSfxMethod('buildContext', $url, $allowInsecure);
    }

    private function invokePrivateSfxMethod(string $methodName, mixed ...$args): mixed
    {
        $method = new \ReflectionMethod(SfxDownloader::class, $methodName);

        return $method->invoke(new SfxDownloader(), ...$args);
    }
}
