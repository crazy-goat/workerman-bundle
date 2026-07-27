<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * E2E acceptance criteria for issue #577.
 *
 * Spawns a real single-worker Workerman process, sends control-byte
 * requests over a real socket, and asserts the worker PID never changes.
 *
 * @see https://github.com/crazy-goat/workerman-bundle/issues/577
 *
 * @group e2e
 */
final class ControlByteWorkerDosE2ETest extends TestCase
{
    private const RUNNER = __DIR__ . '/Fixtures/control_byte_dos_e2e_runner.php';

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

        $this->tempDir = \sys_get_temp_dir() . '/workerman_577_e2e_' . \bin2hex(\random_bytes(4));
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

    /**
     * AC: control byte → 400 and worker PID unchanged.
     */
    public function testControlByteReturns400AndWorkerPidUnchanged(): void
    {
        $workerPid = $this->startWorker('handler');

        $before = $this->readWorkerPid();
        $this->assertSame($workerPid, $before, 'PID file must match ready-reported pid');

        $response = $this->sendRaw(
            "GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n",
        );

        $this->assertStringContainsString(
            '400',
            $response,
            'Control-byte request must receive HTTP 400, got: ' . $this->summarize($response),
        );
        $this->assertStringContainsString('Bad Request', $response);

        // Brief settle so a crashing worker would have exited / been respawned.
        \usleep(200_000);

        $after = $this->readWorkerPid();
        $this->assertSame(
            $before,
            $after,
            'Worker PID must be unchanged after a control-byte request (issue #577)',
        );
        $this->assertTrue(
            \posix_kill($after, 0),
            'Worker process must still be alive after control-byte request',
        );
    }

    /**
     * AC: soak 10 000 malformed requests; PID never changes.
     */
    public function testSoak10kMalformedRequestsKeepsWorkerPidStable(): void
    {
        $workerPid = $this->startWorker('handler');

        $malformed = "GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n";
        $okCount = 0;

        for ($i = 0; $i < 10_000; ++$i) {
            $response = $this->sendRaw($malformed);
            if (\str_contains($response, '400')) {
                ++$okCount;
            } else {
                $this->fail(sprintf(
                    'Request #%d did not return 400 (PID may have died). Response: %s',
                    $i,
                    $this->summarize($response),
                ));
            }

            if ($i > 0 && $i % 1000 === 0) {
                $current = $this->readWorkerPid();
                $this->assertSame(
                    $workerPid,
                    $current,
                    sprintf('Worker PID changed during soak at request #%d', $i),
                );
            }
        }

        $this->assertSame(10_000, $okCount);
        $this->assertSame(
            $workerPid,
            $this->readWorkerPid(),
            'Worker PID must be unchanged after 10k malformed requests',
        );
        $this->assertTrue(\posix_kill($workerPid, 0), 'Worker must still be alive after soak');
    }

    /**
     * AC: with handler-level try removed, errorHandler alone keeps the worker alive.
     */
    public function testBackstopAloneKeepsWorkerAliveOnControlByte(): void
    {
        $workerPid = $this->startWorker('backstop');

        // No 400 expected — the throw escapes onMessage into errorHandler,
        // which closes the connection without a response body.
        $response = $this->sendRaw(
            "GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n",
            expectMaybeEmpty: true,
        );

        // Should not be a clean 200 from the happy path.
        $this->assertStringNotContainsString(
            '200 OK',
            $response,
            'Backstop mode must not complete the happy-path 200 for a control-byte request',
        );

        \usleep(300_000);

        $after = $this->readWorkerPid();
        $this->assertSame(
            $workerPid,
            $after,
            'With only errorHandler backstop (no handler try), worker PID must stay alive',
        );
        $this->assertTrue(
            \posix_kill($after, 0),
            'Worker process must still be alive with backstop-only path',
        );

        // A subsequent benign request should still be served (worker not dead).
        $ok = $this->sendRaw("GET /hello HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        $this->assertStringContainsString(
            '200',
            $ok,
            'Worker must still serve a benign request after backstop handled a throw',
        );
    }

    /**
     * @return int Worker child PID
     */
    private function startWorker(string $mode): int
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
            $mode,
            $readyFile,
            $pidFile,
            $workDir,
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
            $this->fail('Failed to start E2E worker process');
        }
        $this->process = $process;
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        $this->pipes = $pipes;
        \fclose($this->pipes[0]);

        $deadline = \microtime(true) + 15.0;
        while (\microtime(true) < $deadline) {
            if (\is_file($readyFile) && \is_file($pidFile)) {
                $status = \proc_get_status($this->process);
                if ($status['running'] === false) {
                    break;
                }
                $pid = (int) \trim((string) \file_get_contents($pidFile));
                if ($pid > 0 && $this->portIsOpen()) {
                    return $pid;
                }
            }
            \usleep(50_000);
        }

        $stderr = \stream_get_contents($this->pipes[2]) ?: '';
        $stdout = \stream_get_contents($this->pipes[1]) ?: '';
        $this->fail(sprintf(
            "Worker did not become ready within 15s.\nstderr: %s\nstdout: %s",
            $stderr,
            $stdout,
        ));
    }

    private function stopWorker(): void
    {
        if ($this->process === null) {
            return;
        }

        $status = \proc_get_status($this->process);
        if ($status['running']) {
            // Kill process group / children: SIGTERM master then wait.
            $pid = $status['pid'];
            @\posix_kill($pid, SIGTERM);
            // Also signal worker child if known.
            $workerPidFile = $this->tempDir . '/worker.pid';
            if (\is_file($workerPidFile)) {
                $child = (int) \trim((string) \file_get_contents($workerPidFile));
                if ($child > 0) {
                    @\posix_kill($child, SIGTERM);
                }
            }
            $deadline = \microtime(true) + 3.0;
            while (\microtime(true) < $deadline) {
                $s = \proc_get_status($this->process);
                if (!$s['running']) {
                    break;
                }
                \usleep(50_000);
            }
            if (\proc_get_status($this->process)['running']) {
                @\posix_kill($pid, SIGKILL);
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

    private function readWorkerPid(): int
    {
        $pidFile = $this->tempDir . '/worker.pid';
        $this->assertFileExists($pidFile, 'Worker PID file must exist');
        $pid = (int) \trim((string) \file_get_contents($pidFile));
        $this->assertGreaterThan(0, $pid, 'Worker PID must be positive');

        return $pid;
    }

    private function sendRaw(string $payload, bool $expectMaybeEmpty = false): string
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
            // Enough for status line / small body.
            if (\strlen($response) > 4096) {
                break;
            }
        }
        \fclose($socket);

        if ($response === '' && !$expectMaybeEmpty) {
            $this->fail('Empty response from worker (connection closed without body?)');
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
            if (\is_dir($path)) {
                $this->removeTree($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
