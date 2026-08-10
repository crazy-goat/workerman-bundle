<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Workerman\Connection\TcpConnection;

final class DefaultResponseStrategyTest extends TestCase
{
    private TcpConnection&\PHPUnit\Framework\MockObject\MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(TcpConnection::class);
    }

    public function testConvertReturnsWorkermanResponseWithContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response('Hello World', \Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/plain']);

        $workermanResponse = $strategy->convert($symfonyResponse, ['Content-Type' => ['text/plain']], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('Hello World', $workermanResponse->rawBody());
    }

    public function testConvertHandlesEmptyContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response();

        $workermanResponse = $strategy->convert($symfonyResponse, [], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('', $workermanResponse->rawBody());
    }

    public function testSmallBodyReturnsWorkermanResponse(): void
    {
        $strategy = new DefaultResponseStrategy();
        $body = str_repeat('a', 1024);
        $symfonyResponse = new Response($body);

        $this->connection->expects($this->never())
            ->method('send');

        $workermanResponse = $strategy->convert($symfonyResponse, [], $this->connection, '1.1');

        $this->assertSame(1024, strlen($workermanResponse->rawBody()));
    }

    public function testLargeBodyReturnsCompleteResponseWithoutManualChunking(): void
    {
        $strategy = new DefaultResponseStrategy();
        $body = str_repeat('a', 8192 * 5);
        $symfonyResponse = new Response($body);

        $this->connection
            ->expects($this->never())
            ->method('send');

        $workermanResponse = $strategy->convert($symfonyResponse, [], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame($body, $workermanResponse->rawBody());
        $this->assertStringContainsString('Content-Length: ' . strlen($body), (string) $workermanResponse);
    }

    public function testResponseWireFormatPreservesHeadersAndBody(): void
    {
        $strategy = new DefaultResponseStrategy();
        $body = str_repeat('body', 4096);
        $symfonyResponse = new Response($body, \Symfony\Component\HttpFoundation\Response::HTTP_CREATED, ['Content-Type' => 'text/plain', 'X-Test' => 'value']);

        $workermanResponse = $strategy->convert(
            $symfonyResponse,
            ['Content-Type' => 'text/plain', 'X-Test' => 'value'],
            $this->connection,
            '1.1',
        );

        $wire = (string) $workermanResponse;

        $this->assertStringStartsWith("HTTP/1.1 201 Created\r\n", $wire);
        $this->assertStringContainsString("Content-Type: text/plain\r\n", $wire);
        $this->assertStringContainsString("X-Test: value\r\n", $wire);
        $this->assertStringEndsWith("\r\n{$body}", $wire);
    }

    /**
     * An empty-body response carrying an application-provided Content-Length
     * (the HEAD contract from ResponseConverter, issue #643) must emit exactly
     * one Content-Length with the app value — not the 0 the transport
     * computes from the empty body, and not a duplicate.
     */
    public function testEmptyBodyWithAppContentLengthEmitsSingleAppValue(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response('', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Length' => '999',
            'X-Custom' => 'kept',
        ]);

        $workermanResponse = $strategy->convert(
            $symfonyResponse,
            ['Content-Length' => '999', 'X-Custom' => 'kept'],
            $this->connection,
            '1.1',
        );

        $wire = (string) $workermanResponse;

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'must emit exactly one Content-Length');
        $this->assertStringContainsString("Content-Length: 999\r\n", $wire);
        $this->assertStringNotContainsString('Content-Length: 0', $wire);
        $this->assertStringContainsString("X-Custom: kept\r\n", $wire);
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? '', 'empty body on the wire');
    }

    /**
     * A non-empty body with an app-provided Content-Length must keep the
     * transport-strip guarantee of issue #579: exactly one Content-Length,
     * computed from the real body.
     */
    public function testNonEmptyBodyWithAppContentLengthStripsIt(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response('hello world', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Length' => '5',
        ]);

        $workermanResponse = $strategy->convert(
            $symfonyResponse,
            ['Content-Length' => '5'],
            $this->connection,
            '1.1',
        );

        $wire = (string) $workermanResponse;

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'must emit exactly one Content-Length');
        $this->assertStringContainsString("Content-Length: 11\r\n", $wire);
        $this->assertStringNotContainsString("Content-Length: 5\r\n", $wire);
    }

    /**
     * A non-digit application Content-Length (or a header that failed
     * single-value flattening) must be stripped, never echoed onto the wire.
     */
    public function testInvalidAppContentLengthIsStripped(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response('', \Symfony\Component\HttpFoundation\Response::HTTP_OK);

        $workermanResponse = $strategy->convert(
            $symfonyResponse,
            ['Content-Length' => 'not-a-length'],
            $this->connection,
            '1.1',
        );

        $wire = (string) $workermanResponse;

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'must emit exactly one Content-Length');
        $this->assertStringContainsString("Content-Length: 0\r\n", $wire);
    }
}
