<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

use function PHPUnit\Framework\assertIsArray;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MiddlewareTest extends WebTestCase
{
    protected function setUp(): void
    {
        $socket = @fsockopen('127.0.0.1', 9999, $errorCode, $errorMessage, 1);
        if ($socket === false) {
            self::markTestSkipped(
                sprintf('Live Workerman server is not running on 127.0.0.1:9999 (%s)', $errorMessage),
            );
        }
        fclose($socket);
    }

    public function testHeaders(): void
    {
        [$response, $responseHeaders] = $this->createResponse('GET');
        assertIsArray($response['headers']);
        assertIsArray($response['headers']['x-first-middleware']);
        assertIsArray($response['headers']['x-second-middleware']);
        assertIsArray($response['headers']['x-third-middleware']);
        assertIsArray($response['headers']['x-test-middleware-request-order']);
        self::assertEquals(
            'X-First-Middleware|X-Second-Middleware|X-Third-Middleware|',
            $response['headers']['x-test-middleware-request-order'][0],
        );

        assertIsArray($responseHeaders['X-First-Middleware']);
        assertIsArray($responseHeaders['X-Second-Middleware']);
        assertIsArray($responseHeaders['X-Third-Middleware']);
    }

    /**
     * Verify middleware-added request headers do not leak across keep-alive requests (closes #533).
     */
    public function testMiddlewareRequestHeadersDoNotLeakAcrossRequests(): void
    {
        $client = $this->createHttpClient();

        [$firstResponse] = $this->createResponse('GET', [], $client);
        [$secondResponse] = $this->createResponse('GET', [], $client);

        assertIsArray($firstResponse['headers']);
        assertIsArray($secondResponse['headers']);

        $expectedOrder = 'X-First-Middleware|X-Second-Middleware|X-Third-Middleware|';

        self::assertSame($expectedOrder, $firstResponse['headers']['x-test-middleware-request-order'][0] ?? '');
        self::assertSame($expectedOrder, $secondResponse['headers']['x-test-middleware-request-order'][0] ?? '');
    }

    private function createHttpClient(): Client
    {
        return new Client([
            'http_errors' => false,
            'handler' => HandlerStack::create(),
            'headers' => ['Connection' => 'keep-alive'],
        ]);
    }

    /**
     * @param mixed[] $options
     *
     * @return mixed[]
     */
    private function createResponse(string $method, array $options = [], ?Client $client = null): array
    {
        $client ??= $this->createHttpClient();
        $response = $client->request($method, 'http://127.0.0.1:9999/request_test', $options);

        $result = json_decode((string) $response->getBody(), true);
        assertIsArray($result);

        return [$result, $response->getHeaders()];
    }
}
