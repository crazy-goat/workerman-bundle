<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Exception\NoResponseStrategyException;
use CrazyGoat\WorkermanBundle\Http\Response\HeaderNameNormalizer;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workerman\Connection\TcpConnection;

final class ResponseConverterTest extends TestCase
{
    private TcpConnection&\PHPUnit\Framework\MockObject\MockObject $connection;

    protected function setUp(): void
    {
        // Create a mock TcpConnection - we only need it passed through, not actually used
        $this->connection = $this->createMock(TcpConnection::class);
        HeaderNameNormalizer::resetCache();
    }

    public function testConvertUsesCorrectStrategy(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $regularResponse = new Response('regular');
        $workermanResponse = $converter->convert($regularResponse, $this->connection, '1.1', 'GET');

        $this->assertSame('regular', $workermanResponse->rawBody());
    }

    public function testConvertThrowsWhenNoStrategyFound(): void
    {
        $this->expectException(NoResponseStrategyException::class);
        $this->expectExceptionMessage('No strategy found');

        // Empty strategies array
        $converter = new ResponseConverter([]);
        $converter->convert(new Response(), $this->connection, '1.1', 'GET');
    }

    public function testConvertPreservesHeaders(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
            'X-Custom' => 'custom-value',
        ]);

        // Should not throw - headers are passed to strategy
        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('content', $workermanResponse->rawBody());
    }

    public function testConvertNormalizesHeaderNames(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        // Symfony stores some headers in lowercase internally
        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'text/html',
            'content-disposition' => 'attachment',
        ]);

        // Should not throw - headers are normalized and passed to strategy
        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('text/html', $workermanResponse->getHeader('Content-Type'));
        $this->assertSame('attachment', $workermanResponse->getHeader('Content-Disposition'));
    }

    public function testConvertHandlesIterableStrategies(): void
    {
        // Test with Generator (simulating DI tagged_iterator)
        $generator = function () {
            yield new DefaultResponseStrategy();
        };

        $converter = new ResponseConverter($generator());
        $response = $converter->convert(new Response('test'), $this->connection, '1.1', 'GET');

        $this->assertSame('test', $response->rawBody());
    }

    public function testConvertStreamedResponse(): void
    {
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $strategies = [new StreamedResponseStrategy(), new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'streamed content';
        });

        $workermanResponse = $converter->convert($streamedResponse, $this->connection, '1.1', 'GET');

        // Content is sent directly via $connection->send(), not buffered in response
        $this->assertSame('', $workermanResponse->rawBody());
        $this->assertSame(200, $workermanResponse->getStatusCode());
    }

    public function testConvertNormalizesIrregularHeaderNames(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'etag' => '"abc123"',
            'content-md5' => 'deadbeef',
            'www-authenticate' => 'Bearer',
            'dnt' => '1',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertSame('"abc123"', $workermanResponse->getHeader('ETag'));
        $this->assertSame('deadbeef', $workermanResponse->getHeader('Content-MD5'));
        $this->assertSame('Bearer', $workermanResponse->getHeader('WWW-Authenticate'));
        $this->assertSame('1', $workermanResponse->getHeader('DNT'));
    }

    public function testConvertNormalizesIrregularHeadersConsistentlyOnRepeatedCalls(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response1 = new Response('a', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'etag' => '"v1"',
        ]);
        $response2 = new Response('b', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'etag' => '"v2"',
        ]);

        $r1 = $converter->convert($response1, $this->connection, '1.1', 'GET');
        $r2 = $converter->convert($response2, $this->connection, '1.1', 'GET');

        // Both calls hit the static cache on the second invocation
        $this->assertSame('"v1"', $r1->getHeader('ETag'));
        $this->assertSame('"v2"', $r2->getHeader('ETag'));
    }

    public function testConvertPreservesRegularHeaderCasingAfterCaching(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        // First call populates the cache
        $response1 = new Response('a', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'text/html',
            'x-custom-one' => 'first',
        ]);
        $converter->convert($response1, $this->connection, '1.1', 'GET');

        // Second call on same instance hits cache entries
        $response2 = new Response('b', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'application/json',
            'x-custom-two' => 'second',
        ]);
        $workermanResponse = $converter->convert($response2, $this->connection, '1.1', 'GET');

        $this->assertSame('application/json', $workermanResponse->getHeader('Content-Type'));
        $this->assertSame('second', $workermanResponse->getHeader('X-Custom-Two'));
    }

    public function testConvertNormalizesMixedRegularAndIrregularHeaders(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'content-type' => 'text/plain',
            'etag' => '"abc"',
            'x-custom' => 'value',
            'dnt' => '0',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertSame('text/plain', $workermanResponse->getHeader('Content-Type'));
        $this->assertSame('"abc"', $workermanResponse->getHeader('ETag'));
        $this->assertSame('value', $workermanResponse->getHeader('X-Custom'));
        $this->assertSame('0', $workermanResponse->getHeader('DNT'));
    }

    /**
     * Transport-owned headers must be stripped centrally so the transport
     * (Workerman's Http::encode() / Response::__toString()) is the sole
     * authority on message framing. Otherwise array_merge_recursive in
     * Response::withHeaders() emits duplicates — see issue #579.
     *
     * @dataProvider transportHeadersProvider
     */
    public function testConvertStripsTransportOwnedHeaders(string $headerName): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            $headerName => 'should-be-stripped',
            'X-Custom' => 'kept',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertNull(
            $workermanResponse->getHeader($this->normalizeForAssertion($headerName)),
            "{$headerName} must be stripped by the converter so the transport owns it",
        );
        $this->assertSame('kept', $workermanResponse->getHeader('X-Custom'));
    }

    /**
     * @return list<array{string}>
     */
    public static function transportHeadersProvider(): array
    {
        return [
            ['Content-Length'],
            ['content-length'],
            ['Accept-Ranges'],
            ['accept-ranges'],
            ['Transfer-Encoding'],
            ['transfer-encoding'],
        ];
    }

    /**
     * Set-Cookie legitimately needs multiple values (one Set-Cookie header per
     * cookie), so flattening must not collapse it to a single string.
     */
    public function testConvertPreservesMultipleSetCookieValues(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK);
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('a', '1'));
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('b', '2'));

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $setCookie = $workermanResponse->getHeader('Set-Cookie');
        $this->assertIsArray($setCookie, 'Set-Cookie must keep its array shape to emit one line per cookie');
        $this->assertCount(2, $setCookie);
        $this->assertStringContainsString('a=1', (string) $setCookie[0]);
        $this->assertStringContainsString('b=2', (string) $setCookie[1]);
    }

    /**
     * Content-Range must NOT be stripped — Workerman sets it via header()
     * (which overwrites), and Symfony's value is correct for ranged responses.
     */
    public function testConvertPreservesContentRangeHeader(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_PARTIAL_CONTENT, [
            'Content-Range' => 'bytes 0-99/5000',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertSame('bytes 0-99/5000', $workermanResponse->getHeader('Content-Range'));
    }

    /**
     * A HEAD response legitimately carries the Content-Length the
     * corresponding GET would produce (RFC 9110 §9.3.2), so the
     * application-provided value must survive the transport-header strip for
     * HEAD requests (issue #643). Other transport-owned headers are still
     * stripped.
     */
    public function testConvertPreservesAppContentLengthForHeadRequest(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Length' => '999',
            'Accept-Ranges' => 'bytes',
            'Transfer-Encoding' => 'chunked',
            'X-Custom' => 'kept',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'HEAD');

        $wire = (string) $workermanResponse;

        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'HEAD must emit exactly one Content-Length');
        $this->assertStringContainsString("Content-Length: 999\r\n", $wire);
        $this->assertStringNotContainsString('Accept-Ranges:', $wire);
        $this->assertStringNotContainsString('Transfer-Encoding:', $wire);
        $this->assertStringContainsString("X-Custom: kept\r\n", $wire);
    }

    /**
     * The same response converted for a non-HEAD request must keep the
     * unconditional strip: the transport computes Content-Length from the
     * actual body (issue #579, #643).
     */
    public function testConvertStripsAppContentLengthForNonHeadRequest(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('hello world', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'Content-Length' => '999',
        ]);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertNull($workermanResponse->getHeader('Content-Length'));
    }

    /**
     * A header whose values are all null (Symfony can produce these) must be
     * dropped, not emitted as an empty line on the wire.
     */
    public function testConvertDropsAllNullHeaderValues(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            'X-Custom' => 'kept',
        ]);
        // Force a header with only null values, as Symfony can produce.
        $response->headers->set('X-Empty', null);

        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertNull($workermanResponse->getHeader('X-Empty'));
        $this->assertSame('kept', $workermanResponse->getHeader('X-Custom'));
    }

    private function normalizeForAssertion(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', strtolower($name))));
    }

    /**
     * Issue #574: the header-name normalisation cache must stay bounded no
     * matter how many distinct header names the application emits. Drive
     * 10 000 distinct names through extractHeaders() via convert() and
     * assert the cache never exceeds the cap.
     */
    public function testConvertHeaderNameCacheStaysBoundedUnderDistinctNameFlood(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        for ($i = 0; $i < 10000; ++$i) {
            $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
                'x-flood-header-' . $i => 'value',
            ]);
            $converter->convert($response, $this->connection, '1.1', 'GET');
        }

        $this->assertLessThanOrEqual(
            HeaderNameNormalizer::HEADER_CACHE_MAX_SIZE,
            count(HeaderNameNormalizer::cache()),
            'Header-name normalisation cache must stay at or below its cap',
        );
    }

    /**
     * Issue #574: an evicted name must normalise identically on a subsequent
     * request — a cache miss after eviction produces the same string as a
     * cache hit.
     */
    public function testConvertEvictedHeaderNameNormalisesIdenticallyOnReRequest(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $name = 'x-eviction-probe-header';
        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            $name => 'value',
        ]);
        $first = $converter->convert($response, $this->connection, '1.1', 'GET');
        $this->assertSame('value', $first->getHeader('X-Eviction-Probe-Header'));

        // Force eviction of every currently cached entry, including ours.
        $overflow = HeaderNameNormalizer::HEADER_CACHE_MAX_SIZE + 1;
        for ($i = 0; $i < $overflow; ++$i) {
            $flood = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
                'x-flood-evict-' . $i => 'value',
            ]);
            $converter->convert($flood, $this->connection, '1.1', 'GET');
        }
        $this->assertArrayNotHasKey('x-eviction-probe-header', HeaderNameNormalizer::cache());

        // Cache miss after eviction: same normalisation as the cache hit.
        $second = $converter->convert($response, $this->connection, '1.1', 'GET');
        $this->assertSame('value', $second->getHeader('X-Eviction-Probe-Header'));
    }

    /**
     * Issue #574: implausibly long header names must not occupy cache slots.
     */
    public function testConvertSkipsCachingImplausiblyLongHeaderNames(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $longName = 'x-' . str_repeat('a', 200);
        $response = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
            $longName => 'value',
        ]);
        $workermanResponse = $converter->convert($response, $this->connection, '1.1', 'GET');

        $this->assertSame('value', $workermanResponse->getHeader('X-' . ucfirst(str_repeat('a', 200))));
        // Symfony auto-adds Date/Content-Type, so the cache is not empty —
        // but no key longer than the plausibility limit may be present.
        $this->assertArrayNotHasKey($longName, HeaderNameNormalizer::cache());
        foreach (array_keys(HeaderNameNormalizer::cache()) as $key) {
            $this->assertLessThanOrEqual(128, strlen((string) $key));
        }
    }

    /**
     * Issue #574: corrections-table entries and multi-segment names must be
     * unaffected by the bounded cache — both on first pass (fresh cache) and
     * on repeat after the entries have been evicted.
     */
    public function testConvertCorrectionsAndMultiSegmentNamesSurviveCacheChurn(): void
    {
        $strategies = [new DefaultResponseStrategy()];
        $converter = new ResponseConverter($strategies);

        $buildResponse = static fn(): Response => new Response(
            'content',
            \Symfony\Component\HttpFoundation\Response::HTTP_OK,
            [
                'etag' => '"abc123"',
                'content-md5' => 'deadbeef',
                'www-authenticate' => 'Bearer',
                'dnt' => '1',
                'x-multi-segment-header-name' => 'multi',
            ],
        );

        $first = $converter->convert($buildResponse(), $this->connection, '1.1', 'GET');
        $this->assertSame('"abc123"', $first->getHeader('ETag'));
        $this->assertSame('deadbeef', $first->getHeader('Content-MD5'));
        $this->assertSame('Bearer', $first->getHeader('WWW-Authenticate'));
        $this->assertSame('1', $first->getHeader('DNT'));
        $this->assertSame('multi', $first->getHeader('X-Multi-Segment-Header-Name'));

        // Evict everything, then re-request: output must be identical.
        $overflow = HeaderNameNormalizer::HEADER_CACHE_MAX_SIZE + 1;
        for ($i = 0; $i < $overflow; ++$i) {
            $flood = new Response('content', \Symfony\Component\HttpFoundation\Response::HTTP_OK, [
                'x-flood-churn-' . $i => 'value',
            ]);
            $converter->convert($flood, $this->connection, '1.1', 'GET');
        }

        $second = $converter->convert($buildResponse(), $this->connection, '1.1', 'GET');
        $this->assertSame('"abc123"', $second->getHeader('ETag'));
        $this->assertSame('deadbeef', $second->getHeader('Content-MD5'));
        $this->assertSame('Bearer', $second->getHeader('WWW-Authenticate'));
        $this->assertSame('1', $second->getHeader('DNT'));
        $this->assertSame('multi', $second->getHeader('X-Multi-Segment-Header-Name'));
    }
}
