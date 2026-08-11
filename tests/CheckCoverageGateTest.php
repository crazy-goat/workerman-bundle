<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for bin/check-coverage.php.
 *
 * The fixture (tests/Fixtures/clover-small.xml) contains class-, file- and
 * project-level <metrics> nodes whose statement counts do NOT add up to a
 * simple multiple of one another. A naive summation of every //metrics node
 * hashes to 260/360 = 72.22%, whereas the real project aggregate is
 * 75/100 = 75.00%. These tests pin the script to the project aggregate.
 */
final class CheckCoverageGateTest extends TestCase
{
    private const PROJECT_STATEMENTS = 100;
    private const PROJECT_COVERED = 75;
    private const PROJECT_PERCENT = 75.0;

    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = __DIR__ . '/Fixtures/clover-small.xml';
        self::assertFileIsReadable($this->fixture);
    }

    public function testUsesProjectAggregateNotInflatedMetricsSum(): void
    {
        $output = $this->runScript('0.0');

        self::assertStringContainsString(
            sprintf('Coverage: %.2f%% (%d/%d statements)', self::PROJECT_PERCENT, self::PROJECT_COVERED, self::PROJECT_STATEMENTS),
            $output['stdout'],
            'Expected the project-level percentage and exact project statement counts, not the sum of every //metrics node',
        );
    }

    public function testUsesProjectAggregateDoesNotReturnInflatedStatementCounts(): void
    {
        $output = $this->runScript('0.0');

        // Naive summation of the fixture's metrics yields 360 statements /
        // 260 covered; make sure the script did NOT report those.
        self::assertStringNotContainsString(
            '(260/360 statements)',
            $output['stdout'],
            'The script must report the project aggregate, not the sum of class+file+project metrics nodes',
        );
    }

    public function testPassesWhenCoverageAboveThreshold(): void
    {
        $output = $this->runScript('74.0');

        self::assertSame(0, $output['status']);
        self::assertStringContainsString('OK', $output['stdout']);
    }

    public function testFailsWhenCoverageBelowThreshold(): void
    {
        $output = $this->runScript('76.0');

        self::assertSame(1, $output['status']);
        self::assertStringContainsString('FAILED', $output['stdout']);
    }

    public function testExactThresholdBoundaryPasses(): void
    {
        $output = $this->runScript('75.0');

        self::assertSame(0, $output['status']);
    }

    public function testFallsBackToSummingFileMetricsWhenNoProjectAggregateExists(): void
    {
        $output = $this->runScriptOn('0.0', __DIR__ . '/Fixtures/clover-files-only.xml');

        // Both fixtures converge on 75/100 = 75.00%, but this one reaches it
        // via the fallback that sums /file/metrics (60+40 / 40+35) instead of
        // reading a single project-level aggregate.
        self::assertStringContainsString(
            sprintf('Coverage: %.2f%% (%d/%d statements)', self::PROJECT_PERCENT, self::PROJECT_COVERED, self::PROJECT_STATEMENTS),
            $output['stdout'],
        );
        self::assertStringNotContainsString(
            '(72.22%)',
            $output['stdout'],
        );
        self::assertSame(0, $output['status']);
    }

    public function testFallsBackWhenNoMetricsExistAnywhere(): void
    {
        $output = $this->runScriptOn('0.0', __DIR__ . '/Fixtures/clover-no-metrics.xml');

        self::assertSame(2, $output['status'], 'A coverage file with no <metrics> anywhere must exit 2');
        self::assertStringContainsString('No aggregate <metrics> element found', $output['stderr']);
    }

    /**
     * @return array{stdout: string, stderr: string, status: int}
     */
    private function runScript(float|string $threshold): array
    {
        return $this->runScriptOn($threshold, $this->fixture);
    }

    /**
     * @return array{stdout: string, stderr: string, status: int}
     */
    private function runScriptOn(float|string $threshold, string $fixture): array
    {
        $script = __DIR__ . '/../bin/check-coverage.php';
        $thresholdArg = number_format((float) $threshold, 1, '.', '');
        $command = sprintf(
            'php %s %s %s',
            escapeshellarg($script),
            escapeshellarg($fixture),
            escapeshellarg($thresholdArg),
        );

        $stdout = $stderr = '';
        $status = 0;
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => $status];
    }
}
