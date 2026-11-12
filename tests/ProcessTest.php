<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\ProcessMarkerPaths;
use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProcessTest extends KernelTestCase
{
    use RequiresLiveServer;
    private string $varDir;

    protected function setUp(): void
    {
        $this->varDir = dirname(__DIR__) . '/var';

        @unlink($this->varDir . '/' . ProcessMarkerPaths::START_MARKER);
        @unlink($this->varDir . '/' . ProcessMarkerPaths::ERROR_MARKER);
    }

    public function testProcessIsLive(): void
    {
        $content = $this->waitForFile(ProcessMarkerPaths::STATUS_FILE)
            ?? $this->fail('Process status file is not found');

        $this->assertTrue(
            (int) $content > time() - 10,
            'Process status timestamp is stale — supervisor may not be restarting it',
        );
    }

    public function testProcessStartEventIsDispatched(): void
    {
        $freshAfter = time() - 1;
        $entries = $this->waitForMarkerEntries(ProcessMarkerPaths::START_MARKER, 'Test process', $freshAfter);
        $this->assertNotEmpty($entries, 'No fresh ProcessStartEvent marker entry appeared within 15s');

        $latest = $this->findLatestEntryForProcess($entries, 'Test process');
        $this->assertNotNull($latest, 'No ProcessStartEvent entry found for "Test process"');

        $this->assertGreaterThan(
            time() - 4,
            $latest['timestamp'],
            'ProcessStartEvent was not dispatched recently — start event regression',
        );
    }

    public function testNoProcessErrorEventDuringNormalOperation(): void
    {
        $path = $this->varDir . '/' . ProcessMarkerPaths::ERROR_MARKER;
        if (!is_file($path)) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $entries = $this->parseMarkerEntries((string) file_get_contents($path));
        $normalErrors = $this->findLatestEntryForProcess($entries, 'Test process');

        $this->assertNull(
            $normalErrors,
            'ProcessErrorEvent was dispatched for "Test process" during normal operation — error path regression',
        );
    }

    public function testProcessErrorEventIsDispatchedOnThrowable(): void
    {
        $freshAfter = time() - 1;
        $entries = $this->waitForMarkerEntries(ProcessMarkerPaths::ERROR_MARKER, 'Test error process', $freshAfter);
        $this->assertNotEmpty($entries, 'No fresh ProcessErrorEvent marker entry appeared within 15s');

        $latest = $this->findLatestEntryForProcess($entries, 'Test error process');
        $this->assertNotNull($latest, 'No ProcessErrorEvent entry found for "Test error process"');

        $this->assertGreaterThan(
            time() - 4,
            $latest['timestamp'],
            'ProcessErrorEvent timestamp is stale — error event regression',
        );

        $this->assertStringContainsString(
            'intentionally throws',
            $latest['message'] ?? '',
            'ProcessErrorEvent should carry the thrown exception message',
        );
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testProcessSurvivesSigtermAndIsRestartedBySupervisor(): void
    {
        $workerPid = $this->findProcessWorkerPid();
        if ($workerPid === null) {
            self::failLiveServer('Could not locate process worker PID');
        }

        $beforeContent = @file_get_contents($this->varDir . '/' . ProcessMarkerPaths::START_MARKER);
        $beforeTimestamp = 0;
        if ($beforeContent !== false) {
            $entries = $this->parseMarkerEntries($beforeContent);
            $latest = $this->findLatestEntryForProcess($entries, 'Test process');
            $beforeTimestamp = $latest['timestamp'] ?? 0;
        }

        posix_kill($workerPid, SIGTERM);

        $refreshed = Wait::until(function () use ($beforeTimestamp): bool {
            $content = @file_get_contents($this->varDir . '/' . ProcessMarkerPaths::START_MARKER);
            if ($content === false) {
                return false;
            }
            $entries = $this->parseMarkerEntries($content);
            $latest = $this->findLatestEntryForProcess($entries, 'Test process');

            return $latest !== null && $latest['timestamp'] > $beforeTimestamp;
        }, 5);

        $this->assertTrue(
            $refreshed,
            'ProcessStartEvent marker was not refreshed after SIGTERM — supervisor did not restart the worker',
        );
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    private function findProcessWorkerPid(): int|null
    {
        $pidFile = $this->varDir . '/run/workerman.pid';
        if (!is_file($pidFile)) {
            return null;
        }

        $masterPid = (int) trim((string) file_get_contents($pidFile));
        if ($masterPid <= 0 || !posix_kill($masterPid, 0)) {
            return null;
        }

        if (is_dir('/proc')) {
            return $this->findWorkerPidViaProc($masterPid);
        }

        return $this->findWorkerPidViaPs($masterPid);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    private function findWorkerPidViaProc(int $masterPid): int|null
    {
        $entries = @scandir('/proc');
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $pid = (int) $entry;
            if ($pid === $masterPid) {
                continue;
            }
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if ($stat === false) {
                continue;
            }
            $fields = explode(' ', $stat);
            $parentPid = (int) ($fields[3] ?? 0);
            if ($parentPid !== $masterPid) {
                continue;
            }
            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
            if ($cmdline === false) {
                continue;
            }
            if (str_contains($cmdline, '[Process]')) {
                return $pid;
            }
        }

        return null;
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    private function findWorkerPidViaPs(int $masterPid): int|null
    {
        $psOutput = @shell_exec('ps -o pid,ppid,command -ax 2>/dev/null');
        if (!is_string($psOutput) || $psOutput === '') {
            return null;
        }

        $psLines = explode("\n", $psOutput);
        foreach ($psLines as $line) {
            if (!str_contains($line, '[Process]')) {
                continue;
            }
            $fields = preg_split('/\s+/', trim($line), 3);
            if (!is_array($fields) || count($fields) < 2) {
                continue;
            }
            $pid = (int) $fields[0];
            $ppid = (int) $fields[1];
            if ($ppid === $masterPid && $pid > 0) {
                return $pid;
            }
        }

        return null;
    }

    private function waitForFile(string $relativePath): string|null
    {
        $path = $this->varDir . '/' . $relativePath;
        $found = Wait::until(static fn(): bool => @file_get_contents($path) !== false, 2);

        if (!$found) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * @return list<array{timestamp: int, process: string, message?: string}>
     */
    private function waitForMarkerEntries(string $relativePath, string $processName, int $freshAfter): array
    {
        $path = $this->varDir . '/' . $relativePath;
        $found = Wait::until(function () use ($path, $processName, $freshAfter): bool {
            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                return false;
            }
            foreach ($this->parseMarkerEntries($content) as $entry) {
                if ($entry['process'] === $processName && $entry['timestamp'] >= $freshAfter) {
                    return true;
                }
            }

            return false;
        }, 15);

        if (!$found) {
            return [];
        }

        $content = @file_get_contents($path);

        return $content !== false ? $this->parseMarkerEntries($content) : [];
    }

    /**
     * @return list<array{timestamp: int, process: string, message?: string}>
     */
    private function parseMarkerEntries(string $content): array
    {
        $entries = [];
        $lines = explode("\n", $content);
        if (end($lines) !== '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\x1f", $line);
            $entry = [
                'timestamp' => (int) ($parts[0] ?? 0),
                'process' => $parts[1] ?? '',
            ];
            if (isset($parts[2]) && $parts[2] !== '') {
                $entry['message'] = $parts[2];
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * @param list<array{timestamp: int, process: string, message?: string}> $entries
     * @return array{timestamp: int, process: string, message?: string}|null
     */
    private function findLatestEntryForProcess(array $entries, string $processName): array|null
    {
        $latest = null;
        foreach ($entries as $entry) {
            if ($entry['process'] !== $processName) {
                continue;
            }
            if ($latest === null || $entry['timestamp'] > $latest['timestamp']) {
                $latest = $entry;
            }
        }
        return $latest;
    }
}
