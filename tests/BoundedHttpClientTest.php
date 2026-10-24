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
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unboundedOverrideProvider(): array
    {
        return [
            'zero timeout' => [['timeout' => 0]],
            'null timeout' => [['timeout' => null]],
            'zero connect_timeout' => [['connect_timeout' => 0]],
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @dataProvider unboundedOverrideProvider
     */
    public function testCreateRejectsAnOverrideThatRemovesABound(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BoundedHttpClient::create($options);
    }

    /**
     * A test or a lint check must fail when a client without timeouts is
     * added (#1028): scan every tests/ file for a bare client construction
     * and require both timeouts (or the shared factory); a raw
     * curl_init() client needs CURLOPT_CONNECTTIMEOUT and CURLOPT_TIMEOUT.
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
            // A raw curl client needs both curl timeouts in the same file.
            if (str_contains($source, 'curl_init(')
                && (!str_contains($source, 'CURLOPT_CONNECTTIMEOUT') || !str_contains($source, 'CURLOPT_TIMEOUT'))) {
                $offenders[] = $file->getFilename() . ': curl_init() without CURLOPT_CONNECTTIMEOUT and CURLOPT_TIMEOUT';
            }
            // The factory itself is the one place that builds a client
            // from the shared options; testCreateCarriesTimeouts covers it.
            if ($file->getFilename() === 'BoundedHttpClient.php') {
                continue;
            }
            if (preg_match_all('/new\s+\\\\?(?:GuzzleHttp\\\\)?Client\s*\(([^;]*);/', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $arguments) {
                // Quoted keys: 'timeout' is a substring of connect_timeout,
                // so a bare str_contains() would accept connect_timeout alone.
                $bounded = str_contains($arguments, "'connect_timeout'") && str_contains($arguments, "'timeout'");
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
