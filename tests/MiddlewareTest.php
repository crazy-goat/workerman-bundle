<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;

use function PHPUnit\Framework\assertIsArray;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MiddlewareTest extends WebTestCase
{
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
     * @param mixed[] $options
     *
     * @return mixed[]
     */
    private function createResponse(string $method, array $options = []): array
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->request($method, 'http://127.0.0.1:9999/request_test', $options);

        $result = json_decode((string) $response->getBody(), true);
        assertIsArray($result);

        return [$result, $response->getHeaders()];
    }
}
