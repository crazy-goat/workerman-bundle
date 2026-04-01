<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

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
        $tester->execute(['action' => 'status']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Worker', $tester->getDisplay());
    }

    public function testConnectionsShowsOutput(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => 'connections']);

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
        \proc_open(\workerman_create_command('start -d'), $descriptor, $pipes);
        \usleep(500_000);

        // Server should be back up.
        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello from test controller', (string) $response->getBody());
    }

    public function testReloadDoesNotBreakServer(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute(['action' => 'reload']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('reload signal sent', $tester->getDisplay());

        \usleep(500_000);
        $client = new Client(['http_errors' => false]);
        $response = $client->request('GET', 'http://127.0.0.1:8888/response_test');
        self::assertSame(200, $response->getStatusCode());
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('workerman:server'));
    }
}
