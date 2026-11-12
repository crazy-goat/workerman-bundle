<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/crazy-goat/workerman-bundle/issues/987
 * @see https://github.com/crazy-goat/workerman-bundle/issues/910
 */
#[Group('e2e')]
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

    public function testGracefulSigtermLetsRequestFinishPastStopTimeout(): void
    {
        $masterPid = $this->startServer(gracefulSigterm: true, stopTimeout: 1, gracefulStopTimeout: 10);

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGTERM));

        $this->assertStringContainsString('200 OK', $body);
        $this->assertStringContainsString('done', $body);
    }

    public function testGracefulSigtermKillsAfterGracefulStopTimeout(): void
    {
        $masterPid = $this->startServer(gracefulSigterm: true, stopTimeout: 1, gracefulStopTimeout: 1);

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGTERM));

        $this->assertStringNotContainsString('200 OK', $body, 'The graceful stop backstop must cut the request');
        $this->assertTrue(
            Wait::until(fn(): bool => !\proc_get_status($this->processResource())['running'], 10),
            'The server must stop after the graceful stop timeout',
        );
    }

    public function testSigtermWithoutGracefulOptionKeepsFastStop(): void
    {
        $masterPid = $this->startServer(gracefulSigterm: false, stopTimeout: 1);

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGTERM));

        $this->assertStringNotContainsString('200 OK', $body, 'The default SIGTERM handling must stay a fast stop');
        $this->assertTrue(
            Wait::until(fn(): bool => !\proc_get_status($this->processResource())['running'], 10),
            'The server must stop after stop_timeout',
        );
    }

    public function testGracefulSigquitLetsRequestFinishPastStopTimeout(): void
    {
        $masterPid = $this->startServer(stopTimeout: 1, gracefulStopTimeout: 10);

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGQUIT));

        $this->assertStringContainsString('200 OK', $body);
        $this->assertStringContainsString('done', $body);
    }

    public function testGracefulSigquitBackstopKillsAfterGracefulStopTimeout(): void
    {
        $masterPid = $this->startServer(stopTimeout: 1, gracefulStopTimeout: 1);

        $body = $this->requestDuring(fn(): bool => \posix_kill($masterPid, SIGQUIT));

        $this->assertStringNotContainsString('200 OK', $body, 'The graceful stop backstop must cut the request');
        $this->assertTrue(
            Wait::until(fn(): bool => !\proc_get_status($this->processResource())['running'], 10),
            'The server must stop after the graceful stop timeout',
        );
    }

    public function testGracefulSigquitBackstopKillsBlockingWorker(): void
    {
        $masterPid = $this->startServer(stopTimeout: 1, gracefulStopTimeout: 1, blocking: true);

        \posix_kill($masterPid, SIGQUIT);

        self::assertTrue(
            Wait::until(fn(): bool => !\proc_get_status($this->processResource())['running'], 10),
            'The backstop must end a worker whose onWorkerStart never returns',
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

    private function startServer(
        bool $gracefulSigterm = false,
        int $stopTimeout = 10,
        int $gracefulStopTimeout = 30,
        bool $blocking = false,
    ): int {
        $readyFile = $this->tempDir . '/ready';
        $process = \proc_open(
            [
                PHP_BINARY,
                self::RUNNER,
                (string) \realpath(__DIR__ . '/../vendor/autoload.php'),
                (string) $this->port,
                $readyFile,
                $this->tempDir . '/wm',
                $gracefulSigterm ? '1' : '0',
                (string) $stopTimeout,
                (string) $gracefulStopTimeout,
                $blocking ? '1' : '0',
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
