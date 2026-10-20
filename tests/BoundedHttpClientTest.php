<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Guards issue #1028: every real HTTP client in tests/ carries a connect
 * timeout and a total timeout, so a stalled response fails the test instead
 * of hanging the suite.
 */
final class BoundedHttpClientTest extends TestCase
{
    public function testCreateCarriesTimeouts(): void
    {
        $handler = new MockHandler([new Response(200)]);

        BoundedHttpClient::create([], $handler)->request('GET', 'http://127.0.0.1:8888/response_test');

        $options = $handler->getLastOptions();
        self::assertSame(1, $options['timeout']);
        self::assertSame(0.2, $options['connect_timeout']);
        self::assertFalse($options['http_errors']);
    }

    public function testCreateKeepsALongerTimeoutWhereATestNeedsIt(): void
    {
        $handler = new MockHandler([new Response(200)]);

        BoundedHttpClient::create(['timeout' => 20], $handler)->request('GET', 'http://127.0.0.1:9999/response_test_file_big');

        $options = $handler->getLastOptions();
        self::assertSame(20, $options['timeout']);
        self::assertSame(0.2, $options['connect_timeout']);
    }

    /**
     * A test or a lint check must fail when a client without timeouts is
     * added (#1028): scan every tests/ file for a bare client construction
     * and require timeouts, the shared factory, or a mock handler.
     */
    public function testNoUnboundedHttpClientInTests(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/new Client\(([^;]*);/', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $arguments) {
                $bounded = str_contains($arguments, 'BOUNDED_OPTIONS')
                    || str_contains($arguments, 'BoundedHttpClient::create')
                    || str_contains($arguments, "'handler'")
                    || (str_contains($arguments, 'connect_timeout') && str_contains($arguments, 'timeout'));
                if (!$bounded) {
                    $offenders[] = $file->getFilename() . ': ' . trim($arguments);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A real HTTP client needs connect_timeout and timeout (#1028): use BoundedHttpClient::create() instead of: '
            . implode('; ', $offenders),
        );
    }
}
