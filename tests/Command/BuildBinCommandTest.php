<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildPathResolver;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class BuildBinCommandTest extends TestCase
{
    public function testConfigureHasUnsafeNoChecksumOption(): void
    {
        $command = $this->createCommand();
        $option = $command->getDefinition()->getOption('unsafe-no-checksum');

        self::assertInstanceOf(\Symfony\Component\Console\Input\InputOption::class, $option);
        self::assertFalse($option->acceptValue());
    }

    public function testConfigureHasInsecureOption(): void
    {
        $command = $this->createCommand();
        $option = $command->getDefinition()->getOption('insecure');

        self::assertInstanceOf(\Symfony\Component\Console\Input\InputOption::class, $option);
        self::assertFalse($option->acceptValue());
    }

    private function createCommand(): \CrazyGoat\WorkermanBundle\Command\BuildBinCommand
    {
        $configLoader = new ConfigLoader('/tmp', '/tmp/cache/test', false);
        $configLoader->setBuildConfig([]);

        return new \CrazyGoat\WorkermanBundle\Command\BuildBinCommand(
            $configLoader,
            new \CrazyGoat\WorkermanBundle\Phar\PharBuilder('/tmp', 'test'),
            new \CrazyGoat\WorkermanBundle\Phar\SfxDownloader(),
            new \CrazyGoat\WorkermanBundle\Phar\BinaryComposer(),
            new BuildPathResolver(),
            new \CrazyGoat\WorkermanBundle\Phar\SfxSourceResolver(),
            '/tmp',
        );
    }

    public function testResolveBinPathUsesConfigDefaults(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolveBinPath(null, '/abs/build', [
            'bin_filename' => 'app.bin',
        ]);

        self::assertSame('/abs/build/app.bin', $path);
    }

    public function testResolveBinPathPrefersCliFilename(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolveBinPath('custom.bin', '/abs/build', [
            'bin_filename' => 'app.bin',
        ]);

        self::assertSame('/abs/build/custom.bin', $path);
    }

    public function testResolvePharPathRespectsPharFilenameOption(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolvePharPath('mid.phar', '/abs/build', [
            'phar_filename' => 'app.phar',
        ]);

        self::assertSame('/abs/build/mid.phar', $path);
    }
}
