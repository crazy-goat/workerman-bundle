<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class RectorConfigTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $projectDir = \realpath(__DIR__ . '/..');
        if ($projectDir === false) {
            self::fail('Cannot determine project root directory.');
        }
        $this->projectDir = $projectDir;
    }

    public function testRectorConfigDoesNotUseDeprecatedLevelSetList(): void
    {
        $configPath = $this->projectDir . '/rector.php';
        $content = \file_get_contents($configPath);

        if ($content === false) {
            self::fail('Cannot read rector.php');
        }

        self::assertStringNotContainsString(
            'LevelSetList',
            $content,
            'rector.php must not use deprecated LevelSetList::UP_TO_PHP_82',
        );
    }

    public function testRectorConfigUsesWithPhpSets(): void
    {
        $configPath = $this->projectDir . '/rector.php';
        $content = \file_get_contents($configPath);

        if ($content === false) {
            self::fail('Cannot read rector.php');
        }

        self::assertStringContainsString(
            'withPhpSets',
            $content,
            'rector.php must use withPhpSets() instead of deprecated LevelSetList',
        );
    }

    public function testRectorDryRunPasses(): void
    {
        $command = 'php -d memory_limit=512M vendor/bin/rector process --dry-run 2>&1';
        $output = $this->runCommand($command);

        self::assertStringContainsString(
            'Rector is done',
            $output,
            'rector --dry-run should complete successfully: ' . $output,
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
