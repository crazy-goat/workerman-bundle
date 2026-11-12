<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

final class ProcessTerminatorTest extends TestCase
{
    private const TERMINATOR_SCRIPT = __DIR__ . '/Fixtures/process_terminator_test.php';

    public function testSoftTerminationUsesGivenExitCode(): void
    {
        $this->runIsolatedTest('soft', 7);

        $this->addToAssertionCount(1);
    }

    public function testHardTerminationKillsWithSigkill(): void
    {
        $this->runIsolatedTest('hard', 7);

        $this->addToAssertionCount(1);
    }

    private function runIsolatedTest(string $mode, int $code): void
    {
        $this->assertFileExists(self::TERMINATOR_SCRIPT, 'Test runner script must exist');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [
                ...IsolatedPhp::command(),
                self::TERMINATOR_SCRIPT,
                $mode,
                (string) $code,
            ],
            $descriptors,
            $pipes,
        );

        $this->assertIsResource($process, 'Failed to start isolated test process');

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            sprintf(
                "Isolated test '%s/%d' failed (exit code %d):\nstdout: %s\nstderr: %s",
                $mode,
                $code,
                $exitCode,
                $stdout,
                $stderr,
            ),
        );

        $this->assertStringContainsString('PASS', $stdout);
    }
}
