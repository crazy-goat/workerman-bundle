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

    /**
     * The audit.ignore list must be empty. Suppressing Composer audit
     * advisories globally shadows real risk: if a transitive non-dev
     * dependency ever pulls in the affected package, the suppression
     * hides the warning. Any advisory that affects only require-dev
     * dependencies is already excluded from `composer audit --no-dev`,
     * which is the audit mode used in CI and production.
     *
     * If an advisory must be suppressed temporarily, add it to the
     * audit.ignore list AND document the justification in docs/security.md
     * under "Composer Audit Advisory Suppression Policy". Remove the entry
     * as soon as the underlying package is patched.
     */
    public function testAuditIgnoreListIsEmpty(): void
    {
        self::assertArrayHasKey('config', $this->composerConfig);
        self::assertArrayHasKey('audit', $this->composerConfig['config']);
        self::assertArrayHasKey('ignore', $this->composerConfig['config']['audit']);

        $ignore = $this->composerConfig['config']['audit']['ignore'];
        self::assertIsArray($ignore);
        self::assertSame(
            [],
            $ignore,
            'audit.ignore must be empty. Suppressed advisories shadow real risk '
            . 'and hide vulnerabilities in non-dev dependencies. See '
            . 'docs/security.md "Composer Audit Advisory Suppression Policy".',
        );
    }

    /**
     * `composer audit --no-dev` (the mode used by CI and production)
     * must report zero advisories. This guards against vulnerabilities
     * shipped to production users via the `require` dependency set.
     */
    public function testComposerAuditNoDevIsClean(): void
    {
        $output = $this->runComposerAuditNoDev();

        $decoded = \json_decode($output, true);
        self::assertIsArray($decoded, 'composer audit --format=json --no-dev must produce valid JSON');
        self::assertArrayHasKey('advisories', $decoded);

        $advisories = $decoded['advisories'];
        self::assertIsArray($advisories, 'advisories must be an array');
        self::assertSame(
            [],
            $advisories,
            'composer audit --no-dev must report zero advisories. '
            . 'Vulnerabilities in production dependencies must be fixed, not suppressed.',
        );
    }

    private function runComposerAuditNoDev(): string
    {
        $projectDir = \realpath(__DIR__ . '/..');
        if ($projectDir === false) {
            self::fail('Cannot determine project root directory.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = \proc_open(
            'composer audit --format=json --no-dev',
            $descriptors,
            $pipes,
            $projectDir,
        );

        if (!\is_resource($proc)) {
            self::fail('Failed to start composer audit --no-dev');
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        $stdout = \is_string($stdout) ? $stdout : '';
        $stderr = \is_string($stderr) ? $stderr : '';

        // composer audit exits 0 (clean) or 1 (advisories/abandoned found)
        if ($exitCode !== 0 && $exitCode !== 1) {
            self::fail(\sprintf(
                "composer audit --no-dev failed unexpectedly (exit code %d):\nstdout: %s\nstderr: %s",
                $exitCode,
                $stdout,
                $stderr,
            ));
        }

        if ($stdout === '') {
            self::fail(\sprintf(
                "composer audit --no-dev produced no output (exit code %d):\nstderr: %s",
                $exitCode,
                $stderr,
            ));
        }

        return $stdout;
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
