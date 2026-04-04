<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResponseTest extends KernelTestCase
{
    public function testController(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test');

        $this->assertSame('hello from test controller', (string) $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNotFound(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test_not_exist');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFileServe(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:8888/readme.txt');

        $this->assertSame('Test for serve files option', (string) $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoFileServe(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/readme.txt');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testContentTypeJsonResponse(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_json');
        $this->assertSame(200, $response->getStatusCode());

        self::assertTrue($response->hasHeader('Content-Type'));
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
    }

    public function testBinaryFileResponse(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file');

        // Workerman may return 206 for range requests or 200 for normal requests
        $this->assertTrue(in_array($response->getStatusCode(), [200, 206], true));
        $this->assertStringContainsString('Test file download content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
        $this->assertStringContainsString('attachment', $response->getHeaderLine('content-disposition'));
    }

    public function testBinaryFileResponseWithRangeRequest(): void
    {
        $client = new Client(['http_errors' => false]);

        // Request only first 5 bytes
        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file', [
            'headers' => [
                'Range' => 'bytes=0-4',
            ],
        ]);

        // Should return 206 Partial Content for range requests
        $this->assertSame(206, $response->getStatusCode());
        // Body should be exactly 5 bytes
        $this->assertSame(5, strlen((string) $response->getBody()));
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testBinaryFileResponseWithDeleteAfterSend(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file_delete');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Delete me after download!', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
        // File should be deleted after download (handled by strategy)
    }

    public function testBinaryFileResponseWithTempFileObject(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_temp_file');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Temp file object content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }
}
