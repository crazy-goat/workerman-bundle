<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Util\Wait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

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

    /**
     * Verify that the given PID matches the recorded master fingerprint.
     *
     * Returns true if the candidate PID is alive AND its UID matches the
     * fingerprint's UID AND (when available) its start time matches the
     * fingerprint's start time. The start time check is the strongest
     * defense against PID reuse: even if the original master process
     * died and its PID was reassigned to an unrelated process, the new
     * process will have a different start time.
     *
     * On non-Linux platforms, only PID + UID matching is available
     * (no `/proc/$pid/stat`). The check is still meaningful because
     * an attacker would need to control a process with the same PID
     * AND the same UID as the original master.
     */
    public function matchesFingerprint(int $pid, MasterFingerprint $fingerprint): bool
    {
        if ($pid <= 0 || $fingerprint->pid <= 0) {
            return false;
        }

        if ($pid !== $fingerprint->pid) {
            return false;
        }

        if (!$this->isProcessAlive($pid)) {
            return false;
        }

        $candidateUid = $this->readUid($pid);
        if ($candidateUid !== null && $candidateUid !== $fingerprint->uid) {
            $this->logger->warning('Process UID does not match master fingerprint; refusing to signal', [
                'pid' => $pid,
                'expected_uid' => $fingerprint->uid,
                'actual_uid' => $candidateUid,
            ]);

            return false;
        }

        if (self::isLinux() && $fingerprint->startTime > 0) {
            $candidateStartTime = $this->readStartTime($pid);
            if ($candidateStartTime !== null && $candidateStartTime !== $fingerprint->startTime) {
                $this->logger->warning('Process start time does not match master fingerprint; refusing to signal', [
                    'pid' => $pid,
                    'expected_start_time' => $fingerprint->startTime,
                    'actual_start_time' => $candidateStartTime,
                ]);

                return false;
            }
        }

        return true;
    }

    public function isMasterRunning(int $masterPid, ?MasterFingerprint $fingerprint = null): bool
    {
        if ($masterPid <= 0 || !$this->isProcessAlive($masterPid)) {
            return false;
        }

        // If a fingerprint is available, use it as the primary check.
        // The fingerprint-based check is strictly stronger than the
        // cmdline substring check because it verifies PID + UID + start
        // time, not just a loose substring match.
        if ($fingerprint instanceof \CrazyGoat\WorkermanBundle\MasterFingerprint) {
            return $this->matchesFingerprint($masterPid, $fingerprint);
        }

        // Legacy fallback: cmdline substring check. Kept for backward
        // compatibility with deployments that have an existing PID file
        // but no fingerprint file (e.g., after upgrading from a version
        // that did not write fingerprints).
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

    public function killOrphanedIntermediateFork(int $parentPid, ?MasterFingerprint $fingerprint = null): void
    {
        if ($parentPid <= 0 || !$this->isProcessAlive($parentPid)) {
            return;
        }

        if (!self::isLinux()) {
            return;
        }

        // If a fingerprint is available, verify the parent PID matches
        // the recorded master fingerprint before signaling. This prevents
        // killing an unrelated co-located process whose command line
        // happens to contain "WorkerMan".
        if ($fingerprint instanceof \CrazyGoat\WorkermanBundle\MasterFingerprint) {
            if (!$this->matchesFingerprint($parentPid, $fingerprint)) {
                $this->logger->warning('Refusing to kill orphaned intermediate fork: PID does not match master fingerprint', [
                    'pid' => $parentPid,
                    'fingerprint_pid' => $fingerprint->pid,
                ]);

                return;
            }

            posix_kill($parentPid, \SIGKILL);

            return;
        }

        // Legacy fallback: cmdline substring check.
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
     * Read the UID of the given PID from `/proc/$pid/status`.
     *
     * Returns null if the UID cannot be determined (non-Linux platform,
     * missing or unreadable status file, malformed content).
     */
    private function readUid(int $pid): ?int
    {
        if (!self::isLinux() || $pid <= 0) {
            return null;
        }

        $statusFile = "/proc/{$pid}/status";
        if (!\is_readable($statusFile)) {
            return null;
        }

        $content = @\file_get_contents($statusFile);
        if (!\is_string($content)) {
            return null;
        }

        if (\preg_match('/^Uid:\s+(\d+)/m', $content, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Read the start time of the given PID from `/proc/$pid/stat` field 22.
     *
     * Returns null if the start time cannot be determined (non-Linux
     * platform, missing or unreadable stat file, malformed content).
     */
    private function readStartTime(int $pid): ?int
    {
        if (!self::isLinux() || $pid <= 0) {
            return null;
        }

        $statFile = "/proc/{$pid}/stat";
        if (!\is_readable($statFile)) {
            return null;
        }

        $content = @\file_get_contents($statFile);
        if (!\is_string($content) || $content === '') {
            return null;
        }

        // The command name (field 2) can contain spaces and parentheses,
        // so we look for the last ')' and parse after it.
        $closeParen = \strrpos($content, ')');
        if ($closeParen === false) {
            return null;
        }

        $afterParen = \substr($content, $closeParen + 1);
        $afterParts = \preg_split('/\s+/', \trim($afterParen));
        if (!\is_array($afterParts) || \count($afterParts) < 20) {
            return null;
        }

        // After ')', the fields are: state(3), ppid(4), pgrp(5), ...
        // starttime is field 22 overall, which is index 19 after ')'.
        $candidate = (int) $afterParts[19];

        return $candidate > 0 ? $candidate : null;
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
