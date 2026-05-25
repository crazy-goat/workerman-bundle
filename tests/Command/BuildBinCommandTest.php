<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildPathResolver;
use PHPUnit\Framework\TestCase;

final class BuildBinCommandTest extends TestCase
{
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
