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

    public function testPullRequestTriggerIncludesReadyForReview(): void
    {
        // `ready_for_review` is not in the default type set. Without it, a PR
        // pushed while still a draft has `pow: skipped` on every run, and
        // clicking "Ready for review" produces no new run for the same head
        // SHA — so the gate would never run before merge.
        $this->assertMatchesRegularExpression(
            '/^\s+types: \[[^]]*ready_for_review[^]]*\]$/m',
            $this->workflowContent,
            'A draft PR marked ready must trigger a fresh run, or the pow job is never enforced',
        );

        foreach (['opened', 'synchronize', 'reopened'] as $type) {
            $this->assertMatchesRegularExpression(
                '/^\s+types: \[[^]]*\b' . $type . '\b[^]]*\]$/m',
                $this->workflowContent,
                'Naming types explicitly opts out of the defaults, so every default must be restated',
            );
        }
    }

    public function testProofOfWorkGateRunsTheMasterCopyOfTheScript(): void
    {
        $this->assertStringContainsString(
            'git show origin/master:bin/check-pow.php > "$gate/check-pow.php"',
            $this->workflowContent,
            'CI must materialise the master copy of the gate — a PR must not be able to weaken the gate that judges it',
        );
        $this->assertStringContainsString(
            'git show origin/master:bin/pow-common.php > "$gate/pow-common.php"',
            $this->workflowContent,
            'The gate requires bin/pow-common.php from its own directory; half a gate from master is no gate',
        );
        $this->assertStringContainsString(
            'php "$gate/check-pow.php" --strict --verify-reality',
            $this->workflowContent,
            'CI must run the materialised master copy, not bin/check-pow.php from the branch',
        );
        $this->assertStringNotContainsString(
            'run: php bin/check-pow.php',
            $this->workflowContent,
            'CI must never invoke the in-tree copy of the gate directly',
        );
    }

    public function testProofOfWorkGateIsPointedAtTheCheckout(): void
    {
        // The gate is materialised into $RUNNER_TEMP, whose parent is not the
        // checkout; without this the job failed on POW-00/POW-02 whatever the
        // branch had recorded.
        $this->assertStringContainsString(
            'CHECK_POW_ROOT: ${{ github.workspace }}',
            $this->workflowContent,
            'The materialised gate must be told which repository it judges',
        );
    }

    public function testProofOfWorkGateFallsBackWhenMasterHasNoScriptYet(): void
    {
        $this->assertStringContainsString(
            'cp bin/check-pow.php bin/pow-common.php "$gate/"',
            $this->workflowContent,
            'The PR introducing the gate must be able to run it — with a loud warning, and with both halves',
        );
    }

    public function testProofOfWorkGateParticipatesInTheAggregatedSignal(): void
    {
        $this->assertMatchesRegularExpression(
            '/needs: \[lint, tests, benchmark, pow, pow-reality\]/',
            $this->workflowContent,
            'The ci aggregator must depend on both halves of the gate',
        );

        foreach (['pow', 'pow-reality'] as $job) {
            $this->assertStringContainsString(
                sprintf(
                    'if [ "${{ needs.%1$s.result }}" != "success" ] && [ "${{ needs.%1$s.result }}" != "skipped" ]; then exit 1; fi',
                    $job,
                ),
                $this->workflowContent,
                sprintf('A failing %s job must fail CI; a skipped one (draft PR) must not', $job),
            );
        }
    }

    /**
     * Everything except POW-08 is pure git plus a few API reads, so it must not
     * queue behind `lint`: tampering is the finding you want first, not last.
     * POW-08 recomputes lint/tests/coverage and is the only slow part, so it
     * lives in its own job behind `tests`.
     */
    public function testTheFastHalfOfTheGateDoesNotWaitForLint(): void
    {
        $pow = $this->jobBlock('pow');

        $this->assertStringNotContainsString(
            'needs:',
            $pow,
            'The attestation checks are seconds of work — they run beside lint, not after it',
        );
        $this->assertStringNotContainsString(
            '--verify-reality',
            $pow,
            'Recomputing lint and the whole suite belongs in pow-reality, not in the fast job',
        );
        $this->assertStringNotContainsString(
            'composer install',
            $pow,
            'Without --verify-reality the gate is a standalone script; installing vendor/ would only cost time',
        );

        $this->assertStringContainsString(
            'needs: [lint, tests]',
            $this->jobBlock('pow-reality'),
            'POW-08 is moot when the suite is red, so it waits for tests instead of racing them for runners',
        );
    }

    public function testBothHalvesOfTheGateRunTheMasterCopy(): void
    {
        foreach (['pow', 'pow-reality'] as $job) {
            $this->assertStringContainsString(
                'git show origin/master:bin/check-pow.php > "$gate/check-pow.php"',
                $this->jobBlock($job),
                sprintf('%s must judge the PR with the master copy of the gate', $job),
            );
        }
    }

    /**
     * Returns one top-level job block, from its `  <name>:` header up to the
     * next one, so an assertion cannot accidentally be satisfied by a sibling.
     *
     * Full-line `#` comments are stripped: these jobs explain themselves at
     * length, and a negative assertion must not be tripped by prose that merely
     * names the thing it forbids.
     */
    private function jobBlock(string $name): string
    {
        $pattern = sprintf('/^  %s:\n(?:(?!^  [a-z]).*\n)*/m', preg_quote($name, '/'));

        $this->assertMatchesRegularExpression($pattern, $this->workflowContent, sprintf('job %s not found', $name));
        preg_match($pattern, $this->workflowContent, $matches);

        $lines = array_filter(
            explode("\n", $matches[0]),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '#'),
        );

        return implode("\n", $lines);
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
