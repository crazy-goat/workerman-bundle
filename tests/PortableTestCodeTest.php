<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * The tests must run on Alpine (musl) PHP builds too, where some constants do not exist (#813).
 */
final class PortableTestCodeTest extends TestCase
{
    public function testTestsDoNotUseGlobBrace(): void
    {
        // The glob brace flag is not defined on musl: PHP 8 throws "Undefined constant". The name is split so this file does not match itself.
        $needle = 'GLOB_' . 'BRACE';
        $found = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = substr($file->getPathname(), \strlen(__DIR__) + 1);
            }
        }

        sort($found);
        self::assertSame([], $found, $needle . ' is undefined on Alpine/musl PHP; use plain glob() calls or a directory iterator');
    }
}
