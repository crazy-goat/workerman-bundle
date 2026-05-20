<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Command;

use CrazyGoat\WorkermanBundle\Command\BuildPharCommand;
use CrazyGoat\WorkermanBundle\ConfigLoader;
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

    public function testBuildExclusionPatternWithDefaults(): void
    {
        $command = $this->createCommand();

        // Default exclusions should exclude common dev paths
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['.git/HEAD']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['tests/FooTest.php']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['docs/index.md']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['var/cache/test/file.php']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['.env']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['app.phar']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['app.bin']));

        // Source files should NOT be excluded
        self::assertFalse($this->invokeMethod($command, 'isExcluded', ['src/Command/BuildPharCommand.php']));
        self::assertFalse($this->invokeMethod($command, 'isExcluded', ['vendor/autoload.php']));
        self::assertFalse($this->invokeMethod($command, 'isExcluded', ['composer.json']));
        self::assertFalse($this->invokeMethod($command, 'isExcluded', ['config/services.yaml']));
        self::assertFalse($this->invokeMethod($command, 'isExcluded', ['vendor/symfony/var-dumper/Dumper/HtmlDumper.php']));
    }

    public function testBuildExclusionPatternWithCustomPatterns(): void
    {
        // Note: custom patterns are handled in execute() via CallbackFilterIterator
        // This test validates that isExcluded() correctly handles built-in defaults
        $command = $this->createCommand();

        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['build/output.phar']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['.github/workflows/ci.yml']));
        self::assertTrue($this->invokeMethod($command, 'isExcluded', ['phpunit.xml']));
    }

    public function testGenerateStubContainsRequiredElements(): void
    {
        $command = $this->createCommand();

        $stub = $this->invokeMethod($command, 'generateStub', [[
            'custom_ini' => null,
        ]]);

        // Stub must be a valid PHP script starting with shebang
        self::assertStringStartsWith("#!/usr/bin/env php", $stub);

        // Must set APP_RUNTIME for Workerman
        self::assertStringContainsString("APP_RUNTIME'", $stub);
        self::assertStringContainsString(\CrazyGoat\WorkermanBundle\Runtime::class, $stub);

        // Must set APP_CACHE_DIR and APP_LOG_DIR
        self::assertStringContainsString('APP_CACHE_DIR', $stub);
        self::assertStringContainsString('APP_LOG_DIR', $stub);

        // Must create runtime directories
        self::assertStringContainsString('mkdir(', $stub);
        self::assertStringContainsString('var/cache', $stub);
        self::assertStringContainsString('var/log', $stub);
        self::assertStringContainsString('var/run', $stub);

        // Must load external .env if it exists
        self::assertStringContainsString('.env', $stub);

        // Must load autoloader (using PHAR alias)
        self::assertStringContainsString("phar://app.phar/vendor/autoload.php", $stub);

        // Must define IN_PHAR constant
        self::assertStringContainsString("define('IN_PHAR', true)", $stub);

        // Must map PHAR alias
        self::assertStringContainsString("Phar::mapPhar('app.phar')", $stub);

        // Must create Console Application
        self::assertStringContainsString('Console\\Application', $stub);

        // Must have __HALT_COMPILER
        self::assertStringContainsString('__HALT_COMPILER();', $stub);
    }

    public function testGenerateStubUsesCorrectEnvironment(): void
    {
        $command = $this->createCommand('prod');

        $stub = $this->invokeMethod($command, 'generateStub', [[
            'custom_ini' => null,
        ]]);

        self::assertStringContainsString("'prod'", $stub);
    }

    public function testCommandFailsWhenPharReadonlyIsSet(): void
    {
        if (ini_get('phar.readonly') === '0' || ini_get('phar.readonly') === 'Off') {
            self::markTestSkipped('phar.readonly is Off — cannot test readonly error.');
        }

        $command = $this->createCommand();

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $statusCode = $command->run($input, $output);

        self::assertSame(1, $statusCode);
        self::assertStringContainsString('phar.readonly', $output->fetch());
    }

    public function testCommandCreatesBuildDirectory(): void
    {
        if (ini_get('phar.readonly') === '1' || ini_get('phar.readonly') === 'On') {
            self::markTestSkipped('phar.readonly is On — cannot test PHAR creation.');
        }

        // Create project structure
        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        mkdir($this->tempDir . '/config/packages', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php // stub');

        $configLoader = $this->createMock(ConfigLoader::class);
        $configLoader->method('getBuildConfig')->willReturn([
            'build_dir' => $this->tempDir . '/build',
            'kernel_class' => 'App\\Kernel',
            'phar_filename' => 'test.phar',
            'bin_filename' => 'test.bin',
            'bin_php_version' => null,
            'sfx' => ['url' => null, 'file' => null],
            'exclude_patterns' => [],
            'exclude_files' => [],
            'custom_ini' => null,
        ]);

        $command = new BuildPharCommand($configLoader, $this->tempDir, 'test');

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $statusCode = $command->run($input, $output);

        // Should have created the PHAR
        self::assertSame(0, $statusCode);
        self::assertDirectoryExists($this->tempDir . '/build');
        self::assertFileExists($this->tempDir . '/build/test.phar');

        // Clean up
        \Phar::unlinkArchive($this->tempDir . '/build/test.phar');
    }

    public function testFormatSize(): void
    {
        $command = $this->createCommand();

        // Test bytes
        self::assertSame('500 B', $this->invokeMethod($command, 'formatSize', [500]));
        self::assertSame('1 KB', $this->invokeMethod($command, 'formatSize', [1024]));
        self::assertSame('1 MB', $this->invokeMethod($command, 'formatSize', [1024 * 1024]));
        self::assertSame('1.5 MB', $this->invokeMethod($command, 'formatSize', [(int) (1024 * 1024 * 1.5)]));
    }

    private function createCommand(string $environment = 'test'): BuildPharCommand
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $configLoader->method('getBuildConfig')->willReturn([
            'build_dir' => $this->tempDir . '/build',
            'kernel_class' => 'App\\Kernel',
            'phar_filename' => 'app.phar',
            'bin_filename' => 'app.bin',
            'bin_php_version' => null,
            'sfx' => ['url' => null, 'file' => null],
            'exclude_patterns' => [],
            'exclude_files' => [],
            'custom_ini' => null,
        ]);

        return new BuildPharCommand($configLoader, '/fake/project', $environment);
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
