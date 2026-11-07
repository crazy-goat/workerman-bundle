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

$signalReceived = \CrazyGoat\WorkermanBundle\Util\Wait::until(
    static function () use (&$signalReceived): bool {
        pcntl_signal_dispatch();

        return $signalReceived;
    },
    2,
    initialDelayMs: 10,
    maxDelayMs: 10,
);

exit($signalReceived ? 0 : 1);
PHP,
                \var_export($this->autoloadPath, true),
            ),
        );

        $exitCode = $this->runPhpScript($scriptFile);
        self::assertSame(0, $exitCode, 'Utils::reload() should send SIGUSR1 in a subprocess.');
    }

    public function testReloadReturnsTrueInSubprocess(): void
    {
        $scriptFile = $this->tempDir . '/test_reload_returns_true.php';
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

$result = \CrazyGoat\WorkermanBundle\Utils::reload();
pcntl_signal_dispatch();

exit($result && $signalReceived ? 0 : 1);
PHP,
                \var_export($this->autoloadPath, true),
            ),
        );

        $exitCode = $this->runPhpScript($scriptFile);
        self::assertSame(0, $exitCode, 'Utils::reload() should return true when the signal is delivered.');
    }

    public function testReloadAllWorkersReportsFailedSignalWhenParentIsGone(): void
    {
        if (\function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Root can signal PID 1, so the failed-signal path cannot be exercised here.');
        }

        $scriptFile = $this->tempDir . '/test_reload_parent_gone.php';
        \file_put_contents(
            $scriptFile,
            \sprintf(
                <<<'PHP'
<?php
require %s;

$pid = pcntl_fork();
if ($pid === -1) {
    echo "FORK_FAILED\n";
    exit(2);
}
if ($pid > 0) {
    // Parent exits at once so the child is reparented to PID 1, a process
    // a non-root user cannot signal.
    exit(0);
}

// Orphaned child: only ever signal PID 1. Any other reparenting target
// (a subreaper) is left alone so no unrelated process is signalled.
usleep(200000);
$ppid = posix_getppid();
if ($ppid !== 1) {
    echo "PPID:$ppid\n";
    exit(3);
}

$result = \CrazyGoat\WorkermanBundle\Utils::reload(reloadAllWorkers: true);
echo $result ? "RESULT:true\n" : "RESULT:false\n";
exit($result ? 1 : 0);
PHP,
                \var_export($this->autoloadPath, true),
            ),
        );

        $run = $this->runPhpScriptCapturing($scriptFile);
        if (\str_contains($run['stdout'], 'PPID:')) {
            self::markTestSkipped('The orphan was reparented to a subreaper instead of PID 1.');
        }

        self::assertSame(0, $run['exitCode'], 'Utils::reload(reloadAllWorkers: true) should return false when the parent is gone.');
        self::assertStringContainsString('RESULT:false', $run['stdout']);
        self::assertStringContainsString(
            'Utils::reload() could not send SIGUSR1',
            $run['stderr'],
            'A failed reload must be visible in the error log.',
        );
    }

    public function testReloadAllWorkersSendsSigusr1ToParentProcess(): void
    {
        $signalReceived = false;
        $previousHandler = \pcntl_signal_get_handler(SIGUSR1);

        \pcntl_signal(SIGUSR1, static function () use (&$signalReceived): void {
            $signalReceived = true;
        });

        try {
            $scriptFile = $this->tempDir . '/test_reload_all_workers.php';
            \file_put_contents(
                $scriptFile,
                \sprintf(
                    <<<'PHP'
<?php
require %s;

$signalReceived = false;
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, function () use (&$signalReceived) {
    $signalReceived = true;
});

\CrazyGoat\WorkermanBundle\Utils::reload(reloadAllWorkers: true);

usleep(200000);
pcntl_signal_dispatch();

exit($signalReceived ? 1 : 0);
PHP,
                    \var_export($this->autoloadPath, true),
                ),
            );

            $exitCode = $this->runPhpScript($scriptFile);

            \CrazyGoat\WorkermanBundle\Util\Wait::until(
                static function () use (&$signalReceived): bool {
                    \pcntl_signal_dispatch();

                    return $signalReceived;
                },
                1,
                initialDelayMs: 10,
                maxDelayMs: 10,
            );

            self::assertSame(
                0,
                $exitCode,
                'The child process must not receive the SIGUSR1 sent by reload(reloadAllWorkers: true).',
            );
            self::assertTrue(
                $signalReceived,
                'Utils::reload(reloadAllWorkers: true) should send SIGUSR1 to the parent process.',
            );
        } finally {
            \pcntl_signal(SIGUSR1, $previousHandler);
        }
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

$signalReceived = \CrazyGoat\WorkermanBundle\Util\Wait::until(
    static function () use (&$signalReceived): bool {
        pcntl_signal_dispatch();

        return $signalReceived;
    },
    2,
    initialDelayMs: 10,
    maxDelayMs: 10,
);

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

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runPhpScriptCapturing(string $scriptFile): array
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
        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        return [
            'exitCode' => \proc_close($proc),
            'stdout' => \is_string($stdout) ? $stdout : '',
            'stderr' => \is_string($stderr) ? $stderr : '',
        ];
    }
}
