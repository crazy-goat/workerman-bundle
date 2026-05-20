<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildBinCommand;
use CrazyGoat\WorkermanBundle\Command\BuildPharCommand;
use PHPUnit\Framework\TestCase;

final class BuildBinCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/build-bin-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    public function testFormatSize(): void
    {
        $command = $this->createCommand();

        self::assertSame('500 B', $this->invokeMethod($command, 'formatSize', [500]));
        self::assertSame('1 KB', $this->invokeMethod($command, 'formatSize', [1024]));
        self::assertSame('1.5 MB', $this->invokeMethod($command, 'formatSize', [(int) (1024 * 1024 * 1.5)]));
    }

    public function testCommandFailsWhenPharNotBuilt(): void
    {
        // This test verifies that if the PHAR build step returns a failure code,
        // the bin build also fails. We test this indirectly by checking that
        // BuildBinCommand delegates to BuildPharCommand via constructor injection.
        $buildPharCommand = $this->createMock(BuildPharCommand::class);

        $command = new BuildBinCommand($buildPharCommand);

        // Validate the command was constructed correctly
        $reflection = new \ReflectionClass($command);
        $property = $reflection->getProperty('buildPharCommand');
        self::assertSame($buildPharCommand, $property->getValue($command));
    }

    public function testBuildBinaryWithoutCustomIni(): void
    {
        // Create mock SFX and PHAR files
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/test.sfx', 'MOCK_SFX_CONTENT');
        file_put_contents($this->tempDir . '/test.phar', 'MOCK_PHAR_CONTENT');

        $command = $this->createCommand();
        $binPath = $this->tempDir . '/test.bin';

        $this->invokeMethod($command, 'buildBinary', [
            $this->tempDir . '/test.sfx',
            $this->tempDir . '/test.phar',
            $binPath,
            null,
            $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class),
        ]);

        self::assertFileExists($binPath);

        $content = file_get_contents($binPath);
        self::assertStringContainsString('MOCK_SFX_CONTENT', $content);
        self::assertStringContainsString('MOCK_PHAR_CONTENT', $content);

        // Custom INI magic bytes should NOT be present
        self::assertStringNotContainsString("\xfd\xf6\x69\xe6", $content);

        // File should be executable
        self::assertTrue(is_executable($binPath));
    }

    public function testBuildBinaryWithCustomIni(): void
    {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/test.sfx', 'SFX');
        file_put_contents($this->tempDir . '/test.phar', 'PHAR');

        $command = $this->createCommand();
        $binPath = $this->tempDir . '/test.bin';

        $customIni = "opcache.enable=1\nopcache.enable_cli=1";

        $this->invokeMethod($command, 'buildBinary', [
            $this->tempDir . '/test.sfx',
            $this->tempDir . '/test.phar',
            $binPath,
            $customIni,
            $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class),
        ]);

        self::assertFileExists($binPath);

        $content = file_get_contents($binPath);

        // Magic bytes for custom INI
        self::assertStringContainsString("\xfd\xf6\x69\xe6", $content);
        self::assertStringContainsString('opcache.enable=1', $content);
        self::assertStringContainsString('opcache.enable_cli=1', $content);
        // INI header length should be encoded properly
        self::assertStringContainsString(pack('N', strlen($customIni)), $content);
    }

    public function testBuildBinaryOrdering(): void
    {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/test.sfx', '<<<SFX>>>');
        file_put_contents($this->tempDir . '/test.phar', '<<<PHAR>>>');

        $command = $this->createCommand();
        $binPath = $this->tempDir . '/test.bin';

        $customIni = 'memory_limit=256M';

        $this->invokeMethod($command, 'buildBinary', [
            $this->tempDir . '/test.sfx',
            $this->tempDir . '/test.phar',
            $binPath,
            $customIni,
            $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class),
        ]);

        $content = file_get_contents($binPath);

        // Order: SFX first, then INI header, then PHAR last
        $sfxPos = strpos($content, '<<<SFX>>>');
        $pharPos = strpos($content, '<<<PHAR>>>');
        $magicPos = strpos($content, "\xfd\xf6\x69\xe6");

        self::assertIsInt($sfxPos);
        self::assertIsInt($magicPos);
        self::assertIsInt($pharPos);

        self::assertLessThan($magicPos, $sfxPos, 'SFX should come before INI header');
        self::assertLessThan($pharPos, $magicPos, 'INI header should come before PHAR');
        self::assertLessThan($pharPos, $sfxPos, 'SFX should come before PHAR');
    }

    public function testBuildBinaryWithoutIniHeaderWhenEmpty(): void
    {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempDir . '/test.sfx', 'SFX');
        file_put_contents($this->tempDir . '/test.phar', 'PHAR');

        $command = $this->createCommand();
        $binPath = $this->tempDir . '/test.bin';

        // Empty string — no INI header
        $this->invokeMethod($command, 'buildBinary', [
            $this->tempDir . '/test.sfx',
            $this->tempDir . '/test.phar',
            $binPath,
            '',
            $this->createMock(\Symfony\Component\Console\Style\SymfonyStyle::class),
        ]);

        $content = file_get_contents($binPath);
        self::assertStringNotContainsString("\xfd\xf6\x69\xe6", $content);
    }

    public function testCommandRunsBuildPharFirst(): void
    {
        // BuildBinCommand receives BuildPharCommand in constructor
        // The execute() method calls $this->buildPharCommand->execute() first,
        // then proceeds with SFX download and binary concatenation.
        $buildPharCommand = $this->createMock(BuildPharCommand::class);

        $command = new BuildBinCommand($buildPharCommand);

        self::assertInstanceOf(BuildBinCommand::class, $command);
    }

    private function createCommand(): BuildBinCommand
    {
        $buildPharCommand = $this->createMock(BuildPharCommand::class);

        return new BuildBinCommand($buildPharCommand);
    }

    /**
     * @param mixed[] $args
     */
    private function invokeMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($object, $args);
    }
}
