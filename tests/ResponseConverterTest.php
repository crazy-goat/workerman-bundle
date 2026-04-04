<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ResponseConverterTest extends TestCase
{
    public function testConvertUsesCorrectStrategy(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $regularResponse = new Response('regular');
        $workermanResponse = $converter->convert($regularResponse);
        
        $this->assertSame('regular', (string) $workermanResponse->rawBody());
    }

    public function testConvertThrowsWhenNoStrategyFound(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No strategy found');

        // Empty strategies array
        $converter = new ResponseConverter([]);
        $converter->convert(new Response());
    }

    public function testConvertPreservesHeaders(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new JsonResponse(['key' => 'value'], 200, ['X-Custom' => 'header']);
        $workermanResponse = $converter->convert($response);

        $this->assertSame(200, $workermanResponse->getStatusCode());
    }

    public function testConvertHandlesIterableStrategies(): void
    {
        // Test with Generator (simulating DI tagged_iterator)
        $generator = function () {
            yield new DefaultResponseStrategy();
        };

        $converter = new ResponseConverter($generator());
        $response = $converter->convert(new Response('test'));

        $this->assertSame('test', (string) $response->rawBody());
    }
}
