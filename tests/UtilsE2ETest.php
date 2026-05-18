<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * E2E tests for Utils::reload().
 *
 * These tests verify that Utils::reload() actually sends a SIGUSR1 signal
 * in a real subprocess context (not just within the same process).
 */
final class UtilsE2ETest extends TestCase
{
    private string $tempDir;
    private string $autoloadPath;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        $this->tempDir = \sys_get_temp_dir() . '/workerman_e2e_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempDir, 0700);
        $autoloadPath = \realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoloadPath === false) {
            self::markTestSkipped('vendor/autoload.php not found.');
        }
        $this->autoloadPath = $autoloadPath;
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && \is_dir($this->tempDir)) {
            $files = \glob($this->tempDir . '/*.php');
            if (\is_array($files)) {
                \array_map(\unlink(...), $files);
            }
            \rmdir($this->tempDir);
        }
    }

    public function testReloadSendsSigusr1ToChildProcess(): void
    {
        $scriptFile = $this->tempDir . '/test_reload.php';
        \file_put_contents(
            $scriptFile,
            \sprintf(
                <<<'PHP'
<?php
require %s;

$signalReceived = false;
pcntl_signal(SIGUSR1, function () use (&$signalReceived) {
    $signalReceived = true;
});

\CrazyGoat\WorkermanBundle\Utils::reload();

$start = microtime(true);
while (!$signalReceived && (microtime(true) - $start) < 2) {
    pcntl_signal_dispatch();
    usleep(10000);
}

exit($signalReceived ? 0 : 1);
PHP,
                \var_export($this->autoloadPath, true),
            ),
        );

        $exitCode = $this->runPhpScript($scriptFile);
        self::assertSame(0, $exitCode, 'Utils::reload() should send SIGUSR1 in a subprocess.');
    }

    public function testDeprecatedRebootStillWorksInSubprocess(): void
    {
        $scriptFile = $this->tempDir . '/test_reboot_deprecated.php';
        \file_put_contents(
            $scriptFile,
            \sprintf(
                <<<'PHP'
<?php
require %s;

$signalReceived = false;
pcntl_signal(SIGUSR1, function () use (&$signalReceived) {
    $signalReceived = true;
});

set_error_handler(function (int $errno, string $errstr) {
    return $errno === E_USER_DEPRECATED;
});

\CrazyGoat\WorkermanBundle\Utils::reboot();
restore_error_handler();

$start = microtime(true);
while (!$signalReceived && (microtime(true) - $start) < 2) {
    pcntl_signal_dispatch();
    usleep(10000);
}

exit($signalReceived ? 0 : 1);
PHP,
                \var_export($this->autoloadPath, true),
            ),
        );

        $exitCode = $this->runPhpScript($scriptFile);
        self::assertSame(0, $exitCode, 'Deprecated Utils::reboot() should still send SIGUSR1 in a subprocess.');
    }

    private function runPhpScript(string $scriptFile): int
    {
        $proc = \proc_open(
            ['php', $scriptFile],
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
