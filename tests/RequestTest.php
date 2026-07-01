<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Http\Request
 */
final class RequestTest extends TestCase
{
    /**
     * @param array<string, string> $headers
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

    public function testCanBeCreatedFromBuffer(): void
    {
        $request = $this->createRequest('GET', '/test');

        $this->assertInstanceOf(Request::class, $request);
    }

    public function testMethodIsParsed(): void
    {
        $request = $this->createRequest('POST', '/test');

        $this->assertSame('POST', $request->method());
    }

    public function testPathIsParsed(): void
    {
        $request = $this->createRequest('GET', '/test/path');

        $this->assertSame('/test/path', $request->path());
    }

    public function testHeadersAreParsed(): void
    {
        $request = $this->createRequest('GET', '/', ['X-Custom-Header' => 'test-value']);

        $this->assertSame('test-value', $request->header('x-custom-header'));
    }

    public function testWithHeaderAddsHeader(): void
    {
        $request = $this->createRequest('GET', '/');

        $result = @$request->withHeader('X-New-Header', 'new-value');

        $this->assertSame('new-value', $request->header('x-new-header'));
        $this->assertSame($request, $result);
    }

    public function testWithHeaderTriggersDeprecation(): void
    {
        $request = $this->createRequest('GET', '/');

        $deprecationMessage = null;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationMessage): bool {
            if ($errno === \E_USER_DEPRECATED) {
                $deprecationMessage = $errstr;
                return true;
            }
            return false;
        });

        $request->withHeader('X-Test', 'value');

        restore_error_handler();

        $this->assertNotNull($deprecationMessage, 'Expected E_USER_DEPRECATED to be triggered');
        $this->assertStringContainsString('withHeader() is deprecated', $deprecationMessage);
    }

    public function testSetHeaderAddsHeader(): void
    {
        $request = $this->createRequest('GET', '/');

        $result = $request->setHeader('X-New-Header', 'new-value');

        $this->assertSame('new-value', $request->header('x-new-header'));
        $this->assertSame($request, $result);
    }

    public function testSetHeaderOverwritesExistingHeader(): void
    {
        $request = $this->createRequest('GET', '/', ['X-Test' => 'old-value']);

        $request->setHeader('X-Test', 'new-value');

        $this->assertSame('new-value', $request->header('x-test'));
    }

    public function testSetHeaderIsCaseInsensitive(): void
    {
        $request = $this->createRequest('GET', '/');

        $request->setHeader('X-Mixed-Case', 'value1');
        $request->setHeader('x-mixed-case', 'value2');

        $this->assertSame('value2', $request->header('x-mixed-case'));
    }

    public function testSetHeaderReturnsSameInstance(): void
    {
        $request = $this->createRequest('GET', '/');

        $result = $request->setHeader('X-Test', 'value');

        $this->assertSame($request, $result);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = $this->createRequest('GET', '/', ['X-Custom-Header' => 'value']);

        $this->assertSame('value', $request->header('x-custom-header'));
        $this->assertSame('value', $request->header('X-CUSTOM-HEADER'));
        $this->assertSame('value', $request->header('X-Custom-Header'));
    }

    public function testNonExistentHeaderReturnsNull(): void
    {
        $request = $this->createRequest('GET', '/');

        $this->assertNull($request->header('x-non-existent'));
    }

    public function testWithHeaderReturnsSameInstance(): void
    {
        $request = $this->createRequest('GET', '/');

        $result = @$request->withHeader('X-Test', 'value');

        $this->assertSame($request, $result);
    }

    public function testWithHeaderMutatesOriginalObject(): void
    {
        $request = $this->createRequest('GET', '/');

        @$request->withHeader('X-Test', 'value');

        $this->assertSame('value', $request->header('x-test'));
    }

    public function testQueryStringIsParsed(): void
    {
        $buffer = "GET /test?foo=bar&baz=qux HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $this->assertSame('/test?foo=bar&baz=qux', $request->uri());
        $this->assertSame('/test', $request->path());
    }

    public function testQueryParametersAreAccessible(): void
    {
        $buffer = "GET /test?foo=bar&baz=qux&num=42 HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $this->assertSame('bar', $request->get('foo'));
        $this->assertSame('qux', $request->get('baz'));
        $this->assertSame('42', $request->get('num'));
        $this->assertNull($request->get('nonexistent'));
    }

    public function testRawBufferIsAccessible(): void
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $this->assertSame($buffer, $request->rawBuffer());
    }

    public function testSetHeaderDocumentsXForwardedSecurityWarning(): void
    {
        $reflection = new \ReflectionMethod(Request::class, 'setHeader');
        $docComment = $reflection->getDocComment();

        $this->assertIsString($docComment, 'setHeader() must have a PHPDoc comment');
        $this->assertStringContainsString('X-Forwarded', $docComment, 'setHeader() PHPDoc must warn about X-Forwarded-* re-injection');
        $this->assertStringContainsString('Forwarded', $docComment, 'setHeader() PHPDoc must warn about Forwarded header re-injection');
        $this->assertStringContainsString('trusted-proxy', $docComment, 'setHeader() PHPDoc must reference the trusted-proxy bypass risk');
    }

    public function testWithHeaderDocumentsXForwardedSecurityWarning(): void
    {
        $reflection = new \ReflectionMethod(Request::class, 'withHeader');
        $docComment = $reflection->getDocComment();

        $this->assertIsString($docComment, 'withHeader() must have a PHPDoc comment');
        $this->assertStringContainsString('X-Forwarded', $docComment, 'withHeader() PHPDoc must warn about X-Forwarded-* re-injection');
        $this->assertStringContainsString('Forwarded', $docComment, 'withHeader() PHPDoc must warn about Forwarded header re-injection');
        $this->assertStringContainsString('trusted-proxy', $docComment, 'withHeader() PHPDoc must reference the trusted-proxy bypass risk');
    }
}
