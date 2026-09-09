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
            // Read the single state character from /proc/{pid}/stat field 3
            // instead of slurping the whole /proc/{pid}/status (~1 KB) and
            // running a multiline regex over it. A state of 'Z' means the
            // process is a zombie — not alive. When the state cannot be read
            // (unreadable /proc, process gone) the process is treated as
            // alive, matching the previous unreadable-status behavior.
            $state = $this->readProcessStateFromStat($pid);

            return $state !== 'Z';
        }

        return $this->isAliveNonLinux($pid);
    }

    public function getParentPid(int $pid): int
    {
        if ($pid <= 0) {
            return 0;
        }

        if (self::isLinux()) {
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

        return $this->readParentPidViaPs($pid);
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
     * - Linux: full PID + UID + start-time verification. Reads
     *   `/proc/{pid}/stat` once (state + start time) and
     *   `/proc/{pid}/status` once (UID) — two bounded /proc reads per
     *   call — and reasons over that single snapshot.
     * - Non-Linux POSIX: PID + UID verification only (start time is
     *   recorded as 0 and the start-time check is skipped). UID is
     *   verified via `posix_getuid()` of the current process as a
     *   best-effort match (cross-process UID read requires `/proc`).
     *
     * Fail-closed: a snapshot read that fails or comes back empty, an
     * unreadable UID, an unreadable start time, or a process that dies
     * mid-verification (state `Z` or null) each cause the function to
     * refuse (return false), never to succeed.
     */
    public function matchesFingerprint(int $pid, MasterFingerprint $fingerprint): bool
    {
        if ($pid <= 0 || $fingerprint->pid <= 0) {
            return false;
        }

        if ($pid !== $fingerprint->pid) {
            return false;
        }

        if (self::isLinux()) {
            // Take a single snapshot of /proc/{pid}/stat (state + start time)
            // and /proc/{pid}/status (UID) — two bounded /proc reads total —
            // and reason over that snapshot. Previously this method called
            // isProcessAlive() up to 3 times, each reading /proc, plus
            // readUidForPid() and readStartTimeForPid(), for up to 5 reads.
            //
            // Fail-closed is preserved: a snapshot read that fails or comes
            // back empty causes the verification to refuse (return false),
            // never to succeed. Concretely:
            // - /proc/{pid}/stat unreadable or malformed → treat as dead
            //   (state null) → false; a zombie state 'Z' → false.
            // - /proc/{pid}/status unreadable → UID null → false.
            // - start time 0 when the fingerprint recorded one → false.
            $snapshot = $this->readLinuxProcessSnapshot($pid);

            if (!$snapshot instanceof \CrazyGoat\WorkermanBundle\ProcessSnapshot || !$snapshot->isAlive()) {
                return false;
            }

            return $this->snapshotMatchesFingerprint($snapshot, $fingerprint);
        }

        // Non-Linux: PID + UID verification only. Start time is recorded
        // as 0 and the start-time check is skipped. Cross-process UID
        // read requires /proc which is unavailable, so UID is verified
        // via posix_getuid() of the current process as a best-effort
        // match. The isProcessAlive() call below uses the non-Linux
        // pcntl_waitpid/ps path (isAliveNonLinux), unchanged.
        if (!$this->isProcessAlive($pid)) {
            return false;
        }

        $currentUid = \posix_getuid();
        if ($currentUid !== $fingerprint->uid) {
            $this->logger->warning('Current process UID does not match master fingerprint; refusing to signal', [
                'pid' => $pid,
                'expected_uid' => $fingerprint->uid,
                'actual_uid' => $currentUid,
            ]);

            return false;
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

        // If a fingerprint is available, verify the parent PID before
        // signaling. The direct identity check (`matchesFingerprint`) covers
        // the case where the parent PID IS the master (unit tests and the
        // non-daemon path where the caller passes the master PID itself).
        // In daemon mode the fingerprint names the **master**, while
        // `$parentPid` is the daemonize intermediate (the master's parent),
        // so the identity check always refuses — the fingerprint branch was
        // dead in the production daemon flow (issue #721). The ancestry
        // check below verifies that `$parentPid` is the recorded parent of
        // the fingerprinted master and that it looks like a Workerman master
        // process (title check), which prevents killing the user's shell in
        // non-daemon mode while allowing the hung intermediate to be cleaned
        // up.
        if ($fingerprint instanceof \CrazyGoat\WorkermanBundle\MasterFingerprint) {
            if ($this->matchesFingerprint($parentPid, $fingerprint)) {
                posix_kill($parentPid, \SIGKILL);

                return;
            }

            $masterPid = $fingerprint->pid;
            if ($masterPid > 0) {
                $actualParent = $this->getParentPid($masterPid);
                if ($actualParent === $parentPid) {
                    if ($this->isWorkermanMasterTitle($parentPid)) {
                        posix_kill($parentPid, \SIGKILL);

                        return;
                    }

                    $this->logger->warning('Refusing to kill orphaned intermediate fork: parent does not carry the Workerman master process title', [
                        'pid' => $parentPid,
                        'master_pid' => $masterPid,
                    ]);

                    return;
                }
            }

            $this->logger->warning('Refusing to kill orphaned intermediate fork: PID does not match master fingerprint nor is it parent of fingerprinted master', [
                'pid' => $parentPid,
                'fingerprint_pid' => $fingerprint->pid,
            ]);

            return;
        }

        // Legacy fallback: cmdline check against the process title the
        // Workerman master sets. The old check accepted any cmdline
        // containing "WorkerMan"; it is tightened to the actual master
        // title so an unrelated "WorkerMan" mention cannot match (issue #584).
        if ($this->isWorkermanMasterTitle($parentPid)) {
            posix_kill($parentPid, \SIGKILL);
        }
    }

    /**
     * Whether the given PID carries the Workerman master process title.
     *
     * The title is set by Workerman before forking, so it survives daemon
     * mode and matches only genuine Workerman masters — not a user's shell
     * (non-daemon parent) and not an unrelated "WorkerMan" mention.
     *
     * Platform behavior:
     * - Linux: reads `/proc/$pid/cmdline` (kernel argv).
     * - Non-Linux POSIX: queries `ps -ww -o args=` (process args).
     */
    private function isWorkermanMasterTitle(int $pid): bool
    {
        if (self::isLinux()) {
            $cmdline = "/proc/{$pid}/cmdline";
            if (!is_readable($cmdline)) {
                return false;
            }

            $content = file_get_contents($cmdline);
            if (!\is_string($content)) {
                return false;
            }

            return str_contains($content, 'WorkerMan: master process');
        }

        $command = $this->readProcessCommandViaPs($pid);
        if ($command === null || $command === '') {
            return false;
        }

        return str_contains($command, 'WorkerMan: master process');
    }

    /**
     * Read the parent PID via `ps -o ppid= -p <pid>` on non-Linux POSIX.
     *
     * Returns 0 when the PPID cannot be determined (process gone,
     * unreadable, or `ps` unavailable) — the caller treats 0 as "no parent"
     * and fails closed.
     *
     * @phpstan-impure
     */
    private function readParentPidViaPs(int $pid): int
    {
        if (!\function_exists('exec')) {
            $this->logger->warning('Cannot read parent PID: exec() is disabled; treating as no parent', [
                'pid' => $pid,
            ]);

            return 0;
        }

        $output = [];
        $exitCode = 0;
        @exec('ps -o ppid= -p ' . $pid . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode === 126 || $exitCode === 127) {
            $this->logger->warning('Cannot read parent PID: ps command not available', [
                'pid' => $pid,
                'exit_code' => $exitCode,
            ]);

            return 0;
        }

        if ($exitCode !== 0) {
            if (posix_kill($pid, 0)) {
                $this->logger->warning('Cannot read parent PID: ps exited non-zero on a signalable PID', [
                    'pid' => $pid,
                    'exit_code' => $exitCode,
                ]);
            }

            return 0;
        }

        if ($output === []) {
            return 0;
        }

        return (int) trim($output[0]);
    }

    /**
     * Read the process command/args via `ps -ww -o args= -p <pid>` on
     * non-Linux POSIX.
     *
     * Returns the trimmed command string, an empty string when the process
     * no longer exists, or null when `ps` itself could not be executed
     * (the caller must then fail closed).
     *
     * @phpstan-impure
     */
    private function readProcessCommandViaPs(int $pid): ?string
    {
        if (!\function_exists('exec')) {
            $this->logger->warning('Cannot inspect process command: exec() is disabled', [
                'pid' => $pid,
            ]);

            return null;
        }

        $output = [];
        $exitCode = 0;
        @exec('ps -ww -o args= -p ' . $pid . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode === 126 || $exitCode === 127) {
            $this->logger->warning('Cannot inspect process command: ps command not available', [
                'pid' => $pid,
                'exit_code' => $exitCode,
            ]);

            return null;
        }

        if ($exitCode !== 0) {
            if (posix_kill($pid, 0)) {
                $this->logger->warning('Cannot inspect process command: ps exited non-zero on a signalable PID', [
                    'pid' => $pid,
                    'exit_code' => $exitCode,
                ]);

                return null;
            }

            return '';
        }

        if ($output === []) {
            return '';
        }

        return trim($output[0]);
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
     * Read the process state character from `/proc/{pid}/stat` field 3.
     *
     * This is cheaper than slurping the whole `/proc/{pid}/status` file
     * (~1 KB) and running a multiline regex over it: `/proc/{pid}/stat` is
     * a single line, and the state is the first field after the last `)`.
     * Returns the single state character (e.g. `'R'`, `'S'`, `'Z'`), or
     * `null` when the file is unreadable or malformed. A `null` result is
     * treated as "alive" by {@see isProcessAlive()} — matching the previous
     * unreadable-`/proc` behavior — so that a live process is never read as
     * dead when /proc is transiently unavailable.
     *
     * @phpstan-impure
     */
    private function readProcessStateFromStat(int $pid): ?string
    {
        $statFile = "/proc/{$pid}/stat";
        if (!is_readable($statFile)) {
            return null;
        }

        $content = @file_get_contents($statFile);
        if (!\is_string($content) || $content === '') {
            return null;
        }

        // The command name (field 2) can contain spaces and parentheses,
        // so we look for the last ')' and parse after it — the same
        // approach used in MasterFingerprint::readStartTimeForPid().
        $closeParen = \strrpos($content, ')');
        if ($closeParen === false) {
            return null;
        }

        $afterParen = \substr($content, $closeParen + 1);
        // Field 3 (state) is the first whitespace-delimited token after ')'.
        $state = \trim($afterParen);
        if ($state === '') {
            return null;
        }

        // The state is a single character; return just it.
        return $state[0];
    }

    /**
     * Take a single snapshot of the Linux process identity fields.
     *
     * Reads `/proc/{pid}/stat` once (yielding the state character and the
     * start time) and `/proc/{pid}/status` once (yielding the UID). This
     * replaces the up-to-five separate /proc reads that
     * {@see matchesFingerprint()} performed previously (isProcessAlive ×3
     * + readUidForPid + readStartTimeForPid).
     *
     * Returns `null` when `/proc/{pid}/stat` is entirely unreadable (the
     * process is gone or /proc is unavailable) — the caller fails closed.
     * When stat is readable but status is not, the snapshot's `$uid` is
     * `null` — the caller fails closed on the UID check.
     *
     * @phpstan-impure
     */
    private function readLinuxProcessSnapshot(int $pid): ?ProcessSnapshot
    {
        $statFile = "/proc/{$pid}/stat";
        if (!is_readable($statFile)) {
            return null;
        }

        $content = @file_get_contents($statFile);
        if (!\is_string($content) || $content === '') {
            return null;
        }

        $closeParen = \strrpos($content, ')');
        if ($closeParen === false) {
            return null;
        }

        $afterParen = \substr($content, $closeParen + 1);
        $afterParts = \preg_split('/\s+/', \trim($afterParen));
        if (!\is_array($afterParts) || $afterParts === [] || $afterParts[0] === '') {
            return null;
        }

        // After ')', the fields are: state(3), ppid(4), pgrp(5), ...
        // starttime is field 22 overall, which is index 19 after ')'.
        $state = $afterParts[0][0];
        $startTime = \count($afterParts) >= 20 ? max((int) $afterParts[19], 0) : 0;

        $uid = MasterFingerprint::readUidForPid($pid);

        return new ProcessSnapshot($state, $startTime, $uid);
    }

    /**
     * Verify a live Linux process snapshot against the master fingerprint.
     *
     * Given a snapshot that has already passed the liveness check (state is
     * non-null and non-'Z'), verify the identity fields. Fail-closed: an
     * unreadable UID (`null`), a UID mismatch, an unreadable start time
     * (`0` when the fingerprint recorded one), or a start-time mismatch each
     * cause the verification to refuse (return false), never to succeed.
     *
     * Extracted from {@see matchesFingerprint()} so the fail-closed branches
     * can be exercised directly with a crafted snapshot (a real live process
     * always yields a readable UID and a non-zero start time, so the
     * unreadable-field branches cannot be reached through a real `/proc`
     * read).
     */
    private function snapshotMatchesFingerprint(\CrazyGoat\WorkermanBundle\ProcessSnapshot $snapshot, \CrazyGoat\WorkermanBundle\MasterFingerprint $fingerprint): bool
    {
        if ($snapshot->uid === null) {
            // UID could not be read but the process is alive (state is
            // not 'Z'). Fail closed and log so the degraded mode is
            // visible in production.
            $this->logger->warning('Cannot read UID for fingerprint verification; refusing to signal', [
                'pid' => $fingerprint->pid,
                'expected_uid' => $fingerprint->uid,
            ]);

            return false;
        }

        if ($snapshot->uid !== $fingerprint->uid) {
            $this->logger->warning('Process UID does not match master fingerprint; refusing to signal', [
                'pid' => $fingerprint->pid,
                'expected_uid' => $fingerprint->uid,
                'actual_uid' => $snapshot->uid,
            ]);

            return false;
        }

        if ($fingerprint->startTime > 0) {
            if ($snapshot->startTime === 0) {
                // Process is alive (state is not 'Z') but start time is
                // unreadable — fail closed.
                $this->logger->warning('Cannot read start time for fingerprint verification; refusing to signal', [
                    'pid' => $fingerprint->pid,
                    'expected_start_time' => $fingerprint->startTime,
                ]);

                return false;
            }

            if ($snapshot->startTime !== $fingerprint->startTime) {
                $this->logger->warning('Process start time does not match master fingerprint; refusing to signal', [
                    'pid' => $fingerprint->pid,
                    'expected_start_time' => $fingerprint->startTime,
                    'actual_start_time' => $snapshot->startTime,
                ]);

                return false;
            }
        }

        return true;
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
     *    `/proc/{pid}/stat` state-character check.
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
     * string when the process no longer exists (ps exit 0 with no output,
     * or non-zero exit on an unsignalable PID), or null when `ps` itself
     * could not be executed or exited abnormally on a still-signalable
     * PID (the caller must then fail closed — treat as alive).
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

        // `ps` exited abnormally (not 0/126/127). A non-zero exit on a PID
        // that `posix_kill($pid, 0)` just confirmed as signalable means `ps`
        // itself misbehaved (sandbox, resource limits, non-conforming build)
        // — fail closed so a live master is never read as dead (stop() would
        // otherwise report success and delete the pid/fingerprint files while
        // the master still runs). Only when the PID is no longer signalable
        // does a failed ps mean the process is gone.
        if ($exitCode !== 0) {
            if (posix_kill($pid, 0)) {
                $this->logger->warning('Cannot inspect process state: ps exited non-zero on a signalable PID; treating process as alive', [
                    'pid' => $pid,
                    'exit_code' => $exitCode,
                ]);

                return null;
            }

            return ''; // PID unsignalable + ps failed: process gone
        }

        if ($output === []) {
            return ''; // ps succeeded with no output: process gone
        }

        return trim($output[0]);
    }
}
