<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
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

        $this->assertFalse($strategy->supports(new \Symfony\Component\HttpFoundation\Response()));
    }

    public function testConvertCapturesStreamedContent(): void
    {
        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'chunk1';
            echo 'chunk2';
            echo 'chunk3';
        });

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame('chunk1chunk2chunk3', $workermanResponse->rawBody());
    }

    public function testConvertPreservesStatusCode(): void
    {
        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'content';
        }, \Symfony\Component\HttpFoundation\Response::HTTP_CREATED);

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame(201, $workermanResponse->getStatusCode());
    }

    public function testConvertPreservesHeaders(): void
    {
        $strategy = new StreamedResponseStrategy();

        $headers = ['Content-Type' => ['text/plain'], 'X-Custom' => ['custom-value']];

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'content';
        }, \Symfony\Component\HttpFoundation\Response::HTTP_OK, $headers);

        $workermanResponse = $strategy->convert($streamedResponse, $headers, $this->connection);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame(['text/plain'], $workermanResponse->getHeader('Content-Type'));
        $this->assertSame(['custom-value'], $workermanResponse->getHeader('X-Custom'));
    }

    public function testConvertHandlesEmptyContent(): void
    {
        $strategy = new StreamedResponseStrategy();

        $streamedResponse = new StreamedResponse(function (): void {
            // Echo nothing
        });

        $workermanResponse = $strategy->convert($streamedResponse, [], $this->connection);

        $this->assertSame('', $workermanResponse->rawBody());
    }

    public function testConvertCallbackExceptionCleansOB(): void
    {
        $initialLevel = ob_get_level();

        $streamedResponse = new StreamedResponse(function (): never {
            echo 'partial';
            throw new \RuntimeException('intentional error');
        });

        $strategy = new StreamedResponseStrategy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('intentional error');

        $strategy->convert($streamedResponse, [], $this->connection);

        // OB level should be restored after exception
        $this->assertSame($initialLevel, ob_get_level(), 'OB level should be restored after exception');
    }
}
