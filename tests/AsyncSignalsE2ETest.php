<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\TestCase;

/**
 * E2E test for issue #987.
 *
 * Symfony Console turns on pcntl_async_signals(true) and the workers inherit
 * it. A stop or reload signal then runs the Workerman handler in the middle
 * of a request, on the Select loop. The request must end first.
 *
 * @see https://github.com/crazy-goat/workerman-bundle/issues/987
 *
 * @group e2e
 */
final class AsyncSignalsE2ETest extends TestCase
{
    private const RUNNER = __DIR__ . '/Fixtures/async_signals_e2e_runner.php';

    private string $tempDir = '';

    private int $port = 0;

    /** @var resource|null */
    private $process;

    protected function setUp(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix extensions are required.');
        }

        $this->tempDir = \sys_get_temp_dir() . '/workerman_987_e2e_' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempDir, 0700);
        $this->port = $this->allocatePort();
    }

    protected function tearDown(): void
    {
        if ($this->process !== null) {
            $status = \proc_get_status($this->process);
            if ($status['running']) {
                @\posix_kill($status['pid'], SIGKILL);
            }
            @\proc_close($this->process);
            $this->process = null;
        }
        $workerPid = $this->readWorkerPid();
        if ($workerPid > 0) {
            @\posix_kill($workerPid, SIGKILL);
        }
        if ($this->tempDir !== '' && \is_dir($this->tempDir)) {
            \exec('rm -rf ' . \escapeshellarg($this->tempDir));
        }
    }

    public function testRunningRequestEndsBeforeStop(): void
    {
        $masterPid = $this->startServer();

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGTERM));

        $this->assertStringContainsString('200 OK', $body);
        $this->assertStringContainsString('done', $body);
        $this->assertTrue(
            Wait::until(fn(): bool => !\proc_get_status($this->processResource())['running'], 10),
            'The server must stop after the request ended',
        );
    }

    public function testRunningRequestEndsBeforeReload(): void
    {
        $masterPid = $this->startServer();

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGUSR1));

        $this->assertStringContainsString('200 OK', $body);
        $this->assertStringContainsString('done', $body);
    }

    /**
     * Send the slow request, run $signal after 0.5 s and return the reply.
     *
     * @param callable(): mixed $signal
     */
    private function requestDuring(callable $signal): string
    {
        $socket = \stream_socket_client(\sprintf('tcp://127.0.0.1:%d', $this->port), $errno, $errstr, 2.0);
        $this->assertNotFalse($socket, \sprintf('connect failed: %s (%d)', $errstr, $errno));
        \fwrite($socket, "GET /slow HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        \usleep(500_000);
        $signal();

        \stream_set_timeout($socket, 8);
        $response = (string) \stream_get_contents($socket);
        \fclose($socket);

        return $response;
    }

    private function startServer(): int
    {
        $readyFile = $this->tempDir . '/ready';
        $process = \proc_open(
            [
                PHP_BINARY,
                self::RUNNER,
                (string) \realpath(__DIR__ . '/../vendor/autoload.php'),
                (string) $this->port,
                $readyFile,
                $this->tempDir . '/wm',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', $this->tempDir . '/out.log', 'a'], 2 => ['file', $this->tempDir . '/err.log', 'a']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process);
        $this->process = $process;
        \fclose($pipes[0]);

        $ready = Wait::until(static fn(): bool => \is_file($readyFile) && \trim((string) \file_get_contents($readyFile)) !== '', 15);
        $this->assertTrue($ready, 'Server did not start: ' . @\file_get_contents($this->tempDir . '/err.log'));

        return \proc_get_status($process)['pid'];
    }

    /**
     * @return resource
     */
    private function processResource()
    {
        $this->assertIsResource($this->process);

        return $this->process;
    }

    private function readWorkerPid(): int
    {
        $file = $this->tempDir . '/ready';

        return \is_file($file) ? (int) \trim((string) \file_get_contents($file)) : 0;
    }

    private function allocatePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }
}
