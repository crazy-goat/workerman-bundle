<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\ProcessMarkerPaths;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProcessTest extends KernelTestCase
{
    private string $varDir;

    protected function setUp(): void
    {
        $this->varDir = dirname(__DIR__) . '/var';

        // Clean up marker files from prior runs so leftover state cannot
        // cause false failures (Issue #348 review warning #3).
        @unlink($this->varDir . '/' . ProcessMarkerPaths::ERROR_MARKER);
    }

    /**
     * Regression-protective: the supervised process must keep refreshing its
     * status file. The supervisor restarts the process after it exits, so a
     * recent timestamp proves the start → run → exit → restart cycle works.
     */
    public function testProcessIsLive(): void
    {
        $content = $this->waitForFile(ProcessMarkerPaths::STATUS_FILE)
            ?? $this->fail('Process status file is not found');

        $this->assertTrue(
            (int) $content > time() - 4,
            'Process started more than 4 seconds ago — supervisor may not be restarting it',
        );
    }

    /**
     * Regression-protective: ProcessStartEvent must be dispatched before the
     * service method runs. If the dispatch site in ProcessHandler is removed
     * or reordered, this test fails.
     */
    public function testProcessStartEventIsDispatched(): void
    {
        $entries = $this->waitForMarkerEntries(ProcessMarkerPaths::START_MARKER);
        $this->assertNotEmpty($entries, 'Process start marker is not found');

        $latest = $this->findLatestEntryForProcess($entries, 'Test process');
        $this->assertNotNull($latest, 'No ProcessStartEvent entry found for "Test process"');

        $this->assertGreaterThan(
            time() - 4,
            $latest['timestamp'],
            'ProcessStartEvent was not dispatched recently — start event regression',
        );
    }

    /**
     * Regression-protective: ProcessErrorEvent must NOT fire during normal
     * operation. The test process exits cleanly (exit 0), so any error event
     * for "Test process" indicates a regression in ProcessHandler or the
     * service invocation path.
     */
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

    /**
     * Regression-protective: ProcessErrorEvent MUST fire when a process
     * service throws. The TestErrorProcess throws RuntimeException on every
     * invocation; the supervisor restarts it, so the error marker should
     * contain a recent entry for "Test error process".
     */
    public function testProcessErrorEventIsDispatchedOnThrowable(): void
    {
        $entries = $this->waitForMarkerEntries(ProcessMarkerPaths::ERROR_MARKER);
        $this->assertNotEmpty($entries, 'Process error marker is not found — error event was not dispatched');

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

    /**
     * Signal-handling regression: sending SIGTERM to the process worker
     * must result in graceful exit and supervisor restart. The start-event
     * marker must be refreshed after the kill, proving the supervisor
     * detected the death and respawned the worker.
     *
     * @requires extension pcntl
     * @requires extension posix
     */
    public function testProcessSurvivesSigtermAndIsRestartedBySupervisor(): void
    {
        $workerPid = $this->findProcessWorkerPid();
        if ($workerPid === null) {
            $this->markTestSkipped('Could not locate process worker PID');
        }

        // Record the current start-marker timestamp so we can detect a refresh.
        $beforeContent = @file_get_contents($this->varDir . '/' . ProcessMarkerPaths::START_MARKER);
        $beforeTimestamp = 0;
        if ($beforeContent !== false) {
            $entries = $this->parseMarkerEntries($beforeContent);
            $latest = $this->findLatestEntryForProcess($entries, 'Test process');
            $beforeTimestamp = $latest['timestamp'] ?? 0;
        }

        // Send SIGTERM to the worker. Workerman's default handler exits cleanly.
        posix_kill($workerPid, SIGTERM);

        // Wait for the supervisor to restart the worker and dispatch a new
        // ProcessStartEvent. The marker timestamp must advance.
        $deadline = microtime(true) + 5;
        $refreshed = false;
        while (microtime(true) < $deadline) {
            $content = @file_get_contents($this->varDir . '/' . ProcessMarkerPaths::START_MARKER);
            if ($content !== false) {
                $entries = $this->parseMarkerEntries($content);
                $latest = $this->findLatestEntryForProcess($entries, 'Test process');
                if ($latest !== null && $latest['timestamp'] > $beforeTimestamp) {
                    $refreshed = true;
                    break;
                }
            }
            usleep(100_000);
        }

        $this->assertTrue(
            $refreshed,
            'ProcessStartEvent marker was not refreshed after SIGTERM — supervisor did not restart the worker',
        );
    }

    /**
     * Find the PID of the [Process] worker by reading the master PID file
     * and scanning its children via /proc (Linux) or ps (macOS/BSD).
     *
     * @requires extension pcntl
     * @requires extension posix
     */
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

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
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

    /**
     * @requires extension pcntl
     * @requires extension posix
     */
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
        $i = 0;
        do {
            if (($content = @file_get_contents($this->varDir . '/' . $relativePath)) !== false) {
                return $content;
            }
            usleep(200000);
        } while (++$i < 10);
        return null;
    }

    /**
     * Wait for a marker file to appear and return its parsed entries.
     *
     * @return list<array{timestamp: int, process: string, message?: string}>
     */
    private function waitForMarkerEntries(string $relativePath): array
    {
        $i = 0;
        do {
            $content = @file_get_contents($this->varDir . '/' . $relativePath);
            if ($content !== false && $content !== '') {
                return $this->parseMarkerEntries($content);
            }
            usleep(200000);
        } while (++$i < 10);
        return [];
    }

    /**
     * Parse append-only marker file content into structured entries.
     *
     * @return list<array{timestamp: int, process: string, message?: string}>
     */
    private function parseMarkerEntries(string $content): array
    {
        $entries = [];
        foreach (explode("\n", $content) as $line) {
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
     * Find the most recent entry for a given process name.
     *
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
