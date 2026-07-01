<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Phar;

use CrazyGoat\WorkermanBundle\Phar\PharBuilder;
use CrazyGoat\WorkermanBundle\Phar\PharCapabilities;
use PHPUnit\Framework\TestCase;

final class PharBuilderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phar-builder-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/**/*', GLOB_BRACE) ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($dir);
    }

    public function testBuildIncludesSourceAndSkipsExcludedFiles(): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On — cannot build PHARs.');
        }

        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        mkdir($this->tempDir . '/tests', 0755, true);
        mkdir($this->tempDir . '/var/cache', 0755, true);
        file_put_contents($this->tempDir . '/src/App.php', '<?php');
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');
        file_put_contents($this->tempDir . '/tests/Skipped.php', '<?php');
        file_put_contents($this->tempDir . '/var/cache/garbage', 'x');
        file_put_contents($this->tempDir . '/.env', 'SECRET=1');
        file_put_contents($this->tempDir . '/keep.txt', 'keep');

        $pharPath = $this->tempDir . '/build/test.phar';
        (new PharBuilder($this->tempDir, 'test'))->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => [],
            'exclude_files' => [],
        ], $pharPath);

        self::assertFileExists($pharPath);

        $phar = new \Phar($pharPath);
        self::assertTrue(isset($phar['src/App.php']));
        self::assertTrue(isset($phar['vendor/autoload.php']));
        self::assertTrue(isset($phar['keep.txt']));
        self::assertFalse(isset($phar['tests/Skipped.php']));
        self::assertFalse(isset($phar['var/cache/garbage']));
        self::assertFalse(isset($phar['.env']));

        unset($phar);
        \Phar::unlinkArchive($pharPath);
    }

    public function testBuildHonoursCustomExcludePatterns(): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On.');
        }

        mkdir($this->tempDir . '/src', 0755, true);
        file_put_contents($this->tempDir . '/src/keep.php', '<?php');
        file_put_contents($this->tempDir . '/src/skip-me.php', '<?php');

        $pharPath = $this->tempDir . '/build/test.phar';
        (new PharBuilder($this->tempDir, 'test'))->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => ['#src/skip-#'],
            'exclude_files' => [],
        ], $pharPath);

        $phar = new \Phar($pharPath);
        self::assertTrue(isset($phar['src/keep.php']));
        self::assertFalse(isset($phar['src/skip-me.php']));

        unset($phar);
        \Phar::unlinkArchive($pharPath);
    }

    public function testBuildHonoursCustomExcludeFiles(): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On.');
        }

        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/keep.yaml', 'a: 1');
        file_put_contents($this->tempDir . '/config/secret.yaml', 'a: 1');

        $pharPath = $this->tempDir . '/build/test.phar';
        (new PharBuilder($this->tempDir, 'test'))->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => [],
            'exclude_files' => ['config/secret.yaml'],
        ], $pharPath);

        $phar = new \Phar($pharPath);
        self::assertTrue(isset($phar['config/keep.yaml']));
        self::assertFalse(isset($phar['config/secret.yaml']));

        unset($phar);
        \Phar::unlinkArchive($pharPath);
    }

    public function testStubContainsRequiredElements(): void
    {
        $stub = (new PharBuilder('/p', 'test'))->generateStub(['kernel_class' => 'App\\Kernel'], 'app.phar');

        self::assertStringStartsWith('#!/usr/bin/env php', $stub);
        self::assertStringContainsString("define('IN_PHAR', true)", $stub);
        self::assertStringContainsString("Phar::mapPhar('app.phar')", $stub);
        self::assertStringContainsString('WORKERMAN_RUNTIME_DIR', $stub);
        self::assertStringContainsString('phar://app.phar/vendor/autoload.php', $stub);
        self::assertStringContainsString('__HALT_COMPILER();', $stub);
        self::assertStringNotContainsString('@mkdir', $stub);
        self::assertStringContainsString('mkdir($dir, 0700, true)', $stub);
        self::assertStringNotContainsString('mkdir($dir, 0755', $stub);
    }

    /** @return iterable<array{string}> */
    public static function provideValidAliases(): iterable
    {
        yield 'simple name' => ['app.phar'];
        yield 'with version' => ['my-app-1.2.3.phar'];
        yield 'underscores' => ['my_app_v2.phar'];
        yield 'just name' => ['app'];
        yield 'numeric' => ['123.phar'];
        yield 'mixed' => ['Release_2.0-beta.phar'];
    }

    /** @return iterable<array{string}> */
    public static function provideInvalidAliases(): iterable
    {
        yield 'single quote' => ["foo'bar.phar"];
        yield 'backtick' => ['foo`bar.phar'];
        yield 'space' => ['foo bar.phar'];
        yield 'dollar brace' => ['foo${bar}.phar'];
        yield 'semicolon' => ['foo;bar.phar'];
        yield 'double quote' => ['foo"bar.phar'];
        yield 'parenthesis' => ['foo(bar).phar'];
        yield 'newline' => ["foo\nbar.phar"];
        yield 'null byte' => ["foo\0bar.phar"];
    }

    public function testGenerateStubRejectsEmptyAlias(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not be empty');

        (new PharBuilder('/p', 'test'))->generateStub(['kernel_class' => 'App\\Kernel'], '');
    }

    /** @dataProvider provideValidAliases */
    public function testGenerateStubAcceptsValidAliases(string $alias): void
    {
        $stub = (new PharBuilder('/p', 'test'))->generateStub(['kernel_class' => 'App\\Kernel'], $alias);

        self::assertStringContainsString("Phar::mapPhar('{$alias}')", $stub);
        self::assertStringContainsString("phar://{$alias}/vendor/autoload.php", $stub);
    }

    /** @dataProvider provideInvalidAliases */
    public function testGenerateStubRejectsInvalidAliases(string $alias): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains invalid characters');

        (new PharBuilder('/p', 'test'))->generateStub(['kernel_class' => 'App\\Kernel'], $alias);
    }

    /** @dataProvider provideInvalidAliases */
    public function testBuildRejectsInvalidAliases(string $alias): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On — cannot build PHARs.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains invalid characters');

        // Path whose basename contains invalid chars; build() derives alias from it
        $badPath = $this->tempDir . '/build/' . $alias;
        (new PharBuilder($this->tempDir, 'test'))->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => [],
            'exclude_files' => [],
        ], $badPath);
    }

    /** @return iterable<array{string}> */
    public static function provideValidKernelClasses(): iterable
    {
        yield 'simple class' => ['App\\Kernel'];
        yield 'with leading backslash' => ['\\App\\Kernel'];
        yield 'multi-level namespace' => ['App\\Http\\Kernel'];
        yield 'single class name' => ['Kernel'];
        yield 'with underscores' => ['My_App\\Kernel_V2'];
        yield 'numeric suffix' => ['App\\Kernel2024'];
        yield 'root namespace single' => ['\\Kernel'];
    }

    /** @return iterable<array{string}> */
    public static function provideInvalidKernelClasses(): iterable
    {
        yield 'semicolon' => ['App\\Kernel;'];
        yield 'single quote' => ["App\\Ker'nel"];
        yield 'double quote' => ['App\\Ker"nel'];
        yield 'parenthesis' => ['App\\Kernel()'];
        yield 'space' => ['App\\ Kernel'];
        yield 'dollar brace' => ['App\\${Kernel}'];
        yield 'backtick' => ['App\\Ker`nel'];
        yield 'angle brackets' => ['App\\Ker<nel>'];
        yield 'pipe' => ['App\\Ker|nel'];
        yield 'question mark' => ['App\\Ker?nel'];
        yield 'leading digit' => ['1App\\Kernel'];
    }

    /** @dataProvider provideValidKernelClasses */
    public function testGenerateStubAcceptsValidKernelClasses(string $kernelClass): void
    {
        $stub = (new PharBuilder('/p', 'test'))->generateStub([
            'kernel_class' => $kernelClass,
        ]);

        self::assertStringContainsString('new ' . $kernelClass . '(', $stub);
    }

    /** @dataProvider provideInvalidKernelClasses */
    public function testGenerateStubRejectsInvalidKernelClasses(string $kernelClass): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a valid PHP class name');

        (new PharBuilder('/p', 'test'))->generateStub([
            'kernel_class' => $kernelClass,
        ]);
    }

    /** @dataProvider provideInvalidKernelClasses */
    public function testBuildRejectsInvalidKernelClasses(string $kernelClass): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On — cannot build PHARs.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kernel class');

        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $pharPath = $this->tempDir . '/build/test.phar';
        (new PharBuilder($this->tempDir, 'test'))->build([
            'kernel_class' => $kernelClass,
            'exclude_patterns' => [],
            'exclude_files' => [],
        ], $pharPath);
    }

    public function testGenerateStubUsesDefaultKernelClassWhenNotConfigured(): void
    {
        $stub = (new PharBuilder('/p', 'test'))->generateStub([]);

        self::assertStringContainsString('new App\\Kernel(', $stub);
    }

    public function testBuildUsesInjectedCapabilities(): void
    {
        if ((bool) ini_get('phar.readonly')) {
            self::markTestSkipped('phar.readonly is On — cannot build PHARs.');
        }

        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/vendor', 0755, true);
        file_put_contents($this->tempDir . '/src/App.php', '<?php');
        file_put_contents($this->tempDir . '/vendor/autoload.php', '<?php');

        $pharPath = $this->tempDir . '/build/test.phar';
        (new PharBuilder($this->tempDir, 'test', new PharCapabilities(false, true)))->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => [],
            'exclude_files' => [],
        ], $pharPath);

        self::assertFileExists($pharPath);

        $phar = new \Phar($pharPath);
        self::assertTrue(isset($phar['src/App.php']));

        unset($phar);
        \Phar::unlinkArchive($pharPath);
    }

    public function testBuildThrowsWhenInjectedCapabilitiesReportReadOnly(): void
    {
        $builder = new PharBuilder($this->tempDir, 'test', new PharCapabilities(true, true));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('phar.readonly must be disabled');

        $builder->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => [],
            'exclude_files' => [],
        ], $this->tempDir . '/build/test.phar');
    }

    public function testBuildThrowsWhenInjectedCapabilitiesReportMissingExtension(): void
    {
        $builder = new PharBuilder($this->tempDir, 'test', new PharCapabilities(false, false));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Phar extension is required');

        $builder->build([
            'kernel_class' => 'App\\Kernel',
            'exclude_patterns' => [],
            'exclude_files' => [],
        ], $this->tempDir . '/build/test.phar');
    }
}
