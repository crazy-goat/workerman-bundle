<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

final class CoverageCiGateTest extends TestCase
{
    public function testCiWorkflowRunsCoverageAndThresholdCheck(): void
    {
        $workflowFile = __DIR__ . '/../.github/workflows/tests.yaml';
        self::assertFileIsReadable($workflowFile);

        $workflow = file_get_contents($workflowFile);
        self::assertIsString($workflow);

        self::assertStringContainsString('coverage: pcov', $workflow);
        self::assertStringContainsString('composer test:coverage', $workflow);
        self::assertStringContainsString('composer coverage:check', $workflow);
        self::assertStringContainsString('check-coverage.php', $workflow);
    }

    public function testCoverageScriptExistsAndIsExecutable(): void
    {
        $script = __DIR__ . '/../bin/check-coverage.php';
        self::assertFileIsReadable($script);
        $scriptContents = file_get_contents($script);
        self::assertIsString($scriptContents);
        self::assertStringContainsString('simplexml_load_file', $scriptContents);
    }
}
