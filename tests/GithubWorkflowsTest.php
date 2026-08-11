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

    public function testProofOfWorkGateRunsTheMasterCopyOfTheScript(): void
    {
        $this->assertStringContainsString(
            'git show origin/master:bin/check-pow.php > "$RUNNER_TEMP/check-pow.php"',
            $this->workflowContent,
            'CI must materialise the master copy of the gate — a PR must not be able to weaken the gate that judges it',
        );
        $this->assertStringContainsString(
            'php "$RUNNER_TEMP/check-pow.php" --strict --verify-reality',
            $this->workflowContent,
            'CI must run the materialised master copy, not bin/check-pow.php from the branch',
        );
        $this->assertStringNotContainsString(
            'run: php bin/check-pow.php',
            $this->workflowContent,
            'CI must never invoke the in-tree copy of the gate directly',
        );
    }

    public function testProofOfWorkGateFallsBackWhenMasterHasNoScriptYet(): void
    {
        $this->assertStringContainsString(
            'cp bin/check-pow.php "$RUNNER_TEMP/check-pow.php"',
            $this->workflowContent,
            'The PR introducing the gate must be able to run it — with a loud warning',
        );
    }

    public function testProofOfWorkGateParticipatesInTheAggregatedSignal(): void
    {
        $this->assertMatchesRegularExpression(
            '/needs: \[lint, tests, benchmark, pow\]/',
            $this->workflowContent,
            'The ci aggregator must depend on the pow job',
        );
        $this->assertStringContainsString(
            'if [ "${{ needs.pow.result }}" != "success" ] && [ "${{ needs.pow.result }}" != "skipped" ]; then exit 1; fi',
            $this->workflowContent,
            'A failing pow job must fail CI; a skipped one (draft PR) must not',
        );
    }

    public function testProofOfWorkGateIsSkippedOnDraftPullRequests(): void
    {
        $this->assertMatchesRegularExpression(
            '/pow:(?:(?!^  \w).)*if: github\.event\.pull_request\.draft == false/sm',
            $this->workflowContent,
            'The pow job must not run while the PR is a draft — the proof of work is only complete at step 11.5',
        );
    }

    public function testProofOfWorkGateChecksOutTheFullHistory(): void
    {
        $this->assertMatchesRegularExpression(
            '/pow:(?:(?!^  \w).)*fetch-depth: 0/sm',
            $this->workflowContent,
            'The gate walks the ledger history commit by commit, so it needs the full history',
        );
    }
}
