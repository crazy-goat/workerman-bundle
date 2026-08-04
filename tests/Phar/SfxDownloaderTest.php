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

    private string $tempDir;

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
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sfx-downloader-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
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
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');

        (new SfxDownloader())->fetch(
            'https://example.invalid/php8.3.micro.sfx',
            $this->tempDir,
            str_repeat('a', 64),
        );
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('path traversal');

        (new SfxDownloader())->fetch(
            'https://example.invalid/phpmicro.sfx.zip',
            $this->tempDir,
        );
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

        $this->expectException(SfxExtractionException::class);
        $this->expectExceptionMessage('Could not locate extracted SFX file');

        (new SfxDownloader())->fetch(
            'https://example.invalid/orphan.sfx.zip',
            $this->tempDir,
        );
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
        $router = tempnam(sys_get_temp_dir(), 'sfx-router-') . '.php';
        file_put_contents($router, <<<'PHP_WRAP'
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

        $command = [PHP_BINARY, '-S', sprintf('127.0.0.1:%d', self::$serverPort), $router];
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
