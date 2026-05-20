<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildBinCommand;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Phar\BinaryComposer;
use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use CrazyGoat\WorkermanBundle\Phar\SfxDownloader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

final class BuildBinCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/build-bin-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
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

    public function testResolveBinPathUsesConfigDefaults(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());

        $path = BuildBinCommand::resolveBinPath($input, [
            'build_dir' => '/abs/build',
            'bin_filename' => 'app.bin',
        ], '/p');

        self::assertSame('/abs/build/app.bin', $path);
    }

    public function testResolveBinPathPrefersCliFilename(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput(['--filename' => 'custom.bin']);
        $input->bind($command->getDefinition());

        $path = BuildBinCommand::resolveBinPath($input, [
            'build_dir' => '/abs/build',
            'bin_filename' => 'app.bin',
        ], '/p');

        self::assertSame('/abs/build/custom.bin', $path);
    }

    public function testResolvePharPathRespectsPharFilenameOption(): void
    {
        $command = $this->createCommand();
        $input = new ArrayInput(['--phar-filename' => 'mid.phar']);
        $input->bind($command->getDefinition());

        $path = BuildBinCommand::resolvePharPath($input, [
            'build_dir' => '/abs/build',
            'phar_filename' => 'app.phar',
        ], '/p');

        self::assertSame('/abs/build/mid.phar', $path);
    }

    private function createCommand(): BuildBinCommand
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loader->setWorkermanConfig([]);
        $loader->setProcessConfig([]);
        $loader->setSchedulerConfig([]);
        $loader->setBuildConfig([
            'build_dir' => $this->tempDir . '/build',
            'kernel_class' => 'App\\Kernel',
            'phar_filename' => 'app.phar',
            'bin_filename' => 'app.bin',
            'sfx' => ['url' => null, 'file' => null, 'sha256' => null, 'allow_insecure' => false],
            'exclude_patterns' => [],
            'exclude_files' => [],
            'custom_ini' => null,
        ]);

        return new BuildBinCommand(
            $loader,
            new PharBuilder($this->tempDir, 'test'),
            new SfxDownloader(),
            new BinaryComposer(),
            $this->tempDir,
        );
    }
}
