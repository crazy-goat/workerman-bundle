<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class GithubWorkflowsTest extends TestCase
{
    private const WORKFLOW_FILE = __DIR__ . '/../.github/workflows/tests.yaml';

    private string $workflowContent;

    protected function setUp(): void
    {
        self::assertFileExists(self::WORKFLOW_FILE);

        $content = file_get_contents(self::WORKFLOW_FILE);
        self::assertNotFalse($content);

        $this->workflowContent = $content;
    }

    public function testLintJobPinsPhpVersion(): void
    {
        $this->assertStringContainsString(
            "php-version: '8.2'",
            $this->workflowContent,
            'Lint job must pin PHP version to 8.2 for deterministic lint results',
        );
    }

    public function testLintJobSpecifiesComposerVersion(): void
    {
        $this->assertStringContainsString(
            'tools: composer:v2',
            $this->workflowContent,
            'Lint job must specify tools: composer:v2 for deterministic composer behavior',
        );
    }

    public function testTestsJobUsesMatrixPhpVersion(): void
    {
        $this->assertStringContainsString(
            'php-version: ${{ matrix.php-version }}',
            $this->workflowContent,
            'Tests job must use the matrix php-version variable',
        );
    }

    /**
     * Marking a draft ready and pushing a moment later once left two full
     * matrices — eighteen legs — running against the same pull request, and
     * nothing cancelled the one that was already obsolete.
     */
    public function testASupersededRunIsCancelledInsteadOfFinishing(): void
    {
        $this->assertMatchesRegularExpression(
            '/^concurrency:\n  group: .+\n  cancel-in-progress: true$/m',
            $this->workflowContent,
            'Overlapping runs on one pull request must cancel the older one',
        );

        $this->assertMatchesRegularExpression(
            '/^  group: .*pull_request\.number.*$/m',
            $this->workflowContent,
            'The group must be per-pull-request, or one PR would cancel another PR run',
        );
    }
}
