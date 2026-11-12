<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use CrazyGoat\WorkermanBundle\Util\Wait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkermanCommandTest extends KernelTestCase
{
    use RequiresLiveServer;
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
        self::assertStringContainsString('CONNECTION STATUS', $tester->getDisplay());
        self::assertStringNotContainsString('No connection data available', $tester->getDisplay());
    }

    public function testRestartViaCliChangesMasterPidAndStartsWhenStopped(): void
    {
        $client = BoundedHttpClient::create();
        $pidFile = self::bootKernel()->getProjectDir() . '/var/run/workerman.pid';
        self::assertFileExists($pidFile);
        $before = (int) file_get_contents($pidFile);
        self::assertGreaterThan(0, $before, 'The live daemon must have a PID');
        self::assertTrue(\posix_kill($before, 0), 'The recorded master PID must be alive');

        try {
            \exec(\workerman_create_console_command('restart -d') . ' 2>&1', $restartOutput, $restartCode);
            self::assertSame(0, $restartCode, implode("\n", $restartOutput));
            self::assertTrue($this->waitForPortUp(TestPorts::http(), 10), sprintf('Server port %d should be up after restart', TestPorts::http()));
            $this->assertHttpReadyAfterReload($client);

            $after = (int) file_get_contents($pidFile);
            self::assertGreaterThan(0, $after, 'The restarted daemon must have a PID');
            self::assertNotSame($before, $after, 'restart must start a new master process');
            self::assertSame(200, $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'))->getStatusCode());

            \exec(\workerman_create_console_command('stop') . ' 2>&1', $stopOutput, $stopCode);
            self::assertSame(0, $stopCode, implode("\n", $stopOutput));
            self::assertTrue($this->waitForPortDown(TestPorts::http(), 5), sprintf('Server port %d should be down after stop', TestPorts::http()));

            \exec(\workerman_create_console_command('restart -d') . ' 2>&1', $startOutput, $startCode);
            self::assertSame(0, $startCode, implode("\n", $startOutput));
            self::assertTrue($this->waitForPortUp(TestPorts::http(), 10), 'restart must start a stopped server');
            $this->assertHttpReadyAfterReload($client);
            self::assertSame(200, $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'))->getStatusCode());
        } finally {
            if (!$this->waitForPortUp(TestPorts::http(), 1)) {
                \exec(\workerman_create_console_command('start -d') . ' 2>&1');
                $this->waitForPortUp(TestPorts::http(), 10);
            }
        }
    }

    public function testStopAndStartViaCli(): void
    {
        $client = BoundedHttpClient::create();

        $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'));
        self::assertSame(200, $response->getStatusCode());

        \shell_exec(\workerman_create_console_command('stop'));
        self::assertTrue($this->waitForPortDown(TestPorts::http(), 5), sprintf('Server port %d should be down after stop', TestPorts::http()));

        try {
            $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'));
            self::fail('Expected connection to fail after stop');
        } catch (ConnectException) {
        }

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open(\workerman_create_command('start -d'), $descriptor, $pipes);

        if (\is_resource($process)) {
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }
            \proc_close($process);
        }

        self::assertTrue($this->waitForPortUp(TestPorts::http(), 10), sprintf('Server port %d should be back up after restart', TestPorts::http()));

        $this->assertHttpReadyAfterReload($client);

        $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello from test controller', (string) $response->getBody());
    }

    public function testReloadDoesNotBreakServer(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => ServerAction::RELOAD->value]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('reload signal sent', $tester->getDisplay());

        $this->assertHttpReadyAfterReload(BoundedHttpClient::create());
    }

    public function testReloadHttpReadinessRetriesTransientTransportFailures(): void
    {
        $request = new Request('GET', TestPorts::url(TestPorts::http(), '/response_test'));
        $handler = new MockHandler([
            new ConnectException('Connection refused', $request),
            new NetworkTimeoutException('Operation timed out', $request),
            new NetworkException('Empty reply from server', $request),
            new NetworkException('Connection reset by peer', $request),
            new Response(200),
        ]);

        $this->assertHttpReadyAfterReload(BoundedHttpClient::create([], $handler));

        self::assertCount(0, $handler);
        self::assertSame(1, $handler->getLastOptions()['timeout']);
        self::assertSame(0.2, $handler->getLastOptions()['connect_timeout']);
    }

    public function testReloadHttpReadinessFailsWhenTransportFailurePersists(): void
    {
        $request = new Request('GET', TestPorts::url(TestPorts::http(), '/response_test'));
        $handler = new MockHandler([
            new NetworkException('Connection reset by peer', $request),
            new Response(200),
        ]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('HTTP 200 after reload within 0 seconds; last transport error: Connection reset by peer');

        try {
            $this->assertHttpReadyAfterReload(BoundedHttpClient::create([], $handler), 0);
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
            $this->assertHttpReadyAfterReload(BoundedHttpClient::create([], $handler));
        } finally {
            self::assertCount(1, $handler);
        }
    }

    public function testReloadHttpReadinessDoesNotRetryUnexpectedTransportError(): void
    {
        $request = new Request('GET', TestPorts::url(TestPorts::http(), '/response_test'));
        $error = new RequestException('Malformed response', $request);
        $handler = new MockHandler([$error, new Response(200)]);
        $this->expectExceptionObject($error);

        try {
            $this->assertHttpReadyAfterReload(BoundedHttpClient::create([], $handler));
        } finally {
            self::assertCount(1, $handler);
        }
    }

    private function assertHttpReadyAfterReload(Client $client, int $timeoutSeconds = 5): void
    {
        $lastError = 'none';
        $ready = Wait::until(static function () use ($client, &$lastError): bool {
            try {
                $response = $client->request('GET', TestPorts::url(TestPorts::http(), '/response_test'), [
                    ...BoundedHttpClient::OPTIONS,
                    'allow_redirects' => false,
                ]);
            } catch (NetworkException $exception) {
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

    public function testDaemonModeWritesMasterFingerprint(): void
    {
        $pidFile = self::bootKernel()->getProjectDir() . '/var/run/workerman.pid';
        $fingerprintPath = $pidFile . '.fingerprint';

        if (!\is_file($pidFile)) {
            self::failLiveServer('Background daemon server is not running (started by phpunit bootstrap)');
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

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testStopWithStalePidFileDoesNotSignalForeignProcess(): void
    {
        $pidFile = self::bootKernel()->getProjectDir() . '/var/run/workerman.pid';
        $fingerprintPath = $pidFile . '.fingerprint';

        if (!\is_file($pidFile)) {
            self::failLiveServer('Background daemon server is not running (started by phpunit bootstrap)');
        }

        $originalPid = (string) file_get_contents($pidFile);
        $originalFingerprint = \is_file($fingerprintPath) ? (string) file_get_contents($fingerprintPath) : null;

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
            $sock = @\fsockopen(TestPorts::connectHost(), $port, $errno, $errstr, 0.2);

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
            $sock = @\fsockopen(TestPorts::connectHost(), $port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                \fclose($sock);

                return false;
            }

            return true;
        }, $timeoutSeconds);
    }
}
