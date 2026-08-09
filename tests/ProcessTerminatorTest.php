<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for ProcessTerminator's two termination modes.
 *
 * Runs in isolated PHP processes (proc_open with `php -n` + posix) so the
 * grpc extension — whose shutdown handler deadlocks in forked children —
 * cannot interfere with the fork under test. Mirror of RunnerTest.
 */
final class ProcessTerminatorTest extends TestCase
{
    private const TERMINATOR_SCRIPT = __DIR__ . '/Fixtures/process_terminator_test.php';

    /**
     * The soft path must exit with the given code so the supervisor can
     * distinguish a clean finish, and destructors/shutdown functions still
     * run for hosts without grpc.
     */
    public function testSoftTerminationUsesGivenExitCode(): void
    {
        $this->runIsolatedTest('soft', 7);

        // runIsolatedTest asserts the child verified normal exit with code 7.
        $this->addToAssertionCount(1);
    }

    /**
     * The hard path (SIGKILL) must kill the process by signal, bypassing
     * PHP module shutdown — that is the whole point of the grpc mitigation.
     */
    public function testHardTerminationKillsWithSigkill(): void
    {
        $this->runIsolatedTest('hard', 7);

        // runIsolatedTest asserts the child verified SIGKILL death.
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

        $extensionDir = ini_get('extension_dir');

        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                '-d', 'extension_dir=' . $extensionDir,
                '-d', 'extension=posix',
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
