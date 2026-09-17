<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use CrazyGoat\WorkermanBundle\Util\Wait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkermanCommandTest extends KernelTestCase
{
    public function testInvalidAction(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => 'invalid']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Invalid action', $tester->getDisplay());
    }

    public function testStatusShowsRunningServer(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => ServerAction::STATUS->value]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Worker', $tester->getDisplay());
    }

    public function testConnectionsShowsOutput(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => ServerAction::CONNECTIONS->value]);

        $tester->assertCommandIsSuccessful();
    }

    public function testStopAndStartViaCli(): void
    {
        $client = new Client(['http_errors' => false]);

        // Server is running (started by bootstrap.php) — verify HTTP works.
        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test');
        self::assertSame(200, $response->getStatusCode());

        // Stop via console command.
        \shell_exec(\workerman_create_console_command('stop'));
        self::assertTrue($this->waitForPortDown(8888, 5), 'Server port 8888 should be down after stop');

        // Server should be down.
        try {
            $client->request('GET', 'http://127.0.0.1:8888/response_test', ['timeout' => 1]);
            self::fail('Expected connection to fail after stop');
        } catch (ConnectException) {
        }

        // Restart via index.php (Runtime path — works with proc_open).
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open(\workerman_create_command('start -d'), $descriptor, $pipes);

        if (\is_resource($process)) {
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }
            \proc_close($process);
        }

        self::assertTrue($this->waitForPortUp(8888, 10), 'Server port 8888 should be back up after restart');

        // Server should be back up.
        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello from test controller', (string) $response->getBody());
    }

    public function testReloadDoesNotBreakServer(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => ServerAction::RELOAD->value]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('reload signal sent', $tester->getDisplay());

        // The listening socket can stay up while workers are restarting.
        $this->assertHttpReadyAfterReload(new Client());
    }

    public function testReloadHttpReadinessRetriesTransientTransportFailures(): void
    {
        $request = new Request('GET', 'http://127.0.0.1:8888/response_test');
        $handler = new MockHandler([
            new ConnectException('Connection refused', $request, null, ['errno' => 7]),
            new ConnectException('Operation timed out', $request, null, ['errno' => 28]),
            new ConnectException('Empty reply from server', $request, null, ['errno' => 52]),
            new RequestException('Connection reset by peer', $request, null, null, ['errno' => 56]),
            new Response(200),
        ]);

        $this->assertHttpReadyAfterReload(new Client(['handler' => HandlerStack::create($handler)]));

        self::assertCount(0, $handler);
        self::assertSame(1, $handler->getLastOptions()['timeout']);
        self::assertSame(0.2, $handler->getLastOptions()['connect_timeout']);
    }

    public function testReloadHttpReadinessFailsWhenTransportFailurePersists(): void
    {
        $request = new Request('GET', 'http://127.0.0.1:8888/response_test');
        $handler = new MockHandler([
            new RequestException('Connection reset by peer', $request, null, null, ['errno' => 56]),
            new Response(200),
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('HTTP 200 after reload within 0 seconds; last transport error: Connection reset by peer');

        // A zero deadline deterministically exercises exhaustion after the first failed probe.
        try {
            $this->assertHttpReadyAfterReload(new Client(['handler' => HandlerStack::create($handler)]), 0);
        } finally {
            self::assertCount(1, $handler, 'Must not probe again after the deadline');
        }
    }

    public function testReloadHttpReadinessDoesNotRetryUnexpectedHttpStatus(): void
    {
        $handler = new MockHandler([new Response(500), new Response(200)]);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unexpected HTTP status after reload');

        try {
            $this->assertHttpReadyAfterReload(new Client(['handler' => HandlerStack::create($handler)]));
        } finally {
            self::assertCount(1, $handler);
        }
    }

    public function testReloadHttpReadinessDoesNotRetryUnexpectedTransportError(): void
    {
        $request = new Request('GET', 'http://127.0.0.1:8888/response_test');
        $error = new RequestException('Malformed response', $request, null, null, ['errno' => 8]);
        $handler = new MockHandler([$error, new Response(200)]);
        $this->expectExceptionObject($error);

        try {
            $this->assertHttpReadyAfterReload(new Client(['handler' => HandlerStack::create($handler)]));
        } finally {
            self::assertCount(1, $handler);
        }
    }

    private function assertHttpReadyAfterReload(Client $client, int $timeoutSeconds = 5): void
    {
        $lastError = 'none';
        $ready = Wait::until(static function () use ($client, &$lastError): bool {
            try {
                $response = $client->request('GET', 'http://127.0.0.1:8888/response_test', [
                    'http_errors' => false,
                    'allow_redirects' => false,
                    'connect_timeout' => 0.2,
                    'timeout' => 1,
                ]);
            } catch (ConnectException | RequestException $exception) {
                // cURL: connect failure, timeout, empty reply, receive/reset failure.
                // Do not hide HTTP responses or unrelated transport/configuration errors.
                if (($exception instanceof RequestException && $exception->hasResponse())
                    || !in_array($exception->getHandlerContext()['errno'] ?? null, [7, 28, 52, 56], true)) {
                    throw $exception;
                }
                $lastError = $exception->getMessage();

                return false;
            }

            self::assertSame(200, $response->getStatusCode(), 'Unexpected HTTP status after reload');

            return true;
        }, $timeoutSeconds);

        self::assertTrue($ready, sprintf(
            'Expected HTTP 200 after reload within %d seconds; last transport error: %s',
            $timeoutSeconds,
            $lastError,
        ));
    }

    /**
     * Regression test for issue #584: the real master process must
     * record a fingerprint in daemon mode.
     *
     * The test server is started with `start -d` by phpunit's bootstrap,
     * so this asserts the daemon-mode path end to end: the fingerprint
     * sidecar exists next to the PID file, describes the same PID, and
     * passes `ProcessInspector::matchesFingerprint()`.
     */
    public function testDaemonModeWritesMasterFingerprint(): void
    {
        $pidFile = self::bootKernel()->getProjectDir() . '/var/run/workerman.pid';
        $fingerprintPath = $pidFile . '.fingerprint';

        if (!\is_file($pidFile)) {
            self::markTestSkipped('Background daemon server is not running (started by phpunit bootstrap)');
        }
        self::assertFileExists(
            $fingerprintPath,
            'Master fingerprint must be written in daemon mode (issue #584)',
        );

        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::readFrom($fingerprintPath);
        self::assertNotNull($fingerprint, 'Fingerprint must be parseable');

        $masterPid = (int) file_get_contents($pidFile);
        self::assertSame($masterPid, $fingerprint->pid, 'Fingerprint PID must match the PID file');

        $inspector = new \CrazyGoat\WorkermanBundle\ProcessInspector();
        self::assertTrue(
            $inspector->matchesFingerprint($masterPid, $fingerprint),
            'Fingerprint must verify the real master process',
        );
    }

    /**
     * End-to-end regression test for issue #584: a stale pid file that
     * points at a reused (unrelated) PID must not result in any signal
     * being sent by the console `stop` command.
     *
     * Simulates the reported scenario: master died, kernel reassigned
     * the PID to a plain PHP process, no fingerprint is present. The
     * stop command must fail without signalling the foreign process.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testStopWithStalePidFileDoesNotSignalForeignProcess(): void
    {
        $pidFile = self::bootKernel()->getProjectDir() . '/var/run/workerman.pid';
        $fingerprintPath = $pidFile . '.fingerprint';

        if (!\is_file($pidFile)) {
            self::markTestSkipped('Background daemon server is not running (started by phpunit bootstrap)');
        }

        $originalPid = (string) file_get_contents($pidFile);
        $originalFingerprint = \is_file($fingerprintPath) ? (string) file_get_contents($fingerprintPath) : null;

        // Foreign "reused" process: a plain PHP child whose cmdline
        // contains "php" but not the Workerman master title.
        $child = pcntl_fork();
        if ($child === -1) {
            self::markTestSkipped('pcntl_fork failed');
        }
        if ($child === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            file_put_contents($pidFile, (string) $child);
            @\unlink($fingerprintPath);

            \exec(
                \workerman_create_console_command('stop') . ' 2>&1',
                $output,
                $exitCode,
            );

            self::assertNotSame(
                0,
                $exitCode,
                'stop must fail when the PID file points at a non-master process',
            );
            self::assertTrue(
                \posix_kill($child, 0),
                'The foreign process must not have been signaled',
            );
        } finally {
            // Restore the real server's pid/fingerprint files.
            file_put_contents($pidFile, $originalPid);
            if ($originalFingerprint !== null) {
                file_put_contents($fingerprintPath, $originalFingerprint);
            } else {
                @\unlink($fingerprintPath);
            }

            \posix_kill($child, \SIGKILL);
            \pcntl_waitpid($child, $status);
        }
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('workerman:server'));
    }

    private function waitForPortUp(int $port, int $timeoutSeconds): bool
    {
        return Wait::until(static function () use ($port): bool {
            $sock = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if ($sock !== false) {
                \fclose($sock);

                return true;
            }

            return false;
        }, $timeoutSeconds);
    }

    private function waitForPortDown(int $port, int $timeoutSeconds): bool
    {
        return Wait::until(static function () use ($port): bool {
            $sock = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                \fclose($sock);

                return false;
            }

            return true;
        }, $timeoutSeconds);
    }
}
