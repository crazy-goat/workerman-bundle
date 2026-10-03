<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/reverse-proxy.md must match the config tree, and its examples must stay consistent (issue #878, section 13).
 *
 * @coversNothing
 */
final class ReverseProxyDocTest extends TestCase
{
    private const PAGE = 'docs/reverse-proxy.md';

    public function testNamedConfigKeysExistAndDefaultsAreStated(): void
    {
        $leaves = DocsHelper::configLeaves();
        $page = DocsHelper::read(self::PAGE);

        foreach (['trusted_hosts', 'keepalive_timeout', 'connection_timeout', 'max_package_size'] as $key) {
            self::assertArrayHasKey($key, $leaves);
            self::assertStringContainsString('`' . $key . '`', $page);
        }
        self::assertArrayHasKey('servers[].body_size_cap', $leaves);
        self::assertStringContainsString('`body_size_cap`', $page);

        self::assertSame(30, $leaves['keepalive_timeout']->getDefaultValue());
        self::assertSame(120, $leaves['connection_timeout']->getDefaultValue());
        self::assertSame(10485760, $leaves['max_package_size']->getDefaultValue());
        self::assertStringContainsString('(default 30)', $page);
        self::assertStringContainsString('| `connection_timeout` | server | 120 seconds |', $page);
        self::assertStringContainsString('| `max_package_size` and `body_size_cap` | server | 10 MB |', $page);
    }

    public function testNginxUpstreamKeepaliveIsShorterThanTheServerKeepalive(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertSame(1, preg_match_all('/```nginx\n(.*?)```/s', $page, $blocks));
        $nginx = $blocks[1][0];

        self::assertSame(1, preg_match_all('/upstream app \{.*?keepalive_timeout (\d+)s;/s', $nginx, $upstream));
        self::assertLessThan(DocsHelper::configLeaves()['keepalive_timeout']->getDefaultValue(), (int) $upstream[1][0]);

        foreach (['proxy_http_version 1.1;', 'proxy_set_header Connection "";', 'X-Forwarded-For $remote_addr;', 'X-Forwarded-Proto $scheme;', 'X-Forwarded-Host $host;', 'X-Forwarded-Port $server_port;', 'proxy_set_header Host $host;'] as $needle) {
            self::assertStringContainsString($needle, $nginx);
        }
        self::assertStringContainsString('location ~ \.php$ {', $nginx);
        self::assertStringContainsString('location ~ /\.(?!well-known/) {', $nginx);
        self::assertStringNotContainsString('$proxy_add_x_forwarded_for;', $nginx);
    }

    public function testNginxBodyLimitIsNotAboveTheServerLimit(): void
    {
        $page = DocsHelper::read(self::PAGE);

        self::assertSame(1, preg_match_all('/client_max_body_size (\d+)m;/', $page, $size));
        self::assertLessThanOrEqual(
            DocsHelper::configLeaves()['max_package_size']->getDefaultValue(),
            (int) $size[1][0] * 1024 * 1024,
        );
    }

    public function testYamlExamplesParseAndTheTrustedProxiesBlockMatchesUpgrade(): void
    {
        $page = DocsHelper::read(self::PAGE);
        $blocks = DocsHelper::yamlBlocks($page);
        self::assertCount(3, $blocks);

        $server = Yaml::parse($blocks[0]);
        self::assertIsArray($server);
        self::assertStringStartsWith('http://127.0.0.1:', $server['workerman']['servers'][0]['listen']);

        $framework = Yaml::parse($blocks[1]);
        self::assertIsArray($framework);
        self::assertArrayHasKey('trusted_proxies', $framework['framework']);
        self::assertSame(
            ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port'],
            $framework['framework']['trusted_headers'],
        );
        self::assertStringContainsString('trusted_headers: [\'x-forwarded-proto\', \'x-forwarded-for\'', DocsHelper::read('UPGRADE.md'));

        $hosts = Yaml::parse($blocks[2]);
        self::assertIsArray($hosts);
        self::assertSame(['^example\.com$'], $hosts['workerman']['trusted_hosts']);
    }

    public function testPageIsLinkedFromTheIndexAndTheOtherPages(): void
    {
        self::assertStringContainsString('(reverse-proxy.md)', DocsHelper::read('docs/README.md'));
        self::assertStringContainsString('(reverse-proxy.md)', DocsHelper::read('docs/getting-started.md'));
        self::assertStringContainsString('(reverse-proxy.md)', DocsHelper::read('docs/deployment.md'));
    }
}
