<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

final class CoverageCiGateTest extends TestCase
{
    public function testCiWorkflowRunsCoverageAndThresholdCheck(): void
    {
        $workflow = self::readWorkflow();

        self::assertStringContainsString('coverage: pcov', $workflow);
        self::assertStringContainsString('composer test:coverage', $workflow);
        self::assertStringContainsString('composer coverage:check', $workflow);
    }

    public function testCoverageGateRunsOnceOnLowestMatrixLegOnly(): void
    {
        $workflow = self::readWorkflow();

        self::assertSame(
            1,
            substr_count($workflow, 'composer coverage:check'),
            'The coverage gate must run exactly once (single designated matrix leg)',
        );
        self::assertStringContainsString("matrix.php-version == '8.2'", $workflow);
        self::assertStringContainsString("matrix.symfony-version == '6.4.*'", $workflow);
    }

    public function testThresholdIsDefinedOnlyInComposerScript(): void
    {
        $workflow = self::readWorkflow();

        self::assertStringNotContainsString(
            'bin/check-coverage.php',
            $workflow,
            'CI must not invoke check-coverage.php directly — the threshold is defined once in composer.json',
        );
    }

    public function testComposerCoverageCheckDefinesNonZeroThreshold(): void
    {
        $composerFile = __DIR__ . '/../composer.json';
        self::assertFileIsReadable($composerFile);

        $composer = json_decode((string) file_get_contents($composerFile), true);
        self::assertIsArray($composer);

        $scripts = $composer['scripts']['coverage:check'] ?? null;
        self::assertIsArray($scripts);
        self::assertCount(1, $scripts, 'coverage:check must be a single command so the threshold lives in one place');

        $matched = preg_match(
            '/check-coverage\.php\s+\S+\s+(?<threshold>\d+(?:\.\d+)?)/',
            (string) $scripts[0],
            $matches,
        );
        self::assertSame(1, $matched, 'coverage:check must pass an explicit threshold to check-coverage.php');
        self::assertGreaterThan(
            0.0,
            (float) $matches['threshold'],
            'The coverage gate threshold must be non-zero so the gate can actually fail',
        );
    }

    public function testCoverageScriptExistsAndIsExecutable(): void
    {
        $script = __DIR__ . '/../bin/check-coverage.php';
        self::assertFileIsReadable($script);
        $scriptContents = file_get_contents($script);
        self::assertIsString($scriptContents);
        self::assertStringContainsString('simplexml_load_file', $scriptContents);
    }

    private static function readWorkflow(): string
    {
        $workflowFile = __DIR__ . '/../.github/workflows/tests.yaml';
        self::assertFileIsReadable($workflowFile);

        $workflow = file_get_contents($workflowFile);
        self::assertIsString($workflow);

        return $workflow;
    }
}
