<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\TestCase;

/**
 * E2E acceptance criteria for issue #934.
 *
 * Spawns one real Workerman worker with the max_requests reload strategy and
 * asserts that the worker PID really changes after the configured number of
 * requests — the missing link between a shouldReboot() verdict and a restarted
 * worker. The unit test proves the verdict sends SIGUSR1; this test proves the
 * signal restarts the worker.
 *
 * The server binds a free ephemeral port and never touches the shared test
 * daemon ports (8888/9999/9991, issue #868).
 *
 * @see https://github.com/crazy-goat/workerman-bundle/issues/934
 *
 * @group e2e
 */
final class MaxRequestsReloadE2ETest extends TestCase
{
    private const RUNNER = __DIR__ . '/Fixtures/max_requests_reload_e2e_runner.php';

    private string $tempDir = '';
    private string $autoloadPath = '';

    /** @var resource|null */
    private $process;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private ?array $pipes = null;

    private int $port = 0;

    protected function setUp(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required.');
        }

        $autoloadPath = \realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoloadPath === false) {
            self::markTestSkipped('vendor/autoload.php not found.');
        }
        $this->autoloadPath = $autoloadPath;

        $this->tempDir = \sys_get_temp_dir() . '/workerman_934_e2e_' . \bin2hex(\random_bytes(4));
        if (!\mkdir($this->tempDir, 0700) && !\is_dir($this->tempDir)) {
            self::markTestSkipped('Cannot create temp dir: ' . $this->tempDir);
        }

        $this->port = $this->allocatePort();
        $this->process = null;
        $this->pipes = null;
    }

    protected function tearDown(): void
    {
        $this->stopWorker();

        if ($this->tempDir !== '' && \is_dir($this->tempDir)) {
            $this->removeTree($this->tempDir);
        }
    }

    public function testWorkerPidChangesAfterMaxRequests(): void
    {
        $firstPid = $this->startServer(3);

        $this->assertSame($firstPid, $this->requestPid(), 'Request 1 must be served by the started worker');
        $this->assertSame($firstPid, $this->requestPid(), 'Request 2 must stay on the same worker');
        $this->assertSame($firstPid, $this->requestPid(), 'Request 3 must still be served by the old worker');

        $this->assertTrue(
            Wait::until(fn(): bool => $this->readWorkerPid() !== $firstPid, 10),
            'The worker must be replaced after the third request reaches max_requests',
        );

        $secondPid = $this->readWorkerPid();
        $this->assertNotSame($firstPid, $secondPid, 'The replacement worker must have a new PID');

        $fourthPid = $this->requestPid();
        $this->assertSame($secondPid, $fourthPid, 'Request 4 must be served by the replacement worker');
        $this->assertNotSame($firstPid, $fourthPid, 'The PID must change after the third request');
    }

    private function startServer(int $requests): int
    {
        $readyFile = $this->tempDir . '/ready';
        $pidFile = $this->tempDir . '/worker.pid';
        $workDir = $this->tempDir . '/wm';
        @\unlink($readyFile);
        @\unlink($pidFile);

        $command = [
            PHP_BINARY,
            self::RUNNER,
            $this->autoloadPath,
            (string) $this->port,
            $readyFile,
            $pidFile,
            $workDir,
            (string) $requests,
        ];

        $process = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );

        if (!\is_resource($process)) {
            $this->fail('Failed to start reload E2E worker process');
        }
        $this->process = $process;
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        $this->pipes = $pipes;
        \fclose($this->pipes[0]);

        $ready = Wait::until(function (): bool {
            if (!\is_file($this->tempDir . '/ready') || !\is_file($this->tempDir . '/worker.pid')) {
                return false;
            }
            $proc = $this->process;
            if ($proc === null) {
                return true;
            }
            if (\proc_get_status($proc)['running'] === false) {
                return true;
            }

            return $this->portIsOpen();
        }, 15);

        $status = \proc_get_status($process);
        if (!$ready || $status['running'] === false) {
            $stderr = \stream_get_contents($pipes[2]) ?: '';
            $stdout = \stream_get_contents($pipes[1]) ?: '';
            $this->fail(sprintf(
                "Worker did not become ready within 15s.\nstderr: %s\nstdout: %s",
                $stderr,
                $stdout,
            ));
        }

        return $this->readWorkerPid();
    }

    private function requestPid(): int
    {
        $response = $this->sendRaw("GET /pid HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        $separator = \strpos($response, "\r\n\r\n");
        if ($separator === false) {
            $this->fail('Malformed HTTP response: ' . $this->summarize($response));
        }

        $this->assertStringContainsString('200 OK', \substr($response, 0, $separator));
        $body = \trim(\substr($response, $separator + 4));
        $this->assertMatchesRegularExpression('/^\d+$/', $body, 'The route must return the worker PID');

        return (int) $body;
    }

    private function readWorkerPid(): int
    {
        $pidFile = $this->tempDir . '/worker.pid';
        if (!\is_file($pidFile)) {
            return 0;
        }

        return (int) \trim((string) \file_get_contents($pidFile));
    }

    private function stopWorker(): void
    {
        if ($this->process === null) {
            return;
        }

        $status = \proc_get_status($this->process);
        if ($status['running']) {
            $masterPid = $status['pid'];
            @\posix_kill($masterPid, SIGTERM);

            $childPid = $this->readWorkerPid();
            if ($childPid > 0) {
                @\posix_kill($childPid, SIGTERM);
            }

            $stopped = Wait::until(function (): bool {
                $proc = $this->process;

                return $proc === null || !\proc_get_status($proc)['running'];
            }, 3);
            if (!$stopped) {
                @\posix_kill($masterPid, SIGKILL);
            }
        }

        if ($this->pipes !== null) {
            foreach ([1, 2] as $fd) {
                if (\is_resource($this->pipes[$fd])) {
                    \stream_set_blocking($this->pipes[$fd], false);
                    \stream_get_contents($this->pipes[$fd]);
                    \fclose($this->pipes[$fd]);
                }
            }
            $this->pipes = null;
        }

        @\proc_close($this->process);
        $this->process = null;
    }

    private function sendRaw(string $payload): string
    {
        $errno = 0;
        $errstr = '';
        $socket = @\fsockopen('127.0.0.1', $this->port, $errno, $errstr, 2.0);
        if ($socket === false) {
            $this->fail(sprintf('fsockopen failed: %s (%d)', $errstr, $errno));
        }

        \stream_set_timeout($socket, 2);
        $written = \fwrite($socket, $payload);
        if ($written === false || $written !== \strlen($payload)) {
            \fclose($socket);
            $this->fail('Failed to write full request payload');
        }

        $response = '';
        while (!\feof($socket)) {
            $chunk = \fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (\strlen($response) > 4096) {
                break;
            }
        }
        \fclose($socket);

        if ($response === '') {
            $this->fail('Empty response from worker');
        }

        return $response;
    }

    private function portIsOpen(): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @\fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
        if ($socket === false) {
            return false;
        }
        \fclose($socket);

        return true;
    }

    private function allocatePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            $this->fail('Cannot allocate free port');
        }
        $name = \stream_socket_get_name($socket, false);
        \fclose($socket);
        if ($name === false || !\str_contains($name, ':')) {
            $this->fail('Cannot parse allocated port');
        }
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    private function summarize(string $response): string
    {
        $trimmed = \preg_replace('/[^\x20-\x7E\r\n]/', '.', $response) ?? $response;

        return \strlen($trimmed) > 200 ? \substr($trimmed, 0, 200) . '…' : $trimmed;
    }

    private function removeTree(string $dir): void
    {
        $entries = \scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (\is_dir($path) && !\is_link($path)) {
                $this->removeTree($path);
                continue;
            }
            @\unlink($path);
        }
        @\rmdir($dir);
    }
}
