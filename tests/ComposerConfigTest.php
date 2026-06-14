<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ComposerConfigTest extends TestCase
{
    private const COMPOSER_JSON = __DIR__ . '/../composer.json';

    /** @var array<string, mixed> */
    private array $composerConfig;

    protected function setUp(): void
    {
        $content = file_get_contents(self::COMPOSER_JSON);
        if ($content === false) {
            self::fail('Cannot read composer.json');
        }

        $config = json_decode($content, true);
        if ($config === null) {
            self::fail('composer.json is not valid JSON');
        }

        $this->composerConfig = $config;
    }

    public function testAbandonedPackagesReportedNotIgnored(): void
    {
        self::assertArrayHasKey('config', $this->composerConfig);
        self::assertArrayHasKey('audit', $this->composerConfig['config']);
        self::assertArrayHasKey('abandoned', $this->composerConfig['config']['audit']);
        self::assertSame('report', $this->composerConfig['config']['audit']['abandoned']);
    }

    public function testAbandonedConfigIsValidValue(): void
    {
        $currentValue = $this->composerConfig['config']['audit']['abandoned'];

        self::assertContains(
            $currentValue,
            ['report', 'fail'],
            sprintf('abandoned config must be "report" or "fail", got: %s', $currentValue),
        );
    }

    public function testBlockInsecureEnabled(): void
    {
        self::assertArrayHasKey('config', $this->composerConfig);
        self::assertArrayHasKey('audit', $this->composerConfig['config']);
        self::assertArrayHasKey('block-insecure', $this->composerConfig['config']['audit']);
        self::assertTrue(
            $this->composerConfig['config']['audit']['block-insecure'],
            'block-insecure must be true to prevent installing packages with known vulnerabilities',
        );
    }

    public function testAuditIgnoreIsConfigured(): void
    {
        self::assertArrayHasKey('config', $this->composerConfig);
        self::assertArrayHasKey('audit', $this->composerConfig['config']);
        self::assertArrayHasKey('ignore', $this->composerConfig['config']['audit']);
        self::assertIsArray($this->composerConfig['config']['audit']['ignore']);
    }

    public function testDescriptionMentionsMajorCapabilities(): void
    {
        self::assertArrayHasKey('description', $this->composerConfig);

        $description = $this->composerConfig['description'];
        self::assertIsString($description);

        $requiredTerms = ['workerman', 'symfony', 'bundle', 'http', 'long-running'];
        foreach ($requiredTerms as $term) {
            self::assertStringContainsStringIgnoringCase(
                $term,
                $description,
                sprintf('Description must mention "%s"', $term),
            );
        }
    }

    public function testKeywordsContainRequiredTerms(): void
    {
        self::assertArrayHasKey('keywords', $this->composerConfig);
        self::assertIsArray($this->composerConfig['keywords']);

        $keywords = $this->composerConfig['keywords'];
        $requiredKeywords = ['workerman', 'symfony', 'bundle', 'http server', 'long-running', 'scheduler', 'supervisor', 'phar', 'event loop'];

        foreach ($requiredKeywords as $keyword) {
            self::assertContains(
                $keyword,
                $keywords,
                sprintf('keywords must contain "%s"', $keyword),
            );
        }
    }
}
