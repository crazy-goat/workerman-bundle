<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildPathResolver;
use CrazyGoat\WorkermanBundle\Command\BuildPharCommand;
use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class BuildPharCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/build-phar-test-' . uniqid();
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

    public function testResolvePharPathUsesConfigDefaults(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolvePharPath(null, '/abs/build', [
            'phar_filename' => 'app.phar',
        ]);

        self::assertSame('/abs/build/app.phar', $path);
    }

    public function testResolvePharPathPrefersCliOptions(): void
    {
        $resolver = new BuildPathResolver();
        $path = $resolver->resolvePharPath('custom.phar', '/cli/build', [
            'phar_filename' => 'app.phar',
        ]);

        self::assertSame('/cli/build/custom.phar', $path);
    }

    public function testResolvePharPathRelativeOutputDirIsRebasedOnProject(): void
    {
        $resolver = new BuildPathResolver();
        $buildDir = $resolver->resolveBuildDir('out', [
            'phar_filename' => 'a.phar',
        ], '/proj');
        $path = $resolver->resolvePharPath(null, $buildDir, [
            'phar_filename' => 'a.phar',
        ]);

        self::assertSame('/proj/out/a.phar', $path);
    }

    public function testResolvePharPathRejectsEmptyConfig(): void
    {
        $resolver = new BuildPathResolver();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('build_dir must be a non-empty string');
        $resolver->resolveBuildDir(null, ['build_dir' => ''], '/p');
    }

    public function testGenerateStubHonoursEnvironmentAndRuntimeDirOverride(): void
    {
        $stub = (new PharBuilder('/p', 'prod'))->generateStub(['kernel_class' => 'App\\Kernel'], 'app.phar');

        self::assertStringStartsWith("#!/usr/bin/env php", $stub);
        self::assertStringContainsString("define('IN_PHAR', true)", $stub);
        self::assertStringContainsString("Phar::mapPhar('app.phar')", $stub);
        self::assertStringContainsString("WORKERMAN_RUNTIME_DIR", $stub);
        self::assertStringContainsString("phar://app.phar/vendor/autoload.php", $stub);
        self::assertStringContainsString('Console\\Application', $stub);
        self::assertStringContainsString("'prod'", $stub);
        self::assertStringContainsString('__HALT_COMPILER();', $stub);
        // No silent error suppression in the stub
        self::assertStringNotContainsString('@mkdir', $stub);
    }

    public function testGenerateStubFallsBackToAppKernel(): void
    {
        $stub = (new PharBuilder('/p', 'test'))->generateStub([], 'app.phar');

        self::assertStringContainsString('new App\\Kernel', $stub);
    }

    public function testIsExcludedDefaults(): void
    {
        self::assertTrue(PharBuilder::isExcluded('.git/HEAD'));
        self::assertTrue(PharBuilder::isExcluded('tests/FooTest.php'));
        self::assertTrue(PharBuilder::isExcluded('docs/index.md'));
        self::assertTrue(PharBuilder::isExcluded('var/cache/test/file.php'));
        self::assertTrue(PharBuilder::isExcluded('.env'));
        self::assertTrue(PharBuilder::isExcluded('.env.local'));
        self::assertTrue(PharBuilder::isExcluded('app.phar'));
        self::assertTrue(PharBuilder::isExcluded('app.bin'));
        self::assertTrue(PharBuilder::isExcluded('build/output.phar'));
        self::assertTrue(PharBuilder::isExcluded('.github/workflows/ci.yml'));
        self::assertTrue(PharBuilder::isExcluded('phpunit.xml'));

        self::assertFalse(PharBuilder::isExcluded('src/Command/BuildPharCommand.php'));
        self::assertFalse(PharBuilder::isExcluded('vendor/autoload.php'));
        self::assertFalse(PharBuilder::isExcluded('composer.json'));
        self::assertFalse(PharBuilder::isExcluded('config/services.yaml'));
    }

    public function testCommandFailsWhenPharReadonlyIsSet(): void
    {
        if (!(bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is Off — cannot test readonly error.');
        }

        $command = $this->createCommand();

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $statusCode = $command->run($input, $output);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('phar.readonly', $output->fetch());
    }

    public function testCommandBuildsPharIntoConfiguredOutputDir(): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On — cannot test PHAR creation.');
        }

        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        mkdir($this->tempDir . '/config/packages', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php // stub');

        $configLoader = $this->makeConfigLoader([
            'build_dir' => $this->tempDir . '/build',
            'kernel_class' => 'App\\Kernel',
            'phar_filename' => 'test.phar',
            'bin_filename' => 'test.bin',
            'sfx' => ['url' => null, 'file' => null, 'sha256' => null, 'allow_insecure' => false],
            'exclude_patterns' => [],
            'exclude_files' => [],
            'custom_ini' => null,
        ]);

        $command = new BuildPharCommand($configLoader, new PharBuilder($this->tempDir, 'test'), new BuildPathResolver(), $this->tempDir);

        $statusCode = $command->run(new ArrayInput([]), new BufferedOutput());

        self::assertSame(0, $statusCode);
        self::assertFileExists($this->tempDir . '/build/test.phar');

        \Phar::unlinkArchive($this->tempDir . '/build/test.phar');
    }

    /**
     * @param mixed[]|null $buildConfig
     */
    private function makeConfigLoader(?array $buildConfig = null): ConfigLoader
    {
        $loader = new ConfigLoader($this->tempDir, $this->tempDir . '/cache', true);
        $loader->setWorkermanConfig([]);
        $loader->setProcessConfig([]);
        $loader->setSchedulerConfig([]);
        $loader->setBuildConfig($buildConfig ?? [
            'build_dir' => $this->tempDir . '/build',
            'kernel_class' => 'App\\Kernel',
            'phar_filename' => 'app.phar',
            'bin_filename' => 'app.bin',
            'sfx' => ['url' => null, 'file' => null, 'sha256' => null, 'allow_insecure' => false],
            'exclude_patterns' => [],
            'exclude_files' => [],
            'custom_ini' => null,
        ]);

        return $loader;
    }

    private function createCommand(): BuildPharCommand
    {
        return new BuildPharCommand($this->makeConfigLoader(), new PharBuilder($this->tempDir, 'test'), new BuildPathResolver(), $this->tempDir);
    }
}
