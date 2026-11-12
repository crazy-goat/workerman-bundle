<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoundedHttpClientTest extends TestCase
{
    public function testCreateCarriesTimeouts(): void
    {
        $handler = new MockHandler([new Response(200)]);

        BoundedHttpClient::create([], $handler)->request('GET', TestPorts::url(TestPorts::http(), '/response_test'));

        $options = $handler->getLastOptions();
        self::assertSame(1, $options['timeout']);
        self::assertSame(0.2, $options['connect_timeout']);
        self::assertFalse($options['http_errors']);
    }

    public function testCreateKeepsALongerTimeoutWhereATestNeedsIt(): void
    {
        $handler = new MockHandler([new Response(200)]);

        BoundedHttpClient::create(['timeout' => 20], $handler)->request('GET', TestPorts::url(TestPorts::second(), '/response_test_file_big'));

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
     */
    #[DataProvider('unboundedOverrideProvider')]
    public function testCreateRejectsAnOverrideThatRemovesABound(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BoundedHttpClient::create($options);
    }

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
            if (str_contains($source, 'curl_init(')
                && (!str_contains($source, 'CURLOPT_CONNECTTIMEOUT') || !str_contains($source, 'CURLOPT_TIMEOUT'))) {
                $offenders[] = $file->getFilename() . ': curl_init() without CURLOPT_CONNECTTIMEOUT and CURLOPT_TIMEOUT';
            }
            if ($file->getFilename() === 'BoundedHttpClient.php') {
                continue;
            }
            if (preg_match_all('/new\s+\\\\?(?:GuzzleHttp\\\\)?Client\s*\(([^;]*);/', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $arguments) {
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
