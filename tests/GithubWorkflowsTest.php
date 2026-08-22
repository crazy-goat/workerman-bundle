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
            '/^  schedule:\n    - cron: \'((0?[1-9])|([1-5][0-9])) \d+ \* \* \d+/m',
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
            '/^  tests:\n    name: Tests\n    runs-on: ubuntu-latest\n    needs: \[lint, detect-changes\]\n    if: github\.event_name != \'schedule\' && needs\.detect-changes\.outputs\.docs-only != \'true\'/m',
            $this->workflowContent,
            'The nine-leg tests matrix must not run on the weekly schedule, and must skip on docs-only pull requests',
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

        $this->assertMatchesRegularExpression(
            '/^  benchmark:\n    name: Benchmark\n    runs-on: ubuntu-latest\n    needs: \[lint, detect-changes\]\n    if: github\.event_name != \'schedule\' && needs\.detect-changes\.outputs\.docs-only != \'true\'/m',
            $this->workflowContent,
            'The advisory benchmark must not run on the weekly schedule, and must skip on docs-only pull requests',
        );
    }

    /**
     * Issue #619: a pull request that touches only documentation must not
     * trigger the heavy jobs. A `detect-changes` job classifies the diff and
     * exposes a `docs-only` output; `tests` and `benchmark` consume it. Lint
     * still runs on every change (it is the only job that catches a broken
     * workflow YAML), and the `ci` aggregator reports green for a docs-only
     * PR instead of being skipped — so a required `ci` status check never
     * stays pending.
     */
    public function testDocsOnlyChangeSkipsHeavyJobsButKeepsLintAndCi(): void
    {
        $this->assertMatchesRegularExpression(
            '/^  detect-changes:\n    name: Detect changes\n    runs-on: ubuntu-latest\n    needs: lint\n    outputs:\n      docs-only: \$\{\{ steps\.classify\.outputs\.docs-only \}\}/m',
            $this->workflowContent,
            'A detect-changes job must classify the diff and expose a docs-only output',
        );

        // The classifier must treat Markdown and docs/** as documentation,
        // and default non-pull-request events to docs-only=false so pushes,
        // the schedule and manual dispatch keep running the full matrix.
        $this->assertStringContainsString(
            'docs/*|*.md|*.mdx',
            $this->workflowContent,
            'The classifier must recognise docs/**, *.md and *.mdx as documentation',
        );
        $this->assertStringContainsString(
            'if [ "${{ github.event_name }}" != "pull_request" ]',
            $this->workflowContent,
            'Non-pull-request events must default to docs-only=false',
        );

        // The ci aggregator must treat an intentional docs-only skip as a
        // green result, not a missing tests result.
        $this->assertStringContainsString(
            'Docs-only change: tests and benchmark intentionally skipped',
            $this->workflowContent,
            'The ci aggregator must report green when tests are skipped for a docs-only PR',
        );
    }

    public function testScheduledRunFailureOpensAnIssue(): void
    {
        $this->assertMatchesRegularExpression(
            '/^      - name: Open issue on scheduled failure\n        if: failure\(\) && github\.event_name == \'schedule\'/m',
            $this->workflowContent,
            'A failing scheduled run must produce a visible signal: an issue',
        );

        $this->assertMatchesRegularExpression(
            '/^    permissions:\n      contents: read\n      issues: write$/m',
            $this->workflowContent,
            'The ci job must hold issues: write for the issue opener',
        );

        $this->assertStringContainsString(
            'marker="Scheduled CI run failed"',
            $this->workflowContent,
            'The issue opener must deduplicate open issues by a title marker',
        );
    }
}
