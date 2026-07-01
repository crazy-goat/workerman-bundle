<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Util\Wait;

final readonly class ProcessInspector
{
    /**
     * Graceful stop timeout is multiplied by this factor to give long-running
     * workers extra time to finish their current request before being forced off.
     */
    private const GRACEFUL_TIMEOUT_MULTIPLIER = 3;

    /**
     * Additional seconds added to both graceful and regular stop timeouts
     * to account for scheduling granularity, signal delivery latency, and
     * process-reap overhead.
     */
    private const TIMEOUT_BUFFER = 3;

    public function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            return false;
        }

        if (self::isLinux()) {
            $statusFile = "/proc/{$pid}/status";
            if (is_readable($statusFile)) {
                $status = file_get_contents($statusFile);
                if (\is_string($status) && preg_match('/^State:\s+Z/m', $status)) {
                    return false;
                }
            }

            return true;
        }

        return $this->isReaped($pid);
    }

    public function getParentPid(int $pid): int
    {
        if (!self::isLinux() || $pid <= 0) {
            return 0;
        }

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

        if (self::isLinux()) {
            $cmdline = "/proc/{$masterPid}/cmdline";
            if (is_readable($cmdline)) {
                $content = file_get_contents($cmdline);
                if (\is_string($content) && $content !== '') {
                    return str_contains($content, 'WorkerMan') || str_contains($content, 'php');
                }
            }
        }

        return true;
    }

    public function killOrphanedIntermediateFork(int $parentPid): void
    {
        if ($parentPid <= 0 || !$this->isProcessAlive($parentPid)) {
            return;
        }

        if (!self::isLinux()) {
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
        $timeout = $graceful
            ? $stopTimeout * self::GRACEFUL_TIMEOUT_MULTIPLIER + self::TIMEOUT_BUFFER
            : $stopTimeout + self::TIMEOUT_BUFFER;

        return Wait::until(fn(): bool => !$this->isProcessAlive($pid), $timeout);
    }

    /**
     * Whether the current platform exposes the Linux `/proc` filesystem.
     *
     * `/proc` is a Linux-only virtual filesystem. macOS, the BSDs, and
     * other POSIX systems do not provide it, so any code that reads
     * `/proc/{pid}/...` must be gated on this check.
     */
    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    /**
     * On non-Linux POSIX systems, `posix_kill($pid, 0)` returns true for
     * zombie processes until the parent reaps them. To distinguish a
     * running process from a zombie, attempt a non-blocking `waitpid`:
     * a positive return value means the child was reaped (dead), zero
     * means it is still running, and a negative return means the pid is
     * not a direct child of this process — in which case we trust the
     * `posix_kill` result that already passed at the call site and
     * treat the process as alive.
     */
    private function isReaped(int $pid): bool
    {
        $result = pcntl_waitpid($pid, $status, \WNOHANG);

        return $result <= 0;
    }
}
