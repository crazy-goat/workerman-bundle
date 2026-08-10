<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\HeadResponse;
use PHPUnit\Framework\TestCase;

final class HeadResponseTest extends TestCase
{
    public function testRegularSerializationReplacesTrailingContentLength(): void
    {
        $response = new HeadResponse(200, ['X-Custom' => 'kept'], 999);

        $wire = (string) $response;

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'must emit exactly one Content-Length');
        $this->assertStringContainsString("Content-Length: 999\r\n", $wire);
        $this->assertStringNotContainsString('Content-Length: 0', $wire);
        $this->assertStringContainsString("X-Custom: kept\r\n", $wire);
        $this->assertStringEndsWith("\r\n\r\n", $wire);
    }

    public function testEmptyHeadersFastPathReplacesContentLengthBeforeConnection(): void
    {
        // Workerman's empty-headers serialization branch emits
        // "Content-Length: N" followed by "Connection: keep-alive"; the value
        // must be rewritten there too (issue #643 regression guard).
        $response = new HeadResponse(200, [], 999);

        $wire = (string) $response;

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'must emit exactly one Content-Length');
        $this->assertStringContainsString("Content-Length: 999\r\nConnection: keep-alive\r\n\r\n", $wire);
        $this->assertStringNotContainsString('Content-Length: 0', $wire);
    }
}
