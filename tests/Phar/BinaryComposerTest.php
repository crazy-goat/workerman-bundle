<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\BinaryComposer;
use PHPUnit\Framework\TestCase;

final class BinaryComposerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/binary-composer-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->tempDir . '/*') ?: []);
        rmdir($this->tempDir);
    }

    public function testComposeWithoutCustomIni(): void
    {
        $sfx = $this->tempDir . '/test.sfx';
        $phar = $this->tempDir . '/test.phar';
        $bin = $this->tempDir . '/test.bin';

        file_put_contents($sfx, 'MOCK_SFX');
        file_put_contents($phar, 'MOCK_PHAR');

        (new BinaryComposer())->compose($sfx, $phar, $bin);

        self::assertFileExists($bin);
        self::assertTrue(is_executable($bin));

        $content = (string) file_get_contents($bin);
        self::assertSame('MOCK_SFXMOCK_PHAR', $content);
        self::assertStringNotContainsString($this->getMagicBytes(), $content);
    }

    public function testComposeWithCustomIni(): void
    {
        $sfx = $this->tempDir . '/test.sfx';
        $phar = $this->tempDir . '/test.phar';
        $bin = $this->tempDir . '/test.bin';

        file_put_contents($sfx, 'SFX');
        file_put_contents($phar, 'PHAR');

        $customIni = "opcache.enable=1\nmemory_limit=256M";
        (new BinaryComposer())->compose($sfx, $phar, $bin, $customIni);

        $content = (string) file_get_contents($bin);
        self::assertStringContainsString($this->getMagicBytes(), $content);
        self::assertStringContainsString(pack('N', strlen($customIni)), $content);
        self::assertStringContainsString($customIni, $content);

        $sfxPos = strpos($content, 'SFX');
        $magicPos = strpos($content, $this->getMagicBytes());
        $pharPos = strpos($content, 'PHAR');
        self::assertIsInt($sfxPos);
        self::assertIsInt($magicPos);
        self::assertIsInt($pharPos);
        self::assertLessThan($magicPos, $sfxPos);
        self::assertLessThan($pharPos, $magicPos);
    }

    public function testComposeWithEmptyCustomIniSkipsHeader(): void
    {
        $sfx = $this->tempDir . '/test.sfx';
        $phar = $this->tempDir . '/test.phar';
        $bin = $this->tempDir . '/test.bin';

        file_put_contents($sfx, 'SFX');
        file_put_contents($phar, 'PHAR');

        (new BinaryComposer())->compose($sfx, $phar, $bin, '');

        $content = (string) file_get_contents($bin);
        self::assertStringNotContainsString($this->getMagicBytes(), $content);
    }

    public function testComposeFailsWhenSfxMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SFX file not found');

        (new BinaryComposer())->compose(
            $this->tempDir . '/missing.sfx',
            $this->tempDir . '/phar',
            $this->tempDir . '/out.bin',
        );
    }

    public function testComposeOverwritesExistingBinary(): void
    {
        $sfx = $this->tempDir . '/test.sfx';
        $phar = $this->tempDir . '/test.phar';
        $bin = $this->tempDir . '/test.bin';

        file_put_contents($sfx, 'NEW_SFX');
        file_put_contents($phar, 'NEW_PHAR');
        file_put_contents($bin, 'STALE');

        (new BinaryComposer())->compose($sfx, $phar, $bin);

        $content = (string) file_get_contents($bin);
        self::assertSame('NEW_SFXNEW_PHAR', $content);
        self::assertStringNotContainsString('STALE', $content);
    }

    private function getMagicBytes(): string
    {
        return (new \ReflectionClassConstant(BinaryComposer::class, 'MAGIC_BYTES'))->getValue();
    }
}
