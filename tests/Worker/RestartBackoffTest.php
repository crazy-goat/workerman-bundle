<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Worker;

use CrazyGoat\WorkermanBundle\Worker\RestartBackoff;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Workerman\Worker;

final class RestartBackoffTest extends TestCase
{
    private string $tempDir;

    /** @var list<int> */
    private array $sleeps = [];

    private int $now = 1_000;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/restart-backoff-' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->sleeps = [];
        $this->now = 1_000;
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempDir);
    }

    public function testFirstStartDoesNotWait(): void
    {
        $backoff = $this->backoff(delay: 1);

        $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());

        $this->assertSame([], $this->sleeps);
    }

    public function testDelayDoublesAndIsCappedAtMaxDelay(): void
    {
        $backoff = $this->backoff(delay: 1, maxDelay: 5);

        for ($i = 0; $i < 6; $i++) {
            $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());
        }

        $this->assertSame([1, 2, 4, 5, 5], $this->sleeps);
    }

    public function testCounterResetsAfterAHealthyRun(): void
    {
        $backoff = $this->backoff(delay: 1, healthyAfter: 30);

        $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());
        $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());
        $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());
        $this->assertSame([1, 2], $this->sleeps);

        $this->now += 30;
        $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());
        $this->assertSame([1, 2], $this->sleeps);

        $backoff->beforeStart('slot', 'Process', 'worker', $this->noopLog());
        $this->assertSame([1, 2, 1], $this->sleeps);
    }

    public function testUnhealthyLineIsWrittenOnceAtMaxFailures(): void
    {
        $logs = [];
        $backoff = $this->backoff(delay: 1, maxFailures: 3);

        for ($i = 0; $i < 5; $i++) {
            $backoff->beforeStart('slot', 'Process', 'Queue consumer', static function (string $message) use (&$logs): void {
                $logs[] = $message;
            });
        }

        $this->assertSame(
            ['Process "Queue consumer" is unhealthy: 3 fast restarts'],
            $logs,
        );
    }

    public function testMaxFailuresOneLogsOnTheSecondStart(): void
    {
        $logs = [];
        $backoff = $this->backoff(delay: 1, maxFailures: 1);

        $backoff->beforeStart('slot', 'Process', 'worker', static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $this->assertSame([], $logs, 'A healthy first start must not be reported as a failure');

        $backoff->beforeStart('slot', 'Process', 'worker', static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $this->assertSame(['Process "worker" is unhealthy: 1 fast restarts'], $logs);
    }

    public function testMaxFailuresZeroTurnsTheUnhealthyLineOff(): void
    {
        $logs = [];
        $backoff = $this->backoff(delay: 1, maxFailures: 0);

        for ($i = 0; $i < 5; $i++) {
            $backoff->beforeStart('slot', 'Process', 'worker', static function (string $message) use (&$logs): void {
                $logs[] = $message;
            });
        }

        $this->assertSame([], $logs);
    }

    public function testDelayZeroTurnsTheWholeBackoffOff(): void
    {
        $logs = [];
        $backoff = $this->backoff(delay: 0, maxFailures: 1);

        for ($i = 0; $i < 5; $i++) {
            $backoff->beforeStart('slot', 'Process', 'worker', static function (string $message) use (&$logs): void {
                $logs[] = $message;
            });
        }

        $this->assertSame([], $this->sleeps);
        $this->assertSame([], $logs);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/restart');
    }

    public function testStateIsSeparatePerSlot(): void
    {
        $backoff = $this->backoff(delay: 1);

        $backoff->beforeStart('slot-a', 'Process', 'a', $this->noopLog());
        $backoff->beforeStart('slot-b', 'Process', 'b', $this->noopLog());
        $this->assertSame([], $this->sleeps, 'Each slot starts fresh');

        $backoff->beforeStart('slot-a', 'Process', 'a', $this->noopLog());
        $this->assertSame([1], $this->sleeps, 'Only slot-a has one failure');
    }

    public function testDelayForFailures(): void
    {
        $backoff = $this->backoff(delay: 2, maxDelay: 10);

        $this->assertSame(0, $backoff->delayForFailures(0));
        $this->assertSame(2, $backoff->delayForFailures(1));
        $this->assertSame(4, $backoff->delayForFailures(2));
        $this->assertSame(8, $backoff->delayForFailures(3));
        $this->assertSame(10, $backoff->delayForFailures(4));
        $this->assertSame(10, $backoff->delayForFailures(100));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testStopSignalEndsTheWaitEarly(): void
    {
        $readyFile = $this->tempDir . '/ready';
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../Fixtures/restart_backoff_signal.php', $this->tempDir, $readyFile],
            $descriptor,
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            $ready = \CrazyGoat\WorkermanBundle\Util\Wait::until(
                static fn(): bool => is_file($readyFile),
                5,
            );
            $this->assertTrue($ready, 'The child did not reach the backoff wait');

            usleep(200_000);
            $pid = proc_get_status($process)['pid'];
            $this->assertGreaterThan(0, $pid);
            posix_kill($pid, SIGTERM);

            $start = microtime(true);
            $exitCode = proc_close($process);
            $elapsed = microtime(true) - $start;

            $this->assertLessThan(2.0, $elapsed, 'The wait must react to a stop signal immediately');
            $this->assertContains($exitCode, [0, 137], 'The temporary stop handler must end the child cleanly (137 on grpc hosts)');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }
    }

    public function testHealthyAfterZeroNeverWaits(): void
    {
        $backoff = $this->backoff(delay: 5, healthyAfter: 0);

        for ($i = 0; $i < 5; $i++) {
            $backoff->beforeStart('slot', 'Process', 'worker', self::noopLog());
        }

        $this->assertSame([], $this->sleeps);
    }

    public function testInterruptedWaitIsCountedAsFailure(): void
    {
        $now = 1_000;
        $killed = new RestartBackoff(
            runtimeDir: $this->tempDir,
            delay: 1,
            maxDelay: 60,
            maxFailures: 10,
            healthyAfter: 30,
            sleep: static function (int $seconds): never {
                throw new \RuntimeException('killed during the wait');
            },
            now: static function () use (&$now): int {
                return $now;
            },
        );

        $killed->beforeStart('slot', 'Process', 'worker', self::noopLog());

        try {
            $killed->beforeStart('slot', 'Process', 'worker', self::noopLog());
            $this->fail('The simulated kill must abort the wait');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed during the wait', $e->getMessage());
        }

        $afterRestart = new RestartBackoff(
            runtimeDir: $this->tempDir,
            delay: 1,
            maxDelay: 60,
            maxFailures: 10,
            healthyAfter: 30,
            sleep: function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
            now: static function () use (&$now): int {
                return $now;
            },
        );
        $afterRestart->beforeStart('slot', 'Process', 'worker', self::noopLog());

        $this->assertSame([2], $this->sleeps, 'The interrupted wait must count as a fast failure');
    }

    public function testPrepareCreatesTheStateDirectory(): void
    {
        RestartBackoff::prepare($this->tempDir, null, null);

        $this->assertDirectoryExists($this->tempDir . '/restart');
    }

    public function testForgetRemovesOneSlot(): void
    {
        $backoff = $this->backoff(delay: 1);
        $backoff->beforeStart('slot', 'Process', 'worker', self::noopLog());

        $this->assertNotEmpty(glob($this->tempDir . '/restart/*.json'));

        $backoff->forget('slot');

        $this->assertSame([], glob($this->tempDir . '/restart/*.json') ?: []);
    }

    public function testResetClearsPerSlotState(): void
    {
        $backoff = $this->backoff(delay: 1);
        $backoff->beforeStart('slot-a', 'Process', 'a', self::noopLog());
        $backoff->beforeStart('slot-b', 'Process', 'b', self::noopLog());

        $this->assertNotEmpty(glob($this->tempDir . '/restart/*.json'));

        RestartBackoff::reset($this->tempDir);

        $this->assertSame([], glob($this->tempDir . '/restart/*.json') ?: []);
    }

    public function testEmptyRuntimeDirDisablesState(): void
    {
        $backoff = new RestartBackoff('', delay: 1);
        $method = new \ReflectionMethod(RestartBackoff::class, 'statePath');

        $this->assertNull($method->invoke($backoff, 'slot'));
    }

    public function testRestoreUsesTheActiveEventLoop(): void
    {
        $event = $this->createMock(\Workerman\Events\EventInterface::class);
        $event->expects($this->exactly(7))->method('offSignal');
        $event->expects($this->exactly(7))->method('onSignal');

        $previousEvent = Worker::$globalEvent;
        Worker::$globalEvent = $event;

        try {
            $backoff = $this->backoff(delay: 1);
            $method = new \ReflectionMethod(RestartBackoff::class, 'restoreSignalHandlers');
            $method->invoke($backoff, [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2], []);
        } finally {
            Worker::$globalEvent = $previousEvent;
        }
    }

    private function backoff(
        int $delay = 1,
        int $maxDelay = 60,
        int $maxFailures = 10,
        int $healthyAfter = 30,
    ): RestartBackoff {
        return new RestartBackoff(
            runtimeDir: $this->tempDir,
            delay: $delay,
            maxDelay: $maxDelay,
            maxFailures: $maxFailures,
            healthyAfter: $healthyAfter,
            sleep: function (int $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
            now: fn(): int => $this->now,
        );
    }

    private function noopLog(): \Closure
    {
        return static function (string $message): void {
        };
    }
}
