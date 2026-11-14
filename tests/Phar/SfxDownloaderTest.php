<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Exception\SfxExtractionException;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[Group('network')]
final class SfxDownloaderTest extends TestCase
{
    private static ?int $serverPort = null;

    /** @var resource|null */
    private static $serverProcess;

    private static ?string $routerFile = null;

    private string $tempDir;

    /** @var list<string> */
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
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function createZipWithSymlinkEntry(string $zipPath, string $linkName, string $linkTarget): void
    {
        $name = $linkName;
        $content = $linkTarget;
        $crc = hexdec(hash('crc32b', $content));
        $size = strlen($content);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
        ) . $name;

        $externalAttrs = (0120000 | 0777) << 16;

        $centralDirectory = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            0x031E,
            20,
            0x0800,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            $externalAttrs,
            0,
        ) . $name;

        $endOfCentralDirectory = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            1,
            1,
            strlen($centralDirectory),
            strlen($localHeader) + strlen($content),
            0,
        );

        file_put_contents($zipPath, $localHeader . $content . $centralDirectory . $endOfCentralDirectory);
    }

    /**
     * @param array<string, string> $entries
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
        SfxDownloader::verifyChecksum($file, strtoupper($expected));
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

        self::assertFileDoesNotExist($existing);
    }

    public function testFetchRetriesDownloadAfterChecksumMismatch(): void
    {
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

    public function testFetchThrowsWhenDestinationDirectoryIsNotWritable(): void
    {
        $dir = $this->tempDir . '/readonly-dest';
        mkdir($dir, 0755, true);

        chmod($dir, 0555);
        if (is_writable($dir)) {
            chmod($dir, 0755);
            self::markTestSkipped('Cannot simulate a non-writable directory: it stays writable (running as root?).');
        }

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/php8.3.micro.sfx',
                $dir,
            );
            self::fail('Expected a RuntimeException for the non-writable destination directory.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($dir, $e->getMessage());
            self::assertStringContainsString('not writable', $e->getMessage());
        } finally {
            chmod($dir, 0755);
        }
    }

    #[RequiresPhpExtension('zip')]
    public function testFetchFailsFastWhenCachedZipDestinationIsNotWritable(): void
    {
        $dir = $this->tempDir . '/readonly-cached-zip';
        mkdir($dir, 0755, true);
        $zipPath = $dir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, ['phpmicro.sfx' => 'fake-sfx-bytes']);

        chmod($dir, 0555);
        if (is_writable($dir)) {
            chmod($dir, 0755);
            self::markTestSkipped('Cannot simulate a non-writable directory: it stays writable (running as root?).');
        }

        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $dir,
            );
            self::fail('Expected a RuntimeException for the non-writable destination directory.');
        } catch (\RuntimeException $e) {
            self::assertSame(
                sprintf('Destination directory "%s" is not writable; check its permissions before extracting.', $dir),
                $e->getMessage(),
            );
        } finally {
            chmod($dir, 0755);
        }

        self::assertFileExists($zipPath);
        self::assertFileDoesNotExist($dir . '/phpmicro.sfx');
    }

    #[RequiresPhpExtension('zip')]
    public function testFetchReextractsInPlaceWhenNonWritableDirectoryAlreadyHoldsEntries(): void
    {
        $dir = $this->tempDir . '/readonly-prepopulated';
        mkdir($dir, 0755, true);
        $zipPath = $dir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, ['phpmicro.sfx' => 'fake-sfx-bytes']);
        file_put_contents($dir . '/phpmicro.sfx', 'old-bytes');

        chmod($dir, 0555);
        if (is_writable($dir)) {
            chmod($dir, 0755);
            self::markTestSkipped('Cannot simulate a non-writable directory: it stays writable (running as root?).');
        }

        try {
            $result = (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $dir,
            );
        } finally {
            chmod($dir, 0755);
        }

        self::assertSame($dir . '/phpmicro.sfx', $result);
        self::assertSame('fake-sfx-bytes', file_get_contents($result));
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

    #[DataProvider('maliciousEntryProvider')]
    #[RequiresPhpExtension('zip')]
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

        self::assertFileDoesNotExist($zipPath);
    }

    #[RequiresPhpExtension('zip')]
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

        self::assertFileDoesNotExist($zipPath);
    }

    #[RequiresPhpExtension('zip')]
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

        self::assertFileDoesNotExist($zipPath);
    }

    #[RequiresPhpExtension('zip')]
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
            self::assertSame(sprintf('Failed to open zip archive "%s".', $zipPath), $e->getMessage());
        }

        self::assertFileDoesNotExist($zipPath);
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipLogsWarningWhenFailedArchiveCannotBeRemoved(): void
    {
        $dir = $this->tempDir . '/readonly';
        mkdir($dir, 0755, true);
        $zipPath = $dir . '/phpmicro.sfx.zip';
        file_put_contents($zipPath, str_repeat('not-a-zip-', 100));

        chmod($dir, 0555);
        if (is_writable($dir)) {
            chmod($dir, 0755);
            self::markTestSkipped('Cannot simulate an unlink() failure: the directory stays writable (running as root?).');
        }

        $logFile = $this->tempDir . '/error.log';
        ini_set('error_log', $logFile);
        try {
            try {
                (new SfxDownloader())->fetch(
                    'https://example.invalid/phpmicro.sfx.zip',
                    $dir,
                );
                self::fail('Expected a RuntimeException for the corrupt archive.');
            } catch (\RuntimeException $e) {
                self::assertSame(sprintf('Failed to open zip archive "%s".', $zipPath), $e->getMessage());
            }
        } finally {
            ini_restore('error_log');
            chmod($dir, 0755);
        }

        $logContent = @file_get_contents($logFile);
        self::assertIsString($logContent, 'Failed to read error_log capture file.');
        self::assertStringContainsString(
            sprintf('Unable to remove failed SFX archive "%s"', $zipPath),
            $logContent,
            'The error_log should contain the removal-failure warning.',
        );

        self::assertFileExists($zipPath);
    }

    #[RequiresPhpExtension('zip')]
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

    #[RequiresPhpExtension('zip')]
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

    #[RequiresPhpExtension('zip')]
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

    #[RequiresPhpExtension('zip')]
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

    #[RequiresPhpExtension('zip')]
    public function testExtractZipRemovesPreviouslyExtractedEntriesOnMidExtractionFailure(): void
    {
        $outside = sys_get_temp_dir() . '/sfx-outside-' . uniqid();
        mkdir($outside, 0755, true);
        $this->cleanupDirs[] = $outside;

        if (!@symlink($outside, $this->tempDir . '/sub')) {
            self::markTestSkipped('symlink() is not available on this platform.');
        }

        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'goodfile.bin' => 'good-content',
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

        self::assertFileDoesNotExist($zipPath);

        self::assertFileDoesNotExist($this->tempDir . '/goodfile.bin');
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipRemovesExtractedEntriesWhenNoSfxEntryFound(): void
    {
        $zipPath = $this->tempDir . '/orphan.sfx.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create test zip: ' . $zipPath);
        }
        $zip->addEmptyDir('subdir');
        $zip->addEmptyDir('subdir/nested');
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

        self::assertFileDoesNotExist($zipPath);

        self::assertDirectoryDoesNotExist($this->tempDir . '/subdir');
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipRemovesDirectoriesOnMidExtractionFailure(): void
    {
        $outside = sys_get_temp_dir() . '/sfx-outside-' . uniqid();
        mkdir($outside, 0755, true);
        $this->cleanupDirs[] = $outside;

        if (!@symlink($outside, $this->tempDir . '/sub')) {
            self::markTestSkipped('symlink() is not available on this platform.');
        }

        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'gooddir/goodfile.bin' => 'good-content',
            'standalone.bin' => 'standalone-content',
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

        self::assertFileDoesNotExist($zipPath);

        self::assertFileDoesNotExist($this->tempDir . '/gooddir/goodfile.bin');
        self::assertFileDoesNotExist($this->tempDir . '/standalone.bin');

        self::assertDirectoryDoesNotExist($this->tempDir . '/gooddir');
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipRemovesMultiLevelParentsOnMidExtractionFailure(): void
    {
        $outside = sys_get_temp_dir() . '/sfx-outside-' . uniqid();
        mkdir($outside, 0755, true);
        $this->cleanupDirs[] = $outside;

        if (!@symlink($outside, $this->tempDir . '/sub')) {
            self::markTestSkipped('symlink() is not available on this platform.');
        }

        $zipPath = $this->tempDir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'a/b/c/d.txt' => 'deep-content',
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

        self::assertFileDoesNotExist($zipPath);

        self::assertFileDoesNotExist($this->tempDir . '/a/b/c/d.txt');
        self::assertDirectoryDoesNotExist($this->tempDir . '/a/b/c');
        self::assertDirectoryDoesNotExist($this->tempDir . '/a/b');
        self::assertDirectoryDoesNotExist($this->tempDir . '/a');
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipRollsBackEntriesWhenLaterEntryCannotBeExtracted(): void
    {
        $dir = $this->tempDir . '/dest';
        mkdir($dir, 0755, true);
        $blocked = $dir . '/blocked';
        mkdir($blocked, 0755, true);

        $zipPath = $dir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'gooddir/goodfile.bin' => 'good-content',
            'standalone.bin' => 'standalone-content',
            'blocked/third.bin' => 'third-content',
        ]);

        chmod($blocked, 0500);
        if (is_writable($blocked)) {
            chmod($blocked, 0755);
            self::markTestSkipped('Cannot simulate a failed extraction: the directory stays writable (running as root?).');
        }

        try {
            $this->runWithoutWarningLeak(fn(): string => (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $dir,
            ));
            self::fail('Expected a RuntimeException when the third entry cannot be extracted.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to extract entry "blocked/third.bin"', $e->getMessage());
        } finally {
            chmod($blocked, 0755);
        }

        self::assertFileDoesNotExist($zipPath);

        self::assertFileDoesNotExist($dir . '/gooddir/goodfile.bin');
        self::assertDirectoryDoesNotExist($dir . '/gooddir');
        self::assertFileDoesNotExist($dir . '/standalone.bin');
        self::assertSame(['.', '..', 'blocked'], scandir($dir));
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipRollsBackAndRemovesArchiveUnderThrowingErrorHandler(): void
    {
        $dir = $this->tempDir . '/dest';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/blocked', 'pre-existing file, not a directory');

        $zipPath = $dir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'good.bin' => 'good-content',
            'blocked/third.bin' => 'third-content',
        ]);

        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
        try {
            (new SfxDownloader())->fetch(
                'https://example.invalid/phpmicro.sfx.zip',
                $dir,
            );
            self::fail('Expected an exception when the second entry cannot be extracted.');
        } catch (\ErrorException $e) {
            self::assertStringContainsString('blocked/third.bin', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertFileDoesNotExist($zipPath);
        self::assertFileDoesNotExist($dir . '/good.bin');
        self::assertSame('pre-existing file, not a directory', file_get_contents($dir . '/blocked'));
    }

    /**
     * @param callable(): mixed $call
     */
    private function runWithoutWarningLeak(callable $call): void
    {
        $warningEmitted = false;
        set_error_handler(static function (int $errno) use (&$warningEmitted): bool {
            if ((error_reporting() & $errno) === 0) {
                return true;
            }

            $warningEmitted = true;

            return true;
        });
        try {
            $call();
        } finally {
            restore_error_handler();
            self::assertFalse($warningEmitted, 'fetch() must not leak a PHP warning; the extraction failure is reported through the exception.');
        }
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipLogsWarningWhenRollbackCannotRemoveExtractedFile(): void
    {
        $dir = $this->tempDir . '/dest';
        $locked = $dir . '/locked';
        mkdir($locked, 0755, true);
        file_put_contents($locked . '/goodfile.bin', 'pre-existing');
        $blocked = $dir . '/blocked';
        mkdir($blocked, 0755, true);

        $zipPath = $dir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'locked/goodfile.bin' => 'replaced-content',
            'blocked/newfile.bin' => 'new-content',
        ]);

        chmod($locked, 0500);
        chmod($blocked, 0500);
        if (is_writable($locked) || is_writable($blocked)) {
            chmod($locked, 0755);
            chmod($blocked, 0755);
            self::markTestSkipped('Cannot simulate a failed cleanup: the directory stays writable (running as root?).');
        }

        $logFile = $this->tempDir . '/error.log';
        ini_set('error_log', $logFile);
        try {
            try {
                $this->runWithoutWarningLeak(fn(): string => (new SfxDownloader())->fetch(
                    'https://example.invalid/phpmicro.sfx.zip',
                    $dir,
                ));
                self::fail('Expected a RuntimeException when the second entry cannot be extracted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Failed to extract entry "blocked/newfile.bin"', $e->getMessage());
            }
        } finally {
            ini_restore('error_log');
            chmod($locked, 0755);
            chmod($blocked, 0755);
        }

        $logContent = @file_get_contents($logFile);
        self::assertIsString($logContent, 'Failed to read error_log capture file.');
        self::assertStringContainsString(
            sprintf('Unable to remove extracted file "%s"', $locked . '/goodfile.bin'),
            $logContent,
            'The error_log should contain the cleanup warning for the file that stayed on disk.',
        );

        self::assertFileExists($locked . '/goodfile.bin');
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipLogsWarningWhenRollbackCannotRemoveExtractedDirectory(): void
    {
        $dir = $this->tempDir . '/dest';
        $locked = $dir . '/locked';
        mkdir($locked . '/empty', 0755, true);
        $blocked = $dir . '/blocked';
        mkdir($blocked, 0755, true);

        $zipPath = $dir . '/phpmicro.sfx.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create test zip: ' . $zipPath);
        }
        $zip->addEmptyDir('locked/empty');
        $zip->addFromString('blocked/newfile.bin', 'new-content');
        $zip->close();

        chmod($locked, 0500);
        chmod($blocked, 0500);
        if (is_writable($locked) || is_writable($blocked)) {
            chmod($locked, 0755);
            chmod($blocked, 0755);
            self::markTestSkipped('Cannot simulate a failed cleanup: the directory stays writable (running as root?).');
        }

        $logFile = $this->tempDir . '/error.log';
        ini_set('error_log', $logFile);
        try {
            try {
                $this->runWithoutWarningLeak(fn(): string => (new SfxDownloader())->fetch(
                    'https://example.invalid/phpmicro.sfx.zip',
                    $dir,
                ));
                self::fail('Expected a RuntimeException when the second entry cannot be extracted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Failed to extract entry "blocked/newfile.bin"', $e->getMessage());
            }
        } finally {
            ini_restore('error_log');
            chmod($locked, 0755);
            chmod($blocked, 0755);
        }

        $logContent = @file_get_contents($logFile);
        self::assertIsString($logContent, 'Failed to read error_log capture file.');
        self::assertStringContainsString(
            sprintf('Unable to remove extracted directory "%s"', $locked . '/empty'),
            $logContent,
            'The error_log should contain the cleanup warning for the empty directory that stayed on disk.',
        );

        self::assertDirectoryExists($locked . '/empty');
    }

    #[RequiresPhpExtension('zip')]
    public function testExtractZipLogsWarningWhenRollbackCannotRemoveExtractedSymlink(): void
    {
        $dir = $this->tempDir . '/dest';
        $locked = $dir . '/locked';
        mkdir($locked, 0755, true);
        file_put_contents($dir . '/real.bin', 'original');
        if (!@symlink('../real.bin', $locked . '/link.bin')) {
            self::markTestSkipped('symlink() is not available on this platform.');
        }
        $blocked = $dir . '/blocked';
        mkdir($blocked, 0755, true);

        $zipPath = $dir . '/phpmicro.sfx.zip';
        $this->createZipWithEntry($zipPath, [
            'locked/link.bin' => 'replaced-content',
            'blocked/newfile.bin' => 'new-content',
        ]);

        chmod($locked, 0500);
        chmod($blocked, 0500);
        if (is_writable($locked) || is_writable($blocked)) {
            chmod($locked, 0755);
            chmod($blocked, 0755);
            self::markTestSkipped('Cannot simulate a failed cleanup: the directory stays writable (running as root?).');
        }

        $logFile = $this->tempDir . '/error.log';
        ini_set('error_log', $logFile);
        try {
            try {
                $this->runWithoutWarningLeak(fn(): string => (new SfxDownloader())->fetch(
                    'https://example.invalid/phpmicro.sfx.zip',
                    $dir,
                ));
                self::fail('Expected a RuntimeException when the second entry cannot be extracted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Failed to extract entry "blocked/newfile.bin"', $e->getMessage());
            }
        } finally {
            ini_restore('error_log');
            chmod($locked, 0755);
            chmod($blocked, 0755);
        }

        $logContent = @file_get_contents($logFile);
        self::assertIsString($logContent, 'Failed to read error_log capture file.');
        self::assertStringContainsString(
            sprintf('Unable to remove extracted symlink "%s"', $locked . '/link.bin'),
            $logContent,
            'The error_log should contain the cleanup warning for the symlink that stayed on disk.',
        );

        self::assertTrue(is_link($locked . '/link.bin'));
    }

    public function testWriteStreamThrowsWhenWriteIsShort(): void
    {
        $shortWriteDir = $this->tempDir . '/shortwrite';
        mkdir($shortWriteDir, 0755, true);
        ShortWriteStreamWrapper::register($shortWriteDir);

        $in = fopen('php://memory', 'wb');
        self::assertIsResource($in);
        fwrite($in, 'downloaded-bytes');
        rewind($in);

        try {
            $this->invokePrivateSfxMethod('writeStream', $in, 'shortwrite://dest');
            self::fail('Expected a RuntimeException for the short write.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Failed to write to "shortwrite://dest" (short write).', $e->getMessage());
        }

        self::assertFileDoesNotExist($shortWriteDir . '/dest');
    }

    #[RequiresPhpExtension('zip')]
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

        self::assertFileDoesNotExist($zipPath);
    }

    #[RequiresPhpExtension('zip')]
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

    #[RequiresPhpExtension('zip')]
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

    #[DataProvider('nonHttpSchemeLocationProvider')]
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
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blocked cross-scheme redirect from HTTPS to "HTTP://attacker.example/sfx".');

        $this->invokePrivateSfxMethod('assertAllowedRedirect', 'HTTPS://mirror.example/php.sfx', 'HTTP://attacker.example/sfx');
    }

    #[DataProvider('nonHttpSchemeLocationProvider')]
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

    #[DataProvider('nonHttpSchemePathProvider')]
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

        self::assertFileDoesNotExist($this->tempDir . '/big');
    }

    public function testWriteStreamLogsWarningWhenPartialArtifactCannotBeRemoved(): void
    {
        $wrapDir = $this->tempDir . '/wrap';
        mkdir($wrapDir, 0755, true);
        FailingUnlinkStreamWrapper::register($this->tempDir);

        $url = sprintf('http://127.0.0.1:%d/big', self::$serverPort);
        $logFile = $this->tempDir . '/error.log';
        ini_set('error_log', $logFile);

        try {
            try {
                (new SfxDownloader(1024))->fetch($url, 'failunlink:///wrap');
                self::fail('Expected a RuntimeException for the oversized download.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('maximum allowed size of 1024 bytes', $e->getMessage());
            }
        } finally {
            ini_restore('error_log');
        }

        $logContent = @file_get_contents($logFile);
        self::assertIsString($logContent, 'Failed to read error_log capture file.');
        self::assertStringContainsString(
            'Unable to remove partial SFX download',
            $logContent,
            'The error_log should contain the removal-failure warning.',
        );

        self::assertFileExists($wrapDir . '/big');
    }

    public function testWriteStreamThrowsCleanExceptionWithoutWarningWhenDestinationCannotBeOpened(): void
    {
        $in = fopen('php://memory', 'rb');
        self::assertIsResource($in);

        $warningEmitted = false;
        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $errno) use (&$warningEmitted): bool {
            if ((error_reporting() & $errno) === 0) {
                return true;
            }
            $warningEmitted = true;

            return true;
        });
        try {
            $this->invokePrivateSfxMethod('writeStream', $in, $this->tempDir);
            self::fail('Expected a RuntimeException when the destination cannot be opened for writing.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Unable to open', $e->getMessage());
            self::assertStringContainsString('for writing', $e->getMessage());
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
        }

        self::assertFalse($warningEmitted, 'writeStream() must suppress the raw PHP warning on fopen() failure.');
    }

    public function testChecksumIsVerifiedBeforeZipExtraction(): void
    {
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

        $port = (int) self::$serverPort;
        $ready = Wait::until(static function () use ($port): bool {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                fclose($sock);

                return true;
            }

            return false;
        }, 5);

        if (!$ready) {
            throw new \RuntimeException(sprintf(
                'Test HTTP server did not start on port %d.',
                self::$serverPort,
            ));
        }
    }

    public function testLocateSfxEntryRemovesOnlyTrailingZipExtension(): void
    {
        $archivePath = $this->tempDir . '/my.zip.archive.zip';
        $expected = $this->tempDir . '/my.zip.archive';
        file_put_contents($expected, 'sfx');

        self::assertSame(
            $expected,
            $this->invokePrivateSfxMethod('locateSfxEntry', [], $archivePath, $this->tempDir),
        );
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

final class FailingUnlinkStreamWrapper
{
    private const PROTOCOL = 'failunlink';

    private static bool $registered = false;

    private static string $baseDir = '';

    /**
     * @var resource|null
     */
    public $context;

    /** @var resource|null */
    private $handle;

    public static function register(string $baseDir): void
    {
        self::$baseDir = rtrim($baseDir, '/');
        if (!self::$registered) {
            if (!@stream_wrapper_register(self::PROTOCOL, self::class)) {
                throw new \RuntimeException('Unable to register the failing-unlink stream wrapper.');
            }
            self::$registered = true;
        }
    }

    private function realPath(string $path): string
    {
        $inner = preg_replace('#^' . self::PROTOCOL . '://#', '', $path);

        return self::$baseDir . '/' . ltrim((string) $inner, '/');
    }

    /**
     * @return array<int, mixed>|false
     */
    public function url_stat(string $path): array|false
    {
        $real = $this->realPath($path);
        if (!is_file($real) && !is_dir($real)) {
            return false;
        }

        /** @var array<int, mixed>|false $stat */
        $stat = stat($real);

        return $stat;
    }

    public function unlink(): bool
    {
        return false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $real = $this->realPath($path);
        $handle = fopen($real, $mode);
        if (!is_resource($handle)) {
            return false;
        }
        $this->handle = $handle;
        if (($options & STREAM_USE_PATH) === STREAM_USE_PATH) {
            $openedPath = $real;
        }

        return true;
    }

    public function stream_read(int $count): string|false
    {
        return is_resource($this->handle) ? fread($this->handle, max(1, $count)) : false;
    }

    public function stream_write(string $data): int|false
    {
        return is_resource($this->handle) ? fwrite($this->handle, $data) : false;
    }

    public function stream_tell(): int|false
    {
        return is_resource($this->handle) ? ftell($this->handle) : false;
    }

    public function stream_eof(): bool
    {
        return !is_resource($this->handle) || feof($this->handle);
    }

    /**
     * @return array<int, mixed>|false
     */
    public function stream_stat(): array|false
    {
        if (!is_resource($this->handle)) {
            return false;
        }

        /** @var array<int, mixed>|false $stat */
        $stat = fstat($this->handle);

        return $stat;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return is_resource($this->handle) && fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}

final class ShortWriteStreamWrapper
{
    private const PROTOCOL = 'shortwrite';

    private static bool $registered = false;

    private static string $baseDir = '';

    /**
     * @var resource|null
     */
    public $context;

    /** @var resource|null */
    private $handle;

    public static function register(string $baseDir): void
    {
        self::$baseDir = rtrim($baseDir, '/');
        if (!self::$registered) {
            if (!@stream_wrapper_register(self::PROTOCOL, self::class)) {
                throw new \RuntimeException('Unable to register the short-write stream wrapper.');
            }
            self::$registered = true;
        }
    }

    private function realPath(string $path): string
    {
        $inner = preg_replace('#^' . self::PROTOCOL . '://#', '', $path);

        return self::$baseDir . '/' . ltrim((string) $inner, '/');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $real = $this->realPath($path);
        $handle = fopen($real, $mode);
        if (!is_resource($handle)) {
            return false;
        }
        $this->handle = $handle;
        if (($options & STREAM_USE_PATH) === STREAM_USE_PATH) {
            $openedPath = $real;
        }

        return true;
    }

    public function stream_write(string $data): int
    {
        $written = is_resource($this->handle) ? fwrite($this->handle, $data) : false;
        if ($written === false) {
            return 0;
        }

        return max(0, $written - 1);
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function unlink(string $path): bool
    {
        return @unlink($this->realPath($path));
    }

    /**
     * @return array<int, mixed>|false
     */
    public function url_stat(string $path): array|false
    {
        $real = $this->realPath($path);
        if (!is_file($real)) {
            return false;
        }

        /** @var array<int, mixed>|false $stat */
        $stat = stat($real);

        return $stat;
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
