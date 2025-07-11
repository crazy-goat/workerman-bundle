<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\MultipartStream;

use function PHPUnit\Framework\assertIsArray;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestParametersTest extends KernelTestCase
{
    public function testHeaders(): void
    {
        $response = $this->createResponse('GET', [
            'headers' => [
                'test-header-1' => '9hnwk8xuxzt8qdc4wcsrr26uqqsuz8',
            ],
        ]);

        assertIsArray($response['headers']);
        assertIsArray($response['headers']['test-header-1']);

        $this->assertSame('9hnwk8xuxzt8qdc4wcsrr26uqqsuz8', $response['headers']['test-header-1'][0] ?? null);
    }

    public function testGetParameters(): void
    {
        $response = $this->createResponse('GET', [
            'query' => [
                'test-query-1' => '3kqz7kx610uewmcwyg44z',
            ],
        ]);
        assertIsArray($response['get']);

        $this->assertSame('3kqz7kx610uewmcwyg44z', $response['get']['test-query-1'] ?? null);
    }

    public function testPostParameters(): void
    {
        $response = $this->createResponse('POST', [
            'form_params' => [
                'test-post-1' => '88lc5paair2x',
            ],
        ]);
        assertIsArray($response['post']);

        $this->assertSame('88lc5paair2x', $response['post']['test-post-1'] ?? null);
    }

    public function testCookiesParameters(): void
    {
        $response = $this->createResponse('POST', [
            'cookies' => CookieJar::fromArray(
                cookies: [
                    'test-cookie-1' => '94bt5trqjfqe6seo0',
                ],
                domain: '127.0.0.1',
            ),
        ]);

        assertIsArray($response['cookies']);
        $this->assertSame('94bt5trqjfqe6seo0', $response['cookies']['test-cookie-1'] ?? null);
    }

    public function testFilesParameters(): void
    {
        $response = $this->createResponse('POST', [
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=OEZCxUAIiopEcaUw',
            ],
            'body' => new MultipartStream(
                elements: [
                    [
                        'name' => 'test-file-1',
                        'filename' => 'test1.txt',
                        'contents' => 'b8owxkeuhjeq3kqz7kx610uewmcwygap',
                    ],
                ],
                boundary: 'OEZCxUAIiopEcaUw',
            ),
        ]);

        assertIsArray($response['files']);

        $this->assertSame('test-file-1', $response['files'][0]['name'] ?? null);
        $this->assertSame('test1.txt', $response['files'][0]['filename'] ?? null);
        $this->assertSame('txt', $response['files'][0]['extension'] ?? null);
        $this->assertSame('b8owxkeuhjeq3kqz7kx610uewmcwygap', $response['files'][0]['content'] ?? null);
        $this->assertSame(32, $response['files'][0]['size'] ?? null);
    }

    public function testRawRequest(): void
    {
        $response = $this->createResponse('POST', [
            'body' => '88lc5paair2xwnidlz9r6k0rpggkmbhb2oqr0go0cxc',
        ]);

        $this->assertSame('88lc5paair2xwnidlz9r6k0rpggkmbhb2oqr0go0cxc', $response['raw_request']);
    }

    /**
     * @param mixed[] $options
     *
     * @return mixed[]
     */
    private function createResponse(string $method, array $options = []): array
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->request($method, 'http://127.0.0.1:8888/request_test', $options);

        $result = json_decode((string) $response->getBody(), true);
        assertIsArray($result);

        return $result;
    }
}
