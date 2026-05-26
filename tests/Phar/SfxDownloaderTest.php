<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Exception\SfxExtractionException;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use PHPUnit\Framework\TestCase;

final class SfxDownloaderTest extends TestCase
{
    private string $tempDir;

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
}
