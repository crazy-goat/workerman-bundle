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

        $workermanResponse = $strategy->convert($symfonyResponse, ['Content-Type' => ['text/plain']], $this->connection);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('Hello World', $workermanResponse->rawBody());
    }

    public function testConvertHandlesEmptyContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response();

        $workermanResponse = $strategy->convert($symfonyResponse, [], $this->connection);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('', $workermanResponse->rawBody());
    }

    public function testSmallBodyReturnsWorkermanResponse(): void
    {
        $strategy = new DefaultResponseStrategy(2048 * 1024);
        $body = str_repeat('a', 1024);
        $symfonyResponse = new Response($body);

        $this->connection->expects($this->never())
            ->method('send');

        $workermanResponse = $strategy->convert($symfonyResponse, [], $this->connection);

        $this->assertSame(1024, strlen($workermanResponse->rawBody()));
    }

    public function testLargeBodySendsChunkedResponse(): void
    {
        $chunkSize = 2048;
        $strategy = new DefaultResponseStrategy($chunkSize);
        $chunkCount = 5;
        $sendChunkSize = max($chunkSize, 8192);
        $bodySize = $sendChunkSize * $chunkCount;
        $body = str_repeat('a', $bodySize);
        $symfonyResponse = new Response($body);

        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $expectedSendCount = 1 + $chunkCount;
        $this->connection
            ->expects($this->exactly($expectedSendCount))
            ->method('send')
            ->willReturnCallback(function (mixed $data, bool $raw = false) use (&$sendCalls): void {
                $sendCalls[] = ['data' => $data, 'raw' => $raw];
            });

        $workermanResponse = $strategy->convert($symfonyResponse, [], $this->connection);

        $this->assertSame('', $workermanResponse->rawBody());
        $this->assertSame(200, $workermanResponse->getStatusCode());

        $this->assertCount($expectedSendCount, $sendCalls);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $sendCalls[0]['data']);
        $this->assertStringContainsString("Content-Length: {$bodySize}", $sendCalls[0]['data']);
        $this->assertTrue($sendCalls[0]['raw']);

        for ($i = 1; $i <= $chunkCount; $i++) {
            $this->assertTrue($sendCalls[$i]['raw']);
            $this->assertSame($sendChunkSize, strlen((string) $sendCalls[$i]['data']));
        }

        $this->assertInstanceOf(\stdClass::class, $this->connection->context);
        $this->assertTrue($this->connection->context->responseSentDirectly);
    }
}
