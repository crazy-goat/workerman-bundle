<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class FinalClassTest extends TestCase
{
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

            if ($path === false) {
                continue;
            }

            $content = file_get_contents($path);

            if ($content === false) {
                continue;
            }

            if (!preg_match('/^(?:final\s+)?(?:abstract\s+)?(?:readonly\s+)?class\s+\w+\s+extends\s+(?:TestCase|WebTestCase)/m', $content)) {
                continue;
            }

            yield str_replace(__DIR__ . '/', '', $path) => [$path, $content];
        }
    }

    /**
     * @dataProvider provideTestFiles
     */
    public function testTestClassIsFinal(string $path, string $content): void
    {
        $isFinal = (bool) preg_match('/^final\s+(?:abstract\s+)?(?:readonly\s+)?class\s+\w+/m', $content);
        $isAbstract = (bool) preg_match('/^abstract\s+(?:final\s+)?(?:readonly\s+)?class\s+\w+/m', $content);

        if ($isAbstract) {
            $this->markTestSkipped(sprintf('Abstract class %s is not expected to be final', basename($path)));
        }

        $this->assertTrue(
            $isFinal,
            sprintf(
                'Test class in %s must be declared "final" to prevent accidental inheritance. Expected "final class ..."',
                $path,
            ),
        );
    }
}
