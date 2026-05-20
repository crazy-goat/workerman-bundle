<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

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
        array_map(unlink(...), glob($this->tempDir . '/*') ?: []);
        rmdir($this->tempDir);
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
}
