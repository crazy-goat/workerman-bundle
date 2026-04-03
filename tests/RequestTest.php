<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Request class.
 *
 * @covers \CrazyGoat\WorkermanBundle\Http\Request
 */
final class RequestTest extends TestCase
{
    /**
     * Create a Request instance from HTTP buffer.
     */
    private function createRequest(string $method = 'GET', string $path = '/', array $headers = []): Request
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $buffer = "{$method} {$path} HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= implode("\r\n", $headerLines);
        $buffer .= "\r\n\r\n";

        return new Request($buffer);
    }

    /**
     * Test that Request can be created from HTTP buffer.
     */
    public function testCanBeCreatedFromBuffer(): void
    {
        $request = $this->createRequest('GET', '/test');

        $this->assertInstanceOf(Request::class, $request);
    }

    /**
     * Test that method is parsed correctly.
     */
    public function testMethodIsParsed(): void
    {
        $request = $this->createRequest('POST', '/test');

        $this->assertSame('POST', $request->method());
    }

    /**
     * Test that path is parsed correctly.
     */
    public function testPathIsParsed(): void
    {
        $request = $this->createRequest('GET', '/test/path');

        $this->assertSame('/test/path', $request->path());
    }

    /**
     * Test that headers are parsed correctly.
     */
    public function testHeadersAreParsed(): void
    {
        $request = $this->createRequest('GET', '/', ['X-Custom-Header' => 'test-value']);

        $this->assertSame('test-value', $request->header('x-custom-header'));
    }

    /**
     * Test withHeader adds a new header.
     *
     * NOTE: This test documents current behavior where withHeader mutates the object.
     * According to PSR-7, this should return a new immutable instance.
     * See ticket #38.
     */
    public function testWithHeaderAddsHeader(): void
    {
        $request = $this->createRequest('GET', '/');

        $result = $request->withHeader('X-New-Header', 'new-value');

        $this->assertSame('new-value', $request->header('x-new-header'));
        $this->assertSame($request, $result); // Currently returns same instance
    }

    /**
     * Test withHeader overwrites existing header.
     */
    public function testWithHeaderOverwritesExistingHeader(): void
    {
        $request = $this->createRequest('GET', '/', ['X-Test' => 'old-value']);

        $request->withHeader('X-Test', 'new-value');

        $this->assertSame('new-value', $request->header('x-test'));
    }

    /**
     * Test withHeader is case-insensitive for header names.
     */
    public function testWithHeaderIsCaseInsensitive(): void
    {
        $request = $this->createRequest('GET', '/');

        $request->withHeader('X-Mixed-Case', 'value1');
        $request->withHeader('x-mixed-case', 'value2');

        $this->assertSame('value2', $request->header('x-mixed-case'));
    }

    /**
     * Test that header lookup is case-insensitive.
     */
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = $this->createRequest('GET', '/', ['X-Custom-Header' => 'value']);

        // Header lookup should be case-insensitive per HTTP spec
        $this->assertSame('value', $request->header('x-custom-header'));
        $this->assertSame('value', $request->header('X-CUSTOM-HEADER'));
        $this->assertSame('value', $request->header('X-Custom-Header'));
    }

    /**
     * Test that non-existent header returns null.
     */
    public function testNonExistentHeaderReturnsNull(): void
    {
        $request = $this->createRequest('GET', '/');

        $this->assertNull($request->header('x-non-existent'));
    }

    /**
     * Test withHeader returns same instance (current behavior).
     *
     * This test documents the current behavior mentioned in ticket #38.
     * Ideally, this should return a new instance for immutability.
     */
    public function testWithHeaderReturnsSameInstance(): void
    {
        $request = $this->createRequest('GET', '/');

        $result = $request->withHeader('X-Test', 'value');

        // Currently returns $this, not a new instance
        $this->assertSame($request, $result);
    }

    /**
     * Test that withHeader mutates the original object.
     *
     * This test documents the mutation behavior mentioned in ticket #38.
     */
    public function testWithHeaderMutatesOriginalObject(): void
    {
        $request = $this->createRequest('GET', '/');

        $request->withHeader('X-Test', 'value');

        // Original object is modified
        $this->assertSame('value', $request->header('x-test'));
    }

    /**
     * Test query string is parsed correctly.
     */
    public function testQueryStringIsParsed(): void
    {
        $buffer = "GET /test?foo=bar&baz=qux HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $this->assertSame('/test?foo=bar&baz=qux', $request->uri());
        $this->assertSame('/test', $request->path());
    }

    /**
     * Test that raw buffer is accessible.
     */
    public function testRawBufferIsAccessible(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $this->assertSame($buffer, $request->rawBuffer());
    }
}
