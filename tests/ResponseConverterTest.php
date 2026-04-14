<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Exception\NoResponseStrategyException;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;

final class ResponseConverterTest extends TestCase
{
    private TcpConnection&\PHPUnit\Framework\MockObject\MockObject $connection;

    protected function setUp(): void
    {
        // Create a mock TcpConnection - we only need it passed through, not actually used
        $this->connection = $this->createMock(TcpConnection::class);
    }

    public function testConvertUsesCorrectStrategy(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $regularResponse = new Response('regular');
        $workermanResponse = $converter->convert($regularResponse, $this->connection);

        $this->assertSame('regular', $workermanResponse->rawBody());
    }

    public function testConvertThrowsWhenNoStrategyFound(): void
    {
        $this->expectException(NoResponseStrategyException::class);
        $this->expectExceptionMessage('No strategy found');

        // Empty strategies array
        $converter = new ResponseConverter([]);
        $converter->convert(new Response(), $this->connection);
    }

    public function testConvertPreservesHeaders(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'X-Custom' => 'custom-value',
        ]);

        // Should not throw - headers are passed to strategy
        $workermanResponse = $converter->convert($response, $this->connection);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('content', $workermanResponse->rawBody());
    }

    public function testConvertNormalizesHeaderNames(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        // Symfony stores some headers in lowercase internally
        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'text/html',
            'content-disposition' => 'attachment',
        ]);

        // Should not throw - headers are normalized and passed to strategy
        $workermanResponse = $converter->convert($response, $this->connection);

        $this->assertSame(200, $workermanResponse->getStatusCode());
    }

    public function testConvertHandlesIterableStrategies(): void
    {
        // Test with Generator (simulating DI tagged_iterator)
        $generator = function () {
            yield new DefaultResponseStrategy();
        };

        $converter = new ResponseConverter($generator());
        $response = $converter->convert(new Response('test'), $this->connection);

        $this->assertSame('test', $response->rawBody());
    }

    public function testConvertStreamedResponse(): void
    {
        $strategies = [new StreamedResponseStrategy(), new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'streamed content';
        });

        $workermanResponse = $converter->convert($streamedResponse, $this->connection);

        $this->assertSame('streamed content', $workermanResponse->rawBody());
    }
}
