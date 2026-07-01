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

    /**
     * @phpstan-impure
     */
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
     * Platform behavior:
     * - Linux: full PID + UID + start-time verification.
     * - Non-Linux POSIX: PID + UID verification only (start time is
     *   recorded as 0 and the start-time check is skipped). UID is
     *   verified via `posix_getuid()` of the current process as a
     *   best-effort match (cross-process UID read requires `/proc`).
     *
     * Race handling: if the process dies between the initial liveness
     * check and the UID/start-time reads, the function fails closed
     * (returns false).
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

        if (self::isLinux()) {
            $candidateUid = MasterFingerprint::readUidForPid($pid);
            if ($candidateUid === null) {
                // UID could not be read. If the process is now dead, fail closed.
                if (!$this->isProcessAlive($pid)) {
                    return false;
                }
                // Process is still alive but UID is unreadable — fail closed
                // and log a warning so the degraded mode is visible in production.
                $this->logger->warning('Cannot read UID for fingerprint verification; refusing to signal', [
                    'pid' => $pid,
                    'expected_uid' => $fingerprint->uid,
                ]);

                return false;
            }

            if ($candidateUid !== $fingerprint->uid) {
                $this->logger->warning('Process UID does not match master fingerprint; refusing to signal', [
                    'pid' => $pid,
                    'expected_uid' => $fingerprint->uid,
                    'actual_uid' => $candidateUid,
                ]);

                return false;
            }

            if ($fingerprint->startTime > 0) {
                $candidateStartTime = MasterFingerprint::readStartTimeForPid($pid);
                if ($candidateStartTime === 0) {
                    // Start time could not be read. If the process is now dead, fail closed.
                    if (!$this->isProcessAlive($pid)) {
                        return false;
                    }
                    // Process is still alive but start time is unreadable — fail closed.
                    $this->logger->warning('Cannot read start time for fingerprint verification; refusing to signal', [
                        'pid' => $pid,
                        'expected_start_time' => $fingerprint->startTime,
                    ]);

                    return false;
                }

                if ($candidateStartTime !== $fingerprint->startTime) {
                    $this->logger->warning('Process start time does not match master fingerprint; refusing to signal', [
                        'pid' => $pid,
                        'expected_start_time' => $fingerprint->startTime,
                        'actual_start_time' => $candidateStartTime,
                    ]);

                    return false;
                }
            }
        } else {
            // Non-Linux: UID verification via posix_getuid() of the current
            // process. This is a best-effort match — if the current process
            // is running as the same user as the master, the check passes.
            // Cross-process UID read requires /proc which is unavailable.
            $currentUid = \posix_getuid();
            if ($currentUid !== $fingerprint->uid) {
                $this->logger->warning('Current process UID does not match master fingerprint; refusing to signal', [
                    'pid' => $pid,
                    'expected_uid' => $fingerprint->uid,
                    'actual_uid' => $currentUid,
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
        // that did not write fingerprints, or in daemon mode where the
        // launcher PID does not match the master PID).
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

            // Defense in depth: even with a fingerprint match, require
            // the cmdline to still contain "WorkerMan" or "php". This
            // guards against the unlikely case where a fingerprint's
            // start time is 0 (degraded mode) and the only remaining
            // check is PID + UID.
            if (!$this->cmdlineLooksLikeWorkerman($parentPid)) {
                $this->logger->warning('Refusing to kill orphaned intermediate fork: cmdline does not look like Workerman', [
                    'pid' => $parentPid,
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
     * Check whether the cmdline of the given PID looks like a Workerman
     * process (contains "WorkerMan").
     *
     * Used as a defense-in-depth check alongside fingerprint verification.
     * Unlike the legacy `isMasterRunning()` fallback (which accepts "php"
     * as a marker), this check requires the specific "WorkerMan" marker
     * because every PHP process has "php" in its cmdline, which would
     * make the check non-discriminating.
     */
    private function cmdlineLooksLikeWorkerman(int $pid): bool
    {
        if (!self::isLinux() || $pid <= 0) {
            return false;
        }

        $cmdline = "/proc/{$pid}/cmdline";
        if (!\is_readable($cmdline)) {
            return false;
        }

        $content = @\file_get_contents($cmdline);
        if (!\is_string($content) || $content === '') {
            return false;
        }

        return \str_contains($content, 'WorkerMan');
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
