<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class TestNamespaceConventionTest extends TestCase
{
    private const EXPECTED_PREFIX = 'CrazyGoat\\WorkermanBundle\\Test';
    private const WRONG_PREFIX = 'CrazyGoat\\WorkermanBundle\\Tests';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTestFiles(): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();
            $content = file_get_contents($path);

            if ($content === false || !str_contains($content, 'namespace ')) {
                continue;
            }

            yield str_replace(__DIR__ . '/', '', $path) => [$path, $content];
        }
    }

    /**
     * @dataProvider provideTestFiles
     */
    public function testNamespaceUsesTestNotTests(string $path, string $content): void
    {
        $this->assertStringNotContainsString(
            self::WRONG_PREFIX,
            $content,
            sprintf(
                'File %s uses "%s" namespace instead of "%s"',
                $path,
                self::WRONG_PREFIX,
                self::EXPECTED_PREFIX,
            ),
        );
    }

    /**
     * @dataProvider provideTestFiles
     */
    public function testNamespaceStartsWithExpectedPrefix(string $path, string $content): void
    {
        if (!preg_match('/^namespace\s+(.+);/m', $content, $matches)) {
            $this->markTestSkipped(sprintf('File %s has no namespace declaration', $path));
        }

        $namespace = $matches[1];

        $this->assertMatchesRegularExpression(
            '/^' . preg_quote(self::EXPECTED_PREFIX, '/') . '(\\\\|$)/',
            $namespace,
            sprintf(
                'File %s uses namespace "%s" which does not start with expected prefix "%s"',
                $path,
                $namespace,
                self::EXPECTED_PREFIX,
            ),
        );
    }

    /**
     * @dataProvider provideTestFiles
     */
    public function testClassIsAutoloadableWithCorrectNamespace(string $path, string $content): void
    {
        if (!preg_match('/^(?:final\s+)?(?:abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $content, $classMatch)) {
            $this->markTestSkipped(sprintf('File %s has no class, interface, trait, or enum declaration', $path));
        }

        if (!preg_match('/^namespace\s+(.+);/m', $content, $nsMatch)) {
            $this->markTestSkipped(sprintf('File %s has no namespace declaration', $path));
        }

        $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

        $this->assertTrue(
            class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn),
            sprintf(
                'Class %s declared in %s is not autoloadable with PSR-4 (autoload-dev maps "CrazyGoat\\\\WorkermanBundle\\\\Test\\\\" to "tests/")',
                $fqcn,
                $path,
            ),
        );
    }
}
