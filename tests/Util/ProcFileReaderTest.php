<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Util;

use CrazyGoat\WorkermanBundle\Util\ProcFileReader;
use PHPUnit\Framework\TestCase;

final class ProcFileReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/proc-file-reader-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->dir);
    }

    public function testReadReturnsContent(): void
    {
        \file_put_contents($this->dir . '/stat', "1234 (php) R 1\n");

        $this->assertSame("1234 (php) R 1\n", ProcFileReader::read($this->dir . '/stat'));
    }

    public function testReadReturnsNullForMissingFile(): void
    {
        $this->assertNull(ProcFileReader::read($this->dir . '/missing'));
    }

    public function testReadReturnsNullForEmptyFile(): void
    {
        \file_put_contents($this->dir . '/empty', '');

        $this->assertNull(ProcFileReader::read($this->dir . '/empty'));
    }
}
