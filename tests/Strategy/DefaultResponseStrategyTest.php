<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class DefaultResponseStrategyTest extends TestCase
{
    public function testConvertReturnsWorkermanResponseWithContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response('Hello World', \Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/plain']);

        $workermanResponse = $strategy->convert($symfonyResponse, ['Content-Type' => ['text/plain']]);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('Hello World', $workermanResponse->rawBody());
    }

    public function testConvertHandlesEmptyContent(): void
    {
        $strategy = new DefaultResponseStrategy();
        $symfonyResponse = new Response();

        $workermanResponse = $strategy->convert($symfonyResponse, []);

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('', $workermanResponse->rawBody());
    }
}
