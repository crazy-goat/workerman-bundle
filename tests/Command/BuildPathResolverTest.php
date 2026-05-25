<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildPathResolver;
use PHPUnit\Framework\TestCase;

final class BuildPathResolverTest extends TestCase
{
    private BuildPathResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new BuildPathResolver();
    }

    public function testResolveBuildDirUsesCliOptionWhenProvided(): void
    {
        $dir = $this->resolver->resolveBuildDir('/cli/path', ['build_dir' => '/cfg/path'], '/proj');

        self::assertSame('/cli/path', $dir);
    }

    public function testResolveBuildDirFallsBackToConfig(): void
    {
        $dir = $this->resolver->resolveBuildDir(null, ['build_dir' => '/cfg/path'], '/proj');

        self::assertSame('/cfg/path', $dir);
    }

    public function testResolveBuildDirFallsBackToProjectDirDefault(): void
    {
        $dir = $this->resolver->resolveBuildDir(null, [], '/proj');

        self::assertSame('/proj/build', $dir);
    }

    public function testResolveBuildDirRebasesRelativePath(): void
    {
        $dir = $this->resolver->resolveBuildDir('relative/path', ['build_dir' => '/cfg/path'], '/proj');

        self::assertSame('/proj/relative/path', $dir);
    }

    public function testResolveBuildDirRebasesRelativeConfigPath(): void
    {
        $dir = $this->resolver->resolveBuildDir(null, ['build_dir' => 'relative/path'], '/proj');

        self::assertSame('/proj/relative/path', $dir);
    }

    public function testResolveBuildDirThrowsOnEmptyConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('build_dir must be a non-empty string');

        $this->resolver->resolveBuildDir(null, ['build_dir' => ''], '/proj');
    }

    public function testResolveBuildDirThrowsOnEmptyCliWithNoConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('build_dir must be a non-empty string');

        $this->resolver->resolveBuildDir('', ['build_dir' => ''], '/proj');
    }

    public function testResolveBuildDirIgnoresEmptyCliOptionAndFallsBack(): void
    {
        $dir = $this->resolver->resolveBuildDir('', ['build_dir' => '/cfg/path'], '/proj');

        self::assertSame('/cfg/path', $dir);
    }

    public function testResolvePharPathUsesCliOptionWhenProvided(): void
    {
        $path = $this->resolver->resolvePharPath('custom.phar', '/build', ['phar_filename' => 'app.phar']);

        self::assertSame('/build/custom.phar', $path);
    }

    public function testResolvePharPathFallsBackToConfig(): void
    {
        $path = $this->resolver->resolvePharPath(null, '/build', ['phar_filename' => 'app.phar']);

        self::assertSame('/build/app.phar', $path);
    }

    public function testResolvePharPathFallsBackToDefault(): void
    {
        $path = $this->resolver->resolvePharPath(null, '/build', []);

        self::assertSame('/build/app.phar', $path);
    }

    public function testResolvePharPathWhenEmptyCliOptionFallsBackToConfig(): void
    {
        $path = $this->resolver->resolvePharPath('', '/build', ['phar_filename' => 'cfg.phar']);

        self::assertSame('/build/cfg.phar', $path);
    }

    public function testResolvePharPathWhenEmptyConfigFallsBackToDefault(): void
    {
        $path = $this->resolver->resolvePharPath('', '/build', ['phar_filename' => '']);

        self::assertSame('/build/app.phar', $path);
    }

    public function testResolveBinPathUsesCliOptionWhenProvided(): void
    {
        $path = $this->resolver->resolveBinPath('custom.bin', '/build', ['bin_filename' => 'app.bin']);

        self::assertSame('/build/custom.bin', $path);
    }

    public function testResolveBinPathFallsBackToConfig(): void
    {
        $path = $this->resolver->resolveBinPath(null, '/build', ['bin_filename' => 'app.bin']);

        self::assertSame('/build/app.bin', $path);
    }

    public function testResolveBinPathFallsBackToDefault(): void
    {
        $path = $this->resolver->resolveBinPath(null, '/build', []);

        self::assertSame('/build/app.bin', $path);
    }

    public function testResolveBinPathWhenEmptyCliOptionFallsBackToConfig(): void
    {
        $path = $this->resolver->resolveBinPath('', '/build', ['bin_filename' => 'cfg.bin']);

        self::assertSame('/build/cfg.bin', $path);
    }

    public function testResolveBinPathWhenEmptyConfigFallsBackToDefault(): void
    {
        $path = $this->resolver->resolveBinPath('', '/build', ['bin_filename' => '']);

        self::assertSame('/build/app.bin', $path);
    }
}
