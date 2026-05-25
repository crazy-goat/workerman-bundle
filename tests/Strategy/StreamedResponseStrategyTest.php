<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;

final class StreamedResponseStrategyTest extends TestCase
{
    private TcpConnection&\PHPUnit\Framework\MockObject\MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(TcpConnection::class);
    }

    public function testSupportsReturnsTrueForStreamedResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertTrue($strategy->supports(new StreamedResponse()));
    }

    public function testSupportsReturnsFalseForRegularResponse(): void
    {
        $strategy = new StreamedResponseStrategy();

        $this->assertFalse($strategy->supports(new Response()));
    }

    public function testConvertSendsHeadersThenBodyChunk(): void
    {
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(function (mixed $data, bool $raw = false) use (&$sendCalls): void {
                $sendCalls[] = ['data' => $data, 'raw' => $raw];
            });

        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'chunk1';
            echo 'chunk2';
            echo 'chunk3';
        });

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame('', $workermanResponse->rawBody());
        $this->assertSame(200, $workermanResponse->getStatusCode());

        $this->assertCount(3, $sendCalls);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $sendCalls[0]['data']);
        $this->assertStringContainsString('Transfer-Encoding: chunked', $sendCalls[0]['data']);
        $this->assertTrue($sendCalls[0]['raw']);

        $expectedChunk = dechex(18) . "\r\nchunk1chunk2chunk3\r\n";
        $this->assertSame($expectedChunk, $sendCalls[1]['data']);
        $this->assertTrue($sendCalls[1]['raw']);

        $this->assertSame("0\r\n\r\n", $sendCalls[2]['data']);
        $this->assertTrue($sendCalls[2]['raw']);

        $this->assertInstanceOf(\stdClass::class, $this->connection->context);
        $this->assertTrue($this->connection->context->responseSentDirectly);
    }

    public function testConvertChunksOnExplicitFlush(): void
    {
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->exactly(5))
            ->method('send')
            ->willReturnCallback(function (mixed $data, bool $raw = false) use (&$sendCalls): void {
                $sendCalls[] = ['data' => $data, 'raw' => $raw];
            });

        $strategy = new StreamedResponseStrategy(1);

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'a';
            ob_flush();
            flush();
            echo 'b';
            ob_flush();
            flush();
            echo 'c';
            ob_flush();
            flush();
        });

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame('', $workermanResponse->rawBody());
        $this->assertSame(200, $workermanResponse->getStatusCode());

        $this->assertCount(5, $sendCalls);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $sendCalls[0]['data']);
        $this->assertStringContainsString('Transfer-Encoding: chunked', $sendCalls[0]['data']);
        $this->assertTrue($sendCalls[0]['raw']);

        $this->assertSame(dechex(1) . "\r\na\r\n", $sendCalls[1]['data']);
        $this->assertTrue($sendCalls[1]['raw']);
        $this->assertSame(dechex(1) . "\r\nb\r\n", $sendCalls[2]['data']);
        $this->assertTrue($sendCalls[2]['raw']);
        $this->assertSame(dechex(1) . "\r\nc\r\n", $sendCalls[3]['data']);
        $this->assertTrue($sendCalls[3]['raw']);

        $this->assertSame("0\r\n\r\n", $sendCalls[4]['data']);
        $this->assertTrue($sendCalls[4]['raw']);

        $this->assertInstanceOf(\stdClass::class, $this->connection->context);
        $this->assertTrue($this->connection->context->responseSentDirectly);
    }

    public function testConvertPreservesStatusCode(): void
    {
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(function (mixed $data, bool $raw = false) use (&$sendCalls): void {
                $sendCalls[] = ['data' => $data, 'raw' => $raw];
            });

        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'content';
        }, Response::HTTP_CREATED);

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame(201, $workermanResponse->getStatusCode());
        $this->assertStringStartsWith('HTTP/1.1 201 Created', $sendCalls[0]['data']);
    }

    public function testConvertPreservesHeaders(): void
    {
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(function (mixed $data, bool $raw = false) use (&$sendCalls): void {
                $sendCalls[] = ['data' => $data, 'raw' => $raw];
            });

        $headers = ['Content-Type' => ['text/plain'], 'X-Custom' => ['custom-value']];

        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'content';
        }, Response::HTTP_OK, $headers);

        $workermanResponse = $strategy->convert($streamedResponse, $headers, $this->connection);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertStringContainsString('Content-Type: text/plain', $sendCalls[0]['data']);
        $this->assertStringContainsString('X-Custom: custom-value', $sendCalls[0]['data']);
        $this->assertStringContainsString('Transfer-Encoding: chunked', $sendCalls[0]['data']);
    }

    public function testConvertHandlesEmptyContent(): void
    {
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (mixed $data, bool $raw = false) use (&$sendCalls): void {
                $sendCalls[] = ['data' => $data, 'raw' => $raw];
            });

        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
        });

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame('', $workermanResponse->rawBody());
        $this->assertSame(200, $workermanResponse->getStatusCode());

        $this->assertCount(2, $sendCalls);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $sendCalls[0]['data']);
        $this->assertStringContainsString('Transfer-Encoding: chunked', $sendCalls[0]['data']);
        $this->assertSame("0\r\n\r\n", $sendCalls[1]['data']);
    }

    public function testConvertCallbackExceptionCleansOB(): void
    {
        $this->connection->context = new \stdClass();

        $this->connection
            ->expects($this->atLeastOnce())
            ->method('send');

        $initialLevel = ob_get_level();

        $streamedResponse = new StreamedResponse(function (): never {
            echo 'partial';
            throw new \RuntimeException('intentional error');
        });

        $strategy = new StreamedResponseStrategy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('intentional error');

        $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame($initialLevel, ob_get_level(), 'OB level should be restored after exception');
    }
}
