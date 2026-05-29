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
        $this->assertSame(['text/html'], $workermanResponse->getHeader('Content-Type'));
        $this->assertSame(['attachment'], $workermanResponse->getHeader('Content-Disposition'));
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
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $strategies = [new StreamedResponseStrategy(), new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'streamed content';
        });

        $workermanResponse = $converter->convert($streamedResponse, $this->connection);

        // Content is sent directly via $connection->send(), not buffered in response
        $this->assertSame('', $workermanResponse->rawBody());
        $this->assertSame(200, $workermanResponse->getStatusCode());
    }

    public function testConvertNormalizesIrregularHeaderNames(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'etag' => '"abc123"',
            'content-md5' => 'deadbeef',
            'www-authenticate' => 'Bearer',
            'dnt' => '1',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection);

        $this->assertSame(['"abc123"'], $workermanResponse->getHeader('ETag'));
        $this->assertSame(['deadbeef'], $workermanResponse->getHeader('Content-MD5'));
        $this->assertSame(['Bearer'], $workermanResponse->getHeader('WWW-Authenticate'));
        $this->assertSame(['1'], $workermanResponse->getHeader('DNT'));
    }

    public function testConvertNormalizesIrregularHeadersConsistentlyOnRepeatedCalls(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response1 = new Response('a', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'etag' => '"v1"',
        ]);
        $response2 = new Response('b', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'etag' => '"v2"',
        ]);

        $r1 = $converter->convert($response1, $this->connection);
        $r2 = $converter->convert($response2, $this->connection);

        // Both calls hit the static cache on the second invocation
        $this->assertSame(['"v1"'], $r1->getHeader('ETag'));
        $this->assertSame(['"v2"'], $r2->getHeader('ETag'));
    }

    public function testConvertPreservesRegularHeaderCasingAfterCaching(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        // First call populates the cache
        $response1 = new Response('a', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'text/html',
            'x-custom-one' => 'first',
        ]);
        $converter->convert($response1, $this->connection);

        // Second call on same instance hits cache entries
        $response2 = new Response('b', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'application/json',
            'x-custom-two' => 'second',
        ]);
        $workermanResponse = $converter->convert($response2, $this->connection);

        $this->assertSame(['application/json'], $workermanResponse->getHeader('Content-Type'));
        $this->assertSame(['second'], $workermanResponse->getHeader('X-Custom-Two'));
    }

    public function testConvertNormalizesMixedRegularAndIrregularHeaders(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'text/plain',
            'etag' => '"abc"',
            'x-custom' => 'value',
            'dnt' => '0',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection);

        $this->assertSame(['text/plain'], $workermanResponse->getHeader('Content-Type'));
        $this->assertSame(['"abc"'], $workermanResponse->getHeader('ETag'));
        $this->assertSame(['value'], $workermanResponse->getHeader('X-Custom'));
        $this->assertSame(['0'], $workermanResponse->getHeader('DNT'));
    }
}
