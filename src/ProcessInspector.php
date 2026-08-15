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

        return $this->isAliveNonLinux($pid);
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

    /**
     * Determine whether the given PID is the Workerman master process.
     *
     * Returns false (and logs a warning) when the candidate cannot be
     * verified — the method fails closed, never open, in the direction of
     * sending a signal.
     */
    public function isMasterRunning(int $masterPid, ?MasterFingerprint $fingerprint = null): bool
    {
        if ($masterPid <= 0 || !$this->isProcessAlive($masterPid)) {
            return false;
        }

        // If a fingerprint is available, use it as the primary check.
        // The fingerprint-based check is strictly stronger than the
        // cmdline check because it verifies PID + UID + start time,
        // not a substring match.
        if ($fingerprint instanceof \CrazyGoat\WorkermanBundle\MasterFingerprint) {
            return $this->matchesFingerprint($masterPid, $fingerprint);
        }

        // Legacy fallback: match against the process title the Workerman
        // master actually sets ("WorkerMan: master process ..."). Earlier
        // versions also accepted any cmdline containing the substring
        // "php", which made the check vacuous — every PHP process on the
        // host matched (see issue #584). The title is set before the
        // master forks, so it survives daemon mode and matches only
        // genuine Workerman masters.
        if (self::isLinux()) {
            $cmdline = "/proc/{$masterPid}/cmdline";
            if (is_readable($cmdline)) {
                $content = file_get_contents($cmdline);
                if (\is_string($content) && $content !== '' && str_contains($content, 'WorkerMan: master process')) {
                    return true;
                }
            }
        }

        // Fail closed: on a non-Linux host, or when /proc/$pid/cmdline
        // is unreadable (e.g. a process owned by another user under
        // hidepid), we cannot verify the candidate — refuse to signal.
        $this->logger->warning('Cannot verify master process identity; refusing to signal', [
            'pid' => $masterPid,
            'has_fingerprint' => false,
        ]);

        return false;
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

        // Legacy fallback: cmdline check against the process title the
        // Workerman master sets. The old check accepted any cmdline
        // containing "WorkerMan"; it is tightened to the actual master
        // title so an unrelated "WorkerMan" mention cannot match (issue #584).
        $cmdline = "/proc/{$parentPid}/cmdline";
        if (!is_readable($cmdline)) {
            return;
        }

        $content = file_get_contents($cmdline);
        if (\is_string($content) && str_contains($content, 'WorkerMan: master process')) {
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
     * Non-Linux POSIX liveness check.
     *
     * `posix_kill($pid, 0)` (which already passed at the call site) returns
     * true for zombie processes until their parent reaps them, so it cannot
     * distinguish a running process from a zombie. Distinguish them in two
     * steps:
     *
     * 1. A non-blocking `pcntl_waitpid()` gives a definitive answer for
     *    direct children of this process: a positive return reaps a zombie
     *    child (dead), zero means a running child. This also keeps
     *    direct-child zombies from leaking.
     * 2. For PIDs that are NOT direct children (`waitpid` fails with
     *    ECHILD) — such as a daemonized Workerman master stopped from a
     *    separate CLI process — query the kernel process state via
     *    `ps -o stat=`. A zombie has state `Z`; an empty result means the
     *    process is already gone (issue #651). This mirrors the Linux
     *    `/proc/{pid}/status` State check.
     *
     * When `ps` cannot be executed, the check fails closed (process treated
     * as alive), mirroring the unreadable-`/proc` case on Linux, and logs a
     * warning so the degraded mode is visible.
     *
     * @phpstan-impure
     */
    private function isAliveNonLinux(int $pid): bool
    {
        $result = pcntl_waitpid($pid, $status, \WNOHANG);
        if ($result > 0) {
            return false; // zombie direct child, now reaped
        }
        if ($result === 0) {
            return true; // running direct child
        }

        $state = $this->readProcessStateViaPs($pid);
        if ($state === null) {
            return true; // ps unavailable: fail closed, treat as alive
        }

        return $state !== '' && !str_starts_with($state, 'Z');
    }

    /**
     * Read the kernel process state via `ps -o stat= -p <pid>`.
     *
     * Returns the trimmed state string (e.g. "Ss", "R+", "Z"), an empty
     * string when the process no longer exists, or null when `ps` itself
     * could not be executed (the caller must then fail closed).
     *
     * @phpstan-impure
     */
    private function readProcessStateViaPs(int $pid): ?string
    {
        if (!\function_exists('exec')) {
            $this->logger->warning('Cannot inspect process state: exec() is disabled; treating process as alive', [
                'pid' => $pid,
            ]);

            return null;
        }

        $output = [];
        $exitCode = 0;
        @exec('ps -o stat= -p ' . $pid . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode === 126 || $exitCode === 127) {
            $this->logger->warning('Cannot inspect process state: ps command not available; treating process as alive', [
                'pid' => $pid,
                'exit_code' => $exitCode,
            ]);

            return null;
        }

        if ($exitCode !== 0 || $output === []) {
            return ''; // process gone
        }

        return trim($output[0]);
    }
}
