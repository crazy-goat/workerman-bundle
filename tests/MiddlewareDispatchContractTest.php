<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cross-platform middleware dispatch contract.
 *
 * Locks in the invariant that the middleware pipeline dispatches each
 * registered middleware exactly ONCE per incoming HTTP request. Issue #533
 * exposed a regression where middleware ran multiple times per request on
 * macOS, so this contract test guards the whole dispatch-count class of bugs
 * forever, regardless of OS.
 *
 * The implementation uses a dedicated dispatch-count server (port 9991)
 * configured with a single counting middleware
 * ({@see \CrazyGoat\WorkermanBundle\Test\App\DispatchCountMiddleware}).
 * On every __invoke the middleware increments a shared counter file under
 * flock() and exposes the current count via the X-Dispatch-Count response
 * header, which lets us assert the dispatch count after exactly one request.
 *
 * @see https://github.com/crazy-goat/workerman-bundle/issues/542
 * @see https://github.com/crazy-goat/workerman-bundle/issues/533
 */
final class MiddlewareDispatchContractTest extends WebTestCase
{
    private const SERVER_HOST = '127.0.0.1';
    private const SERVER_PORT = 9991;
    private const PROBE_PATH = '/dispatch_count_test';
    private const COUNTER_FILE = __DIR__ . '/../var/dispatch_count';

    protected function setUp(): void
    {
        // Reset the shared counter file so each test starts from zero. Using
        // truncate-then-write inside a microsecond race window is acceptable
        // because every test method here sends exactly one request; if any
        // test were to send concurrent requests the contract would still hold
        // per-request but the file would need locking -- the counting
        // middleware itself uses flock() for that reason.
        $dir = dirname(self::COUNTER_FILE);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            self::markTestSkipped(sprintf('Cannot create counter directory: %s', $dir));
        }

        file_put_contents(self::COUNTER_FILE, '0');

        $socket = @fsockopen(self::SERVER_HOST, self::SERVER_PORT, $errorCode, $errorMessage, 1);
        if ($socket === false) {
            self::markTestSkipped(
                sprintf(
                    'Live Workerman contract server is not running on %s:%d (%s)',
                    self::SERVER_HOST,
                    self::SERVER_PORT,
                    $errorMessage,
                ),
            );
        }
        fclose($socket);
    }

    protected function tearDown(): void
    {
        // Leave the counter file intact so cross-test inspection stays
        // possible, but normalize it back to zero so the next test starts
        // from the same baseline.
        @file_put_contents(self::COUNTER_FILE, '0');
    }

    /**
     * After exactly one HTTP request the dispatch middleware must have run
     * exactly once. The X-Dispatch-Count response header is the dispatched
     * post-increment value; the shared counter file on disk reflects the same
     * value because the middleware writes before sending the response.
     */
    public function testMiddlewareRunsExactlyOncePerRequest(): void
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->request('GET', sprintf('http://%s:%d%s', self::SERVER_HOST, self::SERVER_PORT, self::PROBE_PATH));

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

    /**
     * Two independent requests dispatched in sequence must produce exactly
     * two invocations of the middleware (1+1=2), never one (e.g. caching
     * response across requests) and never more than two (double dispatch).
     *
     * This is a stricter guarantee than the single-request case: it catches
     * reuse-of-Response objects across requests, which would still pass
     * {@see testMiddlewareRunsExactlyOncePerRequest()} on its own.
     */
    public function testMiddlewareRunsExactlyOncePerTwoSequentialRequests(): void
    {
        $client = new Client(['http_errors' => false]);

        for ($i = 1; $i <= 2; $i++) {
            $response = $client->request('GET', sprintf('http://%s:%d%s', self::SERVER_HOST, self::SERVER_PORT, self::PROBE_PATH));
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
