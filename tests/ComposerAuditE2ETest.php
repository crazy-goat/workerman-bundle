<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ComposerAuditE2ETest extends TestCase
{
    private const COMPOSER_AUDIT_COMMAND = 'composer audit 2>&1';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \realpath(__DIR__ . '/..');
        if ($this->projectDir === false) {
            self::fail('Cannot determine project root directory.');
        }
    }

    public function testComposerValidatePasses(): void
    {
        $command = \sprintf('composer validate --strict 2>&1');
        $output = $this->runCommand($command);

        self::assertStringContainsString(
            './composer.json is valid',
            $output,
            'composer.json must pass strict validation: ' . $output,
        );
    }

    public function testComposerAuditCompletesSuccessfully(): void
    {
        $command = \sprintf('composer audit --no-dev 2>&1');
        $output = $this->runCommand($command);

        self::assertStringNotContainsString(
            'ComposerAuditCommand',
            $output,
            'composer audit should not throw exceptions',
        );
    }

    private function runCommand(string $command): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = \proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->projectDir,
        );

        if (!\is_resource($proc)) {
            self::fail('Failed to start command: ' . $command);
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        if ($exitCode !== 0) {
            $errorOutput = \is_string($stdout) ? $stdout : '';
            $errorStderr = \is_string($stderr) ? $stderr : '';
            self::fail(
                \sprintf(
                    "Command failed (exit code %d):\nstdout: %s\nstderr: %s",
                    $exitCode,
                    $errorOutput,
                    $errorStderr,
                ),
            );
        }

        return \is_string($stdout) ? $stdout : '';
    }
}
