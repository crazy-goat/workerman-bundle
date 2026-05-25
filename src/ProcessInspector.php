<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

final readonly class ProcessInspector
{
    public function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            return false;
        }

        $statusFile = "/proc/{$pid}/status";
        if (is_readable($statusFile)) {
            $status = file_get_contents($statusFile);
            if (\is_string($status) && preg_match('/^State:\s+Z/m', $status)) {
                return false;
            }
        }

        return true;
    }

    public function getParentPid(int $pid): int
    {
        $statusFile = "/proc/{$pid}/status";
        if (!is_readable($statusFile)) {
            return 0;
        }

        $status = file_get_contents($statusFile);
        if (\is_string($status) && preg_match('/^PPid:\s+(\d+)/m', $status, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    public function isMasterRunning(int $masterPid): bool
    {
        if ($masterPid <= 0 || !$this->isProcessAlive($masterPid)) {
            return false;
        }

        $cmdline = "/proc/{$masterPid}/cmdline";
        if (is_readable($cmdline)) {
            $content = file_get_contents($cmdline);
            if (\is_string($content) && $content !== '') {
                return str_contains($content, 'WorkerMan') || str_contains($content, 'php');
            }
        }

        return true;
    }

    public function killOrphanedIntermediateFork(int $parentPid): void
    {
        if ($parentPid <= 0 || !$this->isProcessAlive($parentPid)) {
            return;
        }

        $cmdline = "/proc/{$parentPid}/cmdline";
        if (!is_readable($cmdline)) {
            return;
        }

        $content = file_get_contents($cmdline);
        if (\is_string($content) && str_contains($content, 'WorkerMan')) {
            posix_kill($parentPid, \SIGKILL);
        }
    }

    public function waitForProcessToStop(int $pid, int $stopTimeout, bool $graceful): bool
    {
        $timeout = $graceful ? $stopTimeout * 3 + 3 : $stopTimeout + 3;
        $startTime = time();
        $sleepMs = 10;

        while (true) {
            if (!$this->isProcessAlive($pid)) {
                return true;
            }

            if ((time() - $startTime) >= $timeout) {
                return false;
            }

            usleep($sleepMs * 1000);
            $sleepMs = min($sleepMs * 2, 250);
        }
    }
}
