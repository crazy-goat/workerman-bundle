<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * E2E tests for PollingMonitorWatcher.
 *
 * Verifies that the full chain works with a real filesystem:
 * - Creates temp files, instantiates PollingMonitorWatcher
 * - Verifies file modification is detected
 * - Verifies no false positives without changes
 */
final class PollingMonitorWatcherE2ETest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/workerman_e2e_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempDir, 0700);

        // Ignore SIGUSR1 during this test — PollingMonitorWatcher sends it
        // via Utils::reload() to the parent process (this process).
        if (\extension_loaded('pcntl') && \defined('SIGUSR1')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGUSR1, \SIG_IGN);
        }
    }

    protected function tearDown(): void
    {
        if (\extension_loaded('pcntl') && \defined('SIGUSR1')) {
            \pcntl_async_signals(false);
            \pcntl_signal(\SIGUSR1, \SIG_DFL);
        }

        if (isset($this->tempDir) && \is_dir($this->tempDir)) {
            $files = \glob($this->tempDir . '/*.php');
            if (\is_array($files)) {
                \array_map(\unlink(...), $files);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testWatcherDetectsFileModificationInSubprocess(): void
    {
        $autoloadPath = \realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoloadPath === false) {
            self::markTestSkipped('vendor/autoload.php not found.');
        }

        $scriptFile = __DIR__ . '/Fixtures/polling_watcher_e2e_runner.php';

        $exitCode = $this->runPhpScript(
            $scriptFile,
            [$this->tempDir, $autoloadPath],
        );

        self::assertSame(
            0,
            $exitCode,
            'PollingMonitorWatcher should detect file changes in a subprocess.',
        );
    }

    /**
     * @param string[] $args
     */
    private function runPhpScript(string $scriptFile, array $args): int
    {
        $command = array_values(['php', $scriptFile, ...$args]);
        $proc = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($proc)) {
            $this->fail('Failed to start subprocess.');
        }

        \fclose($pipes[0]);
        \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        if ($exitCode !== 0 && $stderr !== '' && $stderr !== false) {
            \fwrite(\STDERR, "Subprocess stderr: " . $stderr);
        }

        return $exitCode;
    }
}
