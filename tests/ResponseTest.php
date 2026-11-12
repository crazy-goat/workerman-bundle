<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResponseTest extends KernelTestCase
{
    public function testController(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'));

        $this->assertSame('hello from test controller', (string) $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNotFound(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test_not_exist'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFileServe(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/readme.txt'));

        $this->assertSame('Test for serve files option', (string) $response->getBody());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoFileServe(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/readme.txt'));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testContentTypeJsonResponse(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_json'));
        $this->assertSame(200, $response->getStatusCode());

        self::assertTrue($response->hasHeader('Content-Type'));
        self::assertSame('application/json', $response->getHeaderLine('content-type'));
    }

    public function testBinaryFileResponse(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Content-Range'));
        $this->assertStringContainsString('Test file download content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
        $this->assertStringContainsString('attachment', $response->getHeaderLine('content-disposition'));
    }

    public function testHeadBinaryFileResponse(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('HEAD', TestPorts::url(TestPorts::second(), '/response_test_file'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody(), 'HEAD must not send the file body');
        $this->assertSame(
            (string) filesize(__DIR__ . '/Fixtures/test_download.txt'),
            $response->getHeaderLine('Content-Length'),
            'HEAD Content-Length must be the file size',
        );
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
        $this->assertStringContainsString('attachment', $response->getHeaderLine('content-disposition'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'), 'HEAD must carry Accept-Ranges like the GET file path');
    }

    public function testBinaryFileResponseWithRangeRequest(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file'), [
            'headers' => [
                'Range' => 'bytes=0-4',
            ],
        ]);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame(5, strlen((string) $response->getBody()));
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testBigBinaryFileResponseIsSentInFull(): void
    {
        $client = BoundedHttpClient::create(['timeout' => 20]);

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file_big'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Content-Range'));
        $this->assertSame('3145728', $response->getHeaderLine('Content-Length'));
        $this->assertSame(str_repeat('0123456789abcdef', 196608), (string) $response->getBody());
    }

    public function testBigBinaryFileResponseWithClosedRange(): void
    {
        $client = BoundedHttpClient::create(['timeout' => 20]);

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file_big'), [
            'headers' => ['Range' => 'bytes=16-47'],
        ]);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 16-47/3145728', $response->getHeaderLine('Content-Range'));
        $this->assertSame('0123456789abcdef0123456789abcdef', (string) $response->getBody());
    }

    public function testBigBinaryFileResponseWithOpenRange(): void
    {
        $client = BoundedHttpClient::create(['timeout' => 20]);

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file_big'), [
            'headers' => ['Range' => 'bytes=100-'],
        ]);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 100-3145727/3145728', $response->getHeaderLine('Content-Range'));
        $this->assertSame(3145628, strlen((string) $response->getBody()));
    }

    public function testBinaryFileResponseWithOpenRange(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file'), [
            'headers' => ['Range' => 'bytes=5-'],
        ]);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame(
            substr((string) file_get_contents(__DIR__ . '/Fixtures/test_download.txt'), 5),
            (string) $response->getBody(),
        );
    }

    public function testBinaryFileResponseWithDeleteAfterSend(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file_delete'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Delete me after download!', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testStreamedBinaryFileResponse(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test_streamed_binary'));

        $this->assertContains($response->getStatusCode(), [200, 206]);
        $this->assertStringContainsString('Test file download content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testBinaryFileResponseWithTempFileObject(): void
    {
        if (!property_exists(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, 'tempFileObject')) {
            $this->markTestSkipped('BinaryFileResponse::$tempFileObject is not available in the installed symfony/http-foundation version.');
        }

        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::second(), '/response_test_temp_file'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Temp file object content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testNotModifiedBinaryFileResponseSendsNoBodyOnTheWire(): void
    {
        $wire = $this->rawGet('/response_test_file_not_modified');

        $this->assertStringStartsWith('HTTP/1.1 304 Not Modified', $wire);
        $this->assertStringContainsString('ETag: "test-download-etag"', $wire, 'a 304 repeats the cached response header fields');
        $this->assertStringContainsString('Content-Length: 0', $wire, 'the file length must not be advertised on a 304');
        $this->assertSame('', $this->bodyOf($wire), 'a 304 must not emit a body');
    }

    public function testNoContentBinaryFileResponseSendsNoBodyOnTheWire(): void
    {
        $wire = $this->rawGet('/response_test_file_no_content');

        $this->assertStringStartsWith('HTTP/1.1 204 No Content', $wire);
        $this->assertStringContainsString('Content-Length: 0', $wire);
        $this->assertSame('', $this->bodyOf($wire), 'a 204 must not emit a body');
    }

    public function testOkBinaryFileResponseStillSendsTheFileOnTheWire(): void
    {
        $wire = $this->rawGet('/response_test_file');

        $this->assertStringStartsWith('HTTP/1.1 200 OK', $wire);
        $this->assertSame(
            (string) file_get_contents(__DIR__ . '/Fixtures/test_download.txt'),
            $this->bodyOf($wire),
        );
    }

    private function rawGet(string $path): string
    {
        $socket = @fsockopen(TestPorts::connectHost(), TestPorts::second(), $errorCode, $errorMessage, 2);
        $this->assertIsResource($socket, sprintf(
            'Cannot connect to the test server on %s:%d (%s)',
            TestPorts::connectHost(),
            TestPorts::second(),
            $errorMessage,
        ));
        assert(is_resource($socket));

        try {
            $request = sprintf("GET %s HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n", $path);
            $this->assertSame(strlen($request), @fwrite($socket, $request), 'the whole request must reach the server');

            stream_set_timeout($socket, 10);
            $wire = stream_get_contents($socket);
            $this->assertIsString($wire);
        } finally {
            fclose($socket);
        }

        return $wire;
    }

    private function bodyOf(string $wire): string
    {
        $parts = explode("\r\n\r\n", $wire, 2);

        return $parts[1] ?? '';
    }
}
