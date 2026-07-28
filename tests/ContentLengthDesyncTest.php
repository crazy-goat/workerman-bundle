<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;

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
     * Path 1: BinaryFileResponse download — must emit exactly one
     * Content-Length and one Accept-Ranges.
     */
    public function testBinaryFileDownloadEmitsSingleContentLengthAndAcceptRanges(): void
    {
        $file = $this->createFixtureFile('download.txt', str_repeat('x', 5000));

        $converter = $this->createConverter();
        $binaryResponse = new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);
        $binaryResponse->prepare($this->createSymfonyRequest());

        $workermanResponse = $converter->convert($binaryResponse, $this->connection);

        // For file responses, Workerman's Http::encode() adds Content-Length
        // and Accept-Ranges via withHeaders(). We simulate that here to prove
        // no duplication occurs when the app headers were stripped centrally.
        $workermanResponse->withHeaders([
            'Content-Length' => 5000,
            'Accept-Ranges' => 'bytes',
        ]);

        $head = (string) $workermanResponse;

        $this->assertSame(
            1,
            substr_count($head, 'Content-Length:'),
            'File download must emit exactly one Content-Length header',
        );
        $this->assertSame(
            1,
            substr_count($head, 'Accept-Ranges:'),
            'File download must emit exactly one Accept-Ranges header',
        );
    }

    /**
     * Path 2: ranged BinaryFileResponse — must yield 206 with exactly one
     * Content-Length and one Content-Range.
     */
    public function testRangedBinaryFileResponseEmitsSingleContentLengthAndContentRange(): void
    {
        $file = $this->createFixtureFile('ranged.txt', str_repeat('x', 5000));

        $converter = $this->createConverter();
        $binaryResponse = new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);
        $binaryResponse->prepare($this->createSymfonyRequest(range: 'bytes=0-99'));

        $workermanResponse = $converter->convert($binaryResponse, $this->connection);

        // Simulate Http::encode() adding Content-Length, Accept-Ranges, and
        // setting Content-Range + status via header() (which overwrites).
        $workermanResponse->withHeaders([
            'Content-Length' => 100,
            'Accept-Ranges' => 'bytes',
        ]);
        $workermanResponse->header('Content-Range', 'bytes 0-99/5000');
        $workermanResponse->withStatus(206);

        $head = (string) $workermanResponse;

        $this->assertSame(1, substr_count($head, 'Content-Length:'));
        $this->assertSame(1, substr_count($head, 'Content-Range:'));
        $this->assertStringContainsString('206', $head);
    }

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
     * emit Content-Length at all.
     */
    public function testStreamedResponseEmitsNoContentLength(): void
    {
        $converter = $this->createConverter();

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'streamed content';
        });

        $converter->convert($streamedResponse, $this->connection);

        $sent = implode('', $this->getSent());
        $this->assertSame(0, substr_count($sent, 'Content-Length:'));
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
