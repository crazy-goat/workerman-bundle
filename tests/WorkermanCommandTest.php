<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Command\ServerAction;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
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
        \usleep(500_000);

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

        \usleep(500_000);

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

        \usleep(500_000);
        $client = new Client(['http_errors' => false]);
        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test');
        self::assertSame(200, $response->getStatusCode());
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

        self::assertFileExists($pidFile, 'PID file must exist for the daemonised test server');
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

        self::assertFileExists($pidFile, 'Prerequisite: daemonised test server must be running');

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
}
