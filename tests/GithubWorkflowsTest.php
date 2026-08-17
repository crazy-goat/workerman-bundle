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
     * nothing cancelled the one that was already obsolete. Since #597 the
     * group is per-ref and cancellation applies to pull requests only: a
     * push-triggered master run is never cancelled by a later run, so a
     * post-merge failure stays visible.
     */
    public function testASupersededRunIsCancelledInsteadOfFinishing(): void
    {
        $this->assertMatchesRegularExpression(
            '/^concurrency:\n  group: .+\n  cancel-in-progress: \${{ github\.event_name == \'pull_request\' }}$/m',
            $this->workflowContent,
            'Overlapping runs on one pull request must cancel the older one',
        );

        $this->assertMatchesRegularExpression(
            '/^  group: \${{ github\.workflow }}-\${{ github\.ref }}$/m',
            $this->workflowContent,
            'The group must be per-ref, or one PR would cancel another PR run',
        );
    }

    public function testWorkflowRunsOnMasterPushScheduleAndDispatch(): void
    {
        $this->assertMatchesRegularExpression(
            '/^on:\n  pull_request:\n  push:\n    branches: \[master\]/m',
            $this->workflowContent,
            'The workflow must run on pull requests and on every push to master',
        );

        $this->assertMatchesRegularExpression(
            '/^  schedule:\n    - cron: \'([1-9]|[1-5][0-9]) \d+ \* \* \d+/m',
            $this->workflowContent,
            'A weekly scheduled run must exist, at a quiet minute not on the top of the hour',
        );

        $this->assertStringContainsString(
            '  workflow_dispatch:',
            $this->workflowContent,
            'Maintainers must be able to start a run manually via workflow_dispatch',
        );
    }

    public function testScheduledRunsTrimTheMatrixToASingleLeg(): void
    {
        $this->assertMatchesRegularExpression(
            '/^  tests:\n    name: Tests\n    runs-on: ubuntu-latest\n    needs: lint\n    if: github\.event_name != \'schedule\'/m',
            $this->workflowContent,
            'The nine-leg tests matrix must not run on the weekly schedule',
        );

        $this->assertMatchesRegularExpression(
            '/^  tests-scheduled:\n    name: Tests \(scheduled\)/m',
            $this->workflowContent,
            'A single-leg tests job must run on the weekly schedule',
        );

        // The scheduled job must run exactly one matrix leg — capture its own
        // block (up to the next job heading) and count the entries, so a
        // regression adding more legs fails even though the job still exists.
        $scheduled = '';
        if (preg_match('/^  tests-scheduled:.*?(?=^  \w[\w-]*:$)/ms', $this->workflowContent, $m) === 1) {
            $scheduled = $m[0];
        }
        $this->assertNotSame('', $scheduled, 'The tests-scheduled job must be present');
        $this->assertSame(
            1,
            preg_match_all('/^ {10}- php-version:/m', $scheduled),
            'The scheduled run must execute exactly one matrix leg',
        );
    }
}
