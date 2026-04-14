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
}
