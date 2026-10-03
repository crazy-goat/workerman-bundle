<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docs/logging-monitoring.md must match the config tree, and its examples must stay consistent (issue #878, section 14).
 *
 * @coversNothing
 */
final class LoggingMonitoringDocTest extends TestCase
{
    private const PAGE = 'docs/logging-monitoring.md';

    public function testNamedConfigKeysExistAndTheDefaultIsStated(): void
    {
        $leaves = DocsHelper::configLeaves();
        $page = DocsHelper::read(self::PAGE);

        foreach (['log_file', 'stdout_file', 'status_timeout'] as $key) {
            self::assertArrayHasKey($key, $leaves);
            self::assertStringContainsString('`' . $key . '`', $page);
        }

        self::assertSame(5, $leaves['status_timeout']->getDefaultValue());
        self::assertStringContainsString('(5 seconds by default)', $page);
    }

    public function testYamlExamplesParse(): void
    {
        $blocks = DocsHelper::yamlBlocks(DocsHelper::read(self::PAGE));
        self::assertCount(3, $blocks);

        foreach ($blocks as $block) {
            $config = Yaml::parse($block);
            self::assertIsArray($config);
            self::assertArrayHasKey('handlers', $config['monolog']);
        }

        $tasks = Yaml::parse($blocks[2]);
        self::assertSame(['task', 'process'], $tasks['monolog']['handlers']['background']['channels']);
        self::assertSame(['!task', '!process'], $tasks['monolog']['handlers']['main']['channels']);
    }

    public function testHealthCheckSendsAHostHeaderInTheDeploymentPage(): void
    {
        $deployment = DocsHelper::read('docs/deployment.md');
        self::assertStringContainsString('"Host: example.com"', $deployment);
        self::assertStringContainsString('send a Host header from the list', $deployment);
        self::assertStringContainsString('"Host: example.com"', DocsHelper::read(self::PAGE));
    }

    public function testPageIsLinkedFromTheIndexAndDeployment(): void
    {
        self::assertStringContainsString('(logging-monitoring.md)', DocsHelper::read('docs/README.md'));
        self::assertStringContainsString('(logging-monitoring.md', DocsHelper::read('docs/deployment.md'));
    }
}
