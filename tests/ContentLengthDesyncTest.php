<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;

/**
 * Regression tests for issue #579: duplicate Content-Length on every file
 * download and on app-set Content-Length — response desync when values
 * conflict.
 *
 * Each test asserts on the actual bytes that would be written to the wire,
 * not on the Response object's header array, because the duplicate arises in
 * Workerman's encode()/__toString() at serialization time.
 */
final class ContentLengthDesyncTest extends TestCase
{
    private string $fixtureDir;
    private TcpConnection $connection;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/cld_' . uniqid();
        mkdir($this->fixtureDir, 0777, true);

        // Anonymous TcpConnection subclass that captures every raw send()
        // without touching a real socket. Mirrors the pattern used in
        // BinaryFileResponseStrategyTest. Captured bytes are exposed via the
        // public $captured property.
        $this->connection = new class extends TcpConnection {
            /** @var list<string> */
            public array $captured = [];

            public function __construct()
            {
                // Bypass parent constructor — no socket needed.
            }

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                if (is_string($sendBuffer)) {
                    $this->captured[] = $sendBuffer;
                }

                return true;
            }
        };
        $this->connection->context = new \stdClass();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixtureDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isDir()) {
                    rmdir($fileinfo->getRealPath());
                } else {
                    unlink($fileinfo->getRealPath());
                }
            }
            rmdir($this->fixtureDir);
        }
    }

    /**
     * Path 1: BinaryFileResponse download — see
     * testFileDownloadThroughEncodeEmitsSingleFramingHeaders for the
     * end-to-end wire test using Http::encode() that asserts exactly one
     * Content-Length and one Accept-Ranges on the wire plus the full body.
     */

    /**
     * Path 2: ranged BinaryFileResponse — see
     * testRangedFileDownloadThroughEncodeEmitsCorrectBodyRange for the
     * end-to-end wire test that asserts status 206, single Content-Length,
     * correct Content-Range value, and the requested byte range as body.
     */

    /**
     * Path 3: small body where the app sets a Content-Length that DISAGREES
     * with the actual body — must emit exactly one Content-Length matching
     * the bytes actually sent.
     */
    public function testSmallBodyWithDisagreeingAppContentLengthEmitsSingleCorrectValue(): void
    {
        $converter = $this->createConverter();

        $response = new Response('hello world', Response::HTTP_OK, [
            'Content-Length' => '5',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection);

        $head = (string) $workermanResponse;

        $this->assertSame(
            1,
            substr_count($head, 'Content-Length:'),
            'Small-body path must emit exactly one Content-Length',
        );
        // Workerman computes Content-Length from the actual body (11 bytes),
        // not from the stripped app-supplied value (5).
        $this->assertStringContainsString('Content-Length: 11', $head);
        $this->assertStringNotContainsString('Content-Length: 5', $head);
    }

    /**
     * Path 4: large body (> chunk size) — buildHeaderString writes its own
     * Content-Length. The app-supplied Content-Length must already be gone.
     */
    public function testLargeBodyPathEmitsSingleContentLength(): void
    {
        $converter = new ResponseConverter([
            new DefaultResponseStrategy(2048),
        ]);

        $body = str_repeat('a', 4096);
        $response = new Response($body, Response::HTTP_OK, [
            'Content-Length' => (string) strlen($body),
        ]);

        $converter->convert($response, $this->connection);

        $sent = implode('', $this->getSent());
        $this->assertSame(1, substr_count($sent, 'Content-Length:'));
        $this->assertStringContainsString('Content-Length: 4096', $sent);
    }

    /**
     * Path 5: StreamedResponse — uses Transfer-Encoding: chunked, must not
     * emit Content-Length at all, even when the application sets its own
     * Content-Length (which must be stripped so it cannot leak onto the wire).
     */
    public function testStreamedResponseEmitsNoContentLength(): void
    {
        $converter = $this->createConverter();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'streamed content';
        }, Response::HTTP_OK, [
            'Content-Length' => '999',
        ]);

        $converter->convert($streamedResponse, $this->connection);

        $sent = implode('', $this->getSent());
        $this->assertSame(0, substr_count($sent, 'Content-Length:'), 'Streamed path must not emit Content-Length even if the app set one');
        $this->assertSame(0, substr_count($sent, 'Content-Length: 999'), 'App-supplied Content-Length must not leak onto the wire');
        $this->assertSame(1, substr_count($sent, 'Transfer-Encoding: chunked'));
    }

    /**
     * Set-Cookie with multiple values must still emit one line per cookie
     * even after the single-value flattening transformation.
     */
    public function testMultipleSetCookiesEmitOneLinePerCookie(): void
    {
        $converter = $this->createConverter();

        $response = new Response('ok');
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('a', '1'));
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('b', '2'));

        $workermanResponse = $converter->convert($response, $this->connection);

        $head = (string) $workermanResponse;

        $this->assertSame(2, substr_count($head, 'Set-Cookie:'));
        $this->assertStringContainsString('a=1', $head);
        $this->assertStringContainsString('b=2', $head);
    }

    /**
     * The transport-header strip must be explicit and catch any future header
     * that encode() also sets. This guards the TRANSPORT_HEADERS list.
     */
    public function testTransportHeadersListedExplicitly(): void
    {
        $reflection = new \ReflectionClass(ResponseConverter::class);
        $constant = $reflection->getConstant('TRANSPORT_HEADERS');

        $this->assertSame(
            ['content-length', 'accept-ranges', 'transfer-encoding'],
            $constant,
            'TRANSPORT_HEADERS must explicitly list Content-Length, Accept-Ranges, Transfer-Encoding',
        );
    }

    /**
     * A response with no body must still emit exactly one Content-Length,
     * computed from the actual empty body. Covers the empty-body path that
     * HEAD/204/304 responses share (Workerman emits Content-Length: 0).
     */
    public function testEmptyBodyEmitsSingleZeroContentLength(): void
    {
        $converter = $this->createConverter();

        $response = new Response('', Response::HTTP_OK, [
            'Content-Length' => '999',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection);

        $head = (string) $workermanResponse;

        $this->assertSame(1, substr_count($head, 'Content-Length:'));
        $this->assertStringContainsString('Content-Length: 0', $head);
        $this->assertStringNotContainsString('Content-Length: 999', $head);
    }

    /**
     * A 304 Not Modified must not carry a body and must emit a consistent
     * Content-Length.
     */
    public function testNotModifiedEmitsSingleContentLength(): void
    {
        $converter = $this->createConverter();

        $response = new Response('', Response::HTTP_NOT_MODIFIED, [
            'Content-Length' => '12345',
            'ETag' => '"abc"',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection);

        $head = (string) $workermanResponse;

        $this->assertSame(1, substr_count($head, 'Content-Length:'));
        $this->assertSame(0, substr_count($head, 'Content-Length: 12345'));
        $this->assertStringContainsString('ETag: "abc"', $head);
    }

    /**
     * A HEAD request must emit no body and a consistent Content-Length.
     * Symfony's prepare() empties the body for HEAD; the transport then
     * computes Content-Length: 0 from the empty body.
     */
    public function testHeadRequestEmitsNoBodyAndSingleContentLength(): void
    {
        $converter = $this->createConverter();

        $response = new Response('hello world', Response::HTTP_OK, [
            'Content-Length' => '999',
        ]);
        $response->prepare(Request::create('/', \Symfony\Component\HttpFoundation\Request::METHOD_HEAD));

        $workermanResponse = $converter->convert($response, $this->connection);

        $output = (string) $workermanResponse;

        $this->assertSame(1, substr_count($output, 'Content-Length:'), 'HEAD must emit exactly one Content-Length');
        $this->assertStringContainsString('Content-Length: 0', $output);
        $this->assertStringNotContainsString('Content-Length: 999', $output);

        // No body after the head terminator.
        $parts = explode("\r\n\r\n", $output, 2);
        $this->assertSame('', $parts[1] ?? '', 'HEAD must not emit a body');
    }

    /**
     * End-to-end wire test for ranged file download using Workerman's actual
     * Http::encode(). Asserts on the bytes written to the connection: status
     * 206, exactly one Content-Length: 100, Content-Range: bytes 0-99/5000,
     * and the body is the requested 100-byte range from the fixture.
     */
    public function testRangedFileDownloadThroughEncodeEmitsCorrectBodyRange(): void
    {
        $fixtureContent = str_repeat('y', 5000);
        $file = $this->createFixtureFile('ranged_encode.txt', $fixtureContent);

        $converter = $this->createConverter();
        $binaryResponse = new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);
        $binaryResponse->prepare($this->createSymfonyRequest(range: 'bytes=0-99'));

        $workermanResponse = $converter->convert($binaryResponse, $this->connection);

        // Drive the real Workerman serializer. For files < 2MB, encode() sends
        // head + body in one send() call and returns ''.
        $return = Http::encode($workermanResponse, $this->connection);
        $this->assertSame('', $return, 'encode() should send inline for small files');

        $wire = implode('', $this->getSent());

        $this->assertStringContainsString('HTTP/1.1 206', $wire, 'Ranged response must be 206');
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'Exactly one Content-Length on the wire');
        $this->assertStringContainsString('Content-Length: 100', $wire);
        $this->assertSame(1, substr_count($wire, 'Content-Range:'), 'Exactly one Content-Range on the wire');
        $this->assertStringContainsString('Content-Range: bytes 0-99/5000', $wire, 'Content-Range value must match the requested range');

        // Body after the head terminator must be the requested 100-byte range.
        $parts = explode("\r\n\r\n", $wire, 2);
        $this->assertSame(
            substr($fixtureContent, 0, 100),
            $parts[1] ?? '',
            'Body must be the requested byte range from the fixture',
        );
    }

    /**
     * End-to-end wire test for plain file download using Workerman's actual
     * Http::encode(). Asserts exactly one Content-Length and one Accept-Ranges
     * on the wire, and the body is the full fixture content.
     */
    public function testFileDownloadThroughEncodeEmitsSingleFramingHeaders(): void
    {
        $fixtureContent = str_repeat('z', 5000);
        $file = $this->createFixtureFile('download_encode.txt', $fixtureContent);

        $converter = $this->createConverter();
        $binaryResponse = new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);
        $binaryResponse->prepare($this->createSymfonyRequest());

        $workermanResponse = $converter->convert($binaryResponse, $this->connection);

        $return = Http::encode($workermanResponse, $this->connection);
        $this->assertSame('', $return);

        $wire = implode('', $this->getSent());

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'Exactly one Content-Length on the wire');
        $this->assertStringContainsString('Content-Length: 5000', $wire);
        $this->assertSame(1, substr_count($wire, 'Accept-Ranges:'), 'Exactly one Accept-Ranges on the wire');
        $this->assertStringContainsString('Accept-Ranges: bytes', $wire);

        $parts = explode("\r\n\r\n", $wire, 2);
        $this->assertSame($fixtureContent, $parts[1] ?? '', 'Body must be the full fixture content');
    }

    private function createConverter(): ResponseConverter
    {
        return new ResponseConverter([
            new StreamedResponseStrategy(),
            new BinaryFileResponseStrategy(),
            new DefaultResponseStrategy(),
        ]);
    }

    private function createFixtureFile(string $name, string $content): string
    {
        $path = $this->fixtureDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function createSymfonyRequest(?string $range = null): \Symfony\Component\HttpFoundation\Request
    {
        $request = new \Symfony\Component\HttpFoundation\Request(
            server: ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost'],
        );

        if ($range !== null) {
            $request->headers->set('Range', $range);
        }

        return $request;
    }

    /**
     * @return list<string>
     */
    private function getSent(): array
    {
        // The anonymous TcpConnection subclass exposes a public $captured
        // property that is not declared on TcpConnection itself, so we read it
        // via reflection to satisfy PHPStan.
        $reflection = (new \ReflectionObject($this->connection))->getProperty('captured');

        /** @var list<string> $value */
        $value = $reflection->getValue($this->connection);

        return $value;
    }
}
