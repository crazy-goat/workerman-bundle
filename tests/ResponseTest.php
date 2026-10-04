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

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Content-Range'));
        $this->assertStringContainsString('Test file download content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
        $this->assertStringContainsString('attachment', $response->getHeaderLine('content-disposition'));
    }

    /**
     * A HEAD request on a BinaryFileResponse must emit no body while carrying
     * the file size as Content-Length and the same header fields as the GET
     * (RFC 9110 §9.3.2, issue #683) — end-to-end through the real daemon.
     */
    public function testHeadBinaryFileResponse(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('HEAD', 'http://127.0.0.1:9999/response_test_file');

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

    /**
     * Issue #902: a file of 2 MB or more was sent as 206 with no body.
     */
    public function testBigBinaryFileResponseIsSentInFull(): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 20]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file_big');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Content-Range'));
        $this->assertSame('3145728', $response->getHeaderLine('Content-Length'));
        $this->assertSame(str_repeat('0123456789abcdef', 196608), (string) $response->getBody());
    }

    public function testBigBinaryFileResponseWithClosedRange(): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 20]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file_big', [
            'headers' => ['Range' => 'bytes=16-47'],
        ]);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 16-47/3145728', $response->getHeaderLine('Content-Range'));
        $this->assertSame('0123456789abcdef0123456789abcdef', (string) $response->getBody());
    }

    public function testBigBinaryFileResponseWithOpenRange(): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 20]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file_big', [
            'headers' => ['Range' => 'bytes=100-'],
        ]);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 100-3145727/3145728', $response->getHeaderLine('Content-Range'));
        $this->assertSame(3145628, strlen((string) $response->getBody()));
    }

    public function testBinaryFileResponseWithOpenRange(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file', [
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
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_file_delete');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Delete me after download!', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
        // File should be deleted after download (handled by strategy)
    }

    public function testStreamedBinaryFileResponse(): void
    {
        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test_streamed_binary');

        $this->assertContains($response->getStatusCode(), [200, 206]);
        $this->assertStringContainsString('Test file download content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    public function testBinaryFileResponseWithTempFileObject(): void
    {
        // The E2E daemon boots from this same vendor dir, so property_exists()
        // here reflects the Symfony version the controller runs against.
        if (!property_exists(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, 'tempFileObject')) {
            $this->markTestSkipped('BinaryFileResponse::$tempFileObject is not available in the installed symfony/http-foundation version.');
        }

        $client = new Client(['http_errors' => false]);

        $response = $client->request('GET', 'http://127.0.0.1:9999/response_test_temp_file');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Temp file object content', (string) $response->getBody());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('content-type'));
    }

    /**
     * A 304 on a BinaryFileResponse must not put the file on the wire
     * (RFC 9110 §15.4.5, issue #948) — end-to-end through the real daemon.
     *
     * The raw socket is not optional here: curl (and therefore Guzzle) never
     * reads a body after a 304, so a client-level assertion passes even while
     * the whole file follows the head. Reading the socket to EOF is the only
     * way to see the bytes the server actually writes.
     */
    public function testNotModifiedBinaryFileResponseSendsNoBodyOnTheWire(): void
    {
        $wire = $this->rawGet('/response_test_file_not_modified');

        $this->assertStringStartsWith('HTTP/1.1 304 Not Modified', $wire);
        $this->assertStringContainsString('ETag: "test-download-etag"', $wire, 'a 304 repeats the cached response header fields');
        $this->assertStringContainsString('Content-Length: 0', $wire, 'the file length must not be advertised on a 304');
        $this->assertSame('', $this->bodyOf($wire), 'a 304 must not emit a body');
    }

    /**
     * A 204 must not carry content at all (RFC 9110 §15.3.5, issue #948) —
     * raw socket, because curl also stops reading the body of a 204.
     */
    public function testNoContentBinaryFileResponseSendsNoBodyOnTheWire(): void
    {
        $wire = $this->rawGet('/response_test_file_no_content');

        $this->assertStringStartsWith('HTTP/1.1 204 No Content', $wire);
        $this->assertStringContainsString('Content-Length: 0', $wire);
        $this->assertSame('', $this->bodyOf($wire), 'a 204 must not emit a body');
    }

    /**
     * The GET file path next to it must keep working: the bodyless guard is
     * about prepare()'s maxlen = 0, not about file responses in general
     * (issue #948).
     */
    public function testOkBinaryFileResponseStillSendsTheFileOnTheWire(): void
    {
        $wire = $this->rawGet('/response_test_file');

        $this->assertStringStartsWith('HTTP/1.1 200 OK', $wire);
        $this->assertSame(
            (string) file_get_contents(__DIR__ . '/Fixtures/test_download.txt'),
            $this->bodyOf($wire),
        );
    }

    /**
     * Send one GET on a raw socket and read the response to EOF.
     *
     * `Connection: close` makes the daemon close the socket after the reply,
     * so the full response — including any body the server wrongly wrote — is
     * in the string this returns.
     */
    private function rawGet(string $path): string
    {
        $socket = @fsockopen('127.0.0.1', 9999, $errorCode, $errorMessage, 2);
        $this->assertIsResource($socket, sprintf('Cannot connect to the test server on 127.0.0.1:9999 (%s)', $errorMessage));
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

    /**
     * The bytes after the header terminator of a raw response.
     */
    private function bodyOf(string $wire): string
    {
        $parts = explode("\r\n\r\n", $wire, 2);

        return $parts[1] ?? '';
    }
}
