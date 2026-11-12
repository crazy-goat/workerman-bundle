<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @see https://github.com/crazy-goat/workerman-bundle/issues/542
 * @see https://github.com/crazy-goat/workerman-bundle/issues/533
 */
final class MiddlewareDispatchContractTest extends WebTestCase
{
    use RequiresLiveServer;

    private const PROBE_PATH = '/dispatch_count_test';
    private const COUNTER_FILE = __DIR__ . '/../var/dispatch_count';

    protected function setUp(): void
    {
        $dir = dirname(self::COUNTER_FILE);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            self::failLiveServer(sprintf('Cannot create counter directory: %s', $dir));
        }

        file_put_contents(self::COUNTER_FILE, '0');

        $socket = @fsockopen(TestPorts::connectHost(), TestPorts::third(), $errorCode, $errorMessage, 1);
        if ($socket === false) {
            self::failLiveServer(sprintf(
                'Live Workerman contract server is not running on %s:%d (%s)',
                TestPorts::connectHost(),
                TestPorts::third(),
                $errorMessage,
            ));
        }
        fclose($socket);
    }

    protected function tearDown(): void
    {
        @file_put_contents(self::COUNTER_FILE, '0');
    }

    public function testMiddlewareRunsExactlyOncePerRequest(): void
    {
        $client = BoundedHttpClient::create();
        $response = $client->request('GET', TestPorts::url(TestPorts::third(), self::PROBE_PATH));

        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders();
        self::assertSame(200, $statusCode, sprintf(
            'Expected single-pass status 200, got %d. The known #533 regression presents as multiple dispatch passes producing 404 or wrong body.',
            $statusCode,
        ));

        self::assertArrayHasKey('X-Dispatch-Count', $headers, 'Middleware did not tag response with X-Dispatch-Count header');
        self::assertNotEmpty($headers['X-Dispatch-Count'], 'X-Dispatch-Count header must be present and non-empty');
        $dispatchCount = $headers['X-Dispatch-Count'][0];

        self::assertSame('1', $dispatchCount, sprintf(
            'Middleware contract violated: expected exactly 1 dispatch per request, observed %s. This is the #533 regression class (e.g. 3x on macOS).',
            $dispatchCount ?? 'null',
        ));

        self::assertSame('ok', (string) $response->getBody(), 'Response body should be the controller probe payload, not a duplicated or short-circuited body');

        $stored = (int) trim((string) file_get_contents(self::COUNTER_FILE));
        self::assertSame(1, $stored, sprintf(
            'Shared dispatch counter file should equal 1 after one request, got %d. A mismatch indicates either over-dispatch (#533) or the file was touched by an unrelated test.',
            $stored,
        ));
    }

    public function testMiddlewareRunsExactlyOncePerTwoSequentialRequests(): void
    {
        $client = BoundedHttpClient::create();

        for ($i = 1; $i <= 2; $i++) {
            $response = $client->request('GET', TestPorts::url(TestPorts::third(), self::PROBE_PATH));
            self::assertSame(200, $response->getStatusCode(), sprintf('Request #%d failed with status %d', $i, $response->getStatusCode()));
            self::assertSame((string) $i, $response->getHeaderLine('X-Dispatch-Count'), sprintf(
                'Request #%d should observe dispatch count %d, observed %s',
                $i,
                $i,
                $response->getHeaderLine('X-Dispatch-Count') ?: '<missing>',
            ));
        }

        $stored = (int) trim((string) file_get_contents(self::COUNTER_FILE));
        self::assertSame(2, $stored, sprintf('Shared counter should be 2 after two sequential requests, got %d', $stored));
    }
}
