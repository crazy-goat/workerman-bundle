<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Util\ProcFileReader;
use CrazyGoat\WorkermanBundle\Util\ProcStatParser;
use CrazyGoat\WorkermanBundle\Util\Wait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ProcessInspector
{
    private const GRACEFUL_TIMEOUT_MULTIPLIER = 3;

    private const TIMEOUT_BUFFER = 3;

    /** @var \Closure(list<string>): (array{output: list<string>, exitCode: int}|null) */
    private \Closure $psRunner;

    private string $osFamily;

    /**
     * @param \Closure(list<string>): (array{output: list<string>, exitCode: int}|null)|null $psRunner
     */
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
        ?\Closure $psRunner = null,
        ?string $osFamily = null,
    ) {
        $this->psRunner = $psRunner ?? $this->execPs(...);
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
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
            $stat = ProcFileReader::read("/proc/{$pid}/stat");
            if ($stat === null) {
                return 0;
            }

            $afterParts = ProcStatParser::splitFields($stat);
            if ($afterParts === null || \count($afterParts) < 2 || !\ctype_digit($afterParts[1])) {
                return 0;
            }

            return (int) $afterParts[1];
        }

        return $this->readParentPidViaPs($pid);
    }

    public function matchesFingerprint(int $pid, MasterFingerprint $fingerprint): bool
    {
        if ($pid <= 0 || $fingerprint->pid <= 0) {
            return false;
        }

        if ($pid !== $fingerprint->pid) {
            return false;
        }

        if (self::isLinux()) {
            $snapshot = $this->readLinuxProcessSnapshot($pid);

            if (!$snapshot instanceof \CrazyGoat\WorkermanBundle\ProcessSnapshot || !$snapshot->isAlive()) {
                return false;
            }

            return $this->snapshotMatchesFingerprint($snapshot, $fingerprint);
        }

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

    public function isMasterRunning(int $masterPid, ?MasterFingerprint $fingerprint = null): bool
    {
        if ($masterPid <= 0 || !$this->isProcessAlive($masterPid)) {
            return false;
        }

        if ($fingerprint instanceof \CrazyGoat\WorkermanBundle\MasterFingerprint) {
            return $this->matchesFingerprint($masterPid, $fingerprint);
        }

        if (self::isLinux()) {
            $content = ProcFileReader::read("/proc/{$masterPid}/cmdline");
            if ($content !== null && str_contains($content, 'WorkerMan: master process')) {
                return true;
            }
        }

        $this->logger->warning('Cannot verify master process identity; refusing to signal', [
            'pid' => $masterPid,
            'has_fingerprint' => false,
        ]);

        return false;
    }

    public function killOrphanedIntermediateFork(int $parentPid, ?MasterFingerprint $fingerprint = null, ?int $preKillParentPid = null): void
    {
        if ($parentPid <= 0 || !$this->isProcessAlive($parentPid)) {
            return;
        }

        if ($fingerprint instanceof \CrazyGoat\WorkermanBundle\MasterFingerprint) {
            if ($this->matchesFingerprint($parentPid, $fingerprint)) {
                posix_kill($parentPid, \SIGKILL);

                return;
            }

            $masterPid = $fingerprint->pid;
            if ($masterPid > 0) {
                $actualParent = $preKillParentPid ?? $this->getParentPid($masterPid);
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

        if ($this->isWorkermanMasterTitle($parentPid)) {
            posix_kill($parentPid, \SIGKILL);
        }
    }

    private function isWorkermanMasterTitle(int $pid): bool
    {
        if (self::isLinux()) {
            $content = ProcFileReader::read("/proc/{$pid}/cmdline");
            if ($content === null) {
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
     * @phpstan-impure
     */
    private function readParentPidViaPs(int $pid): int
    {
        $result = ($this->psRunner)(['-o', 'ppid=', '-p', (string) $pid]);

        if ($result === null) {
            $this->logger->warning('Cannot read parent PID: exec() is disabled; treating as no parent', [
                'pid' => $pid,
            ]);

            return 0;
        }

        $output = $result['output'];
        $exitCode = $result['exitCode'];

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
     * @phpstan-impure
     */
    private function readProcessCommandViaPs(int $pid): ?string
    {
        $result = ($this->psRunner)(['-ww', '-o', 'args=', '-p', (string) $pid]);

        if ($result === null) {
            $this->logger->warning('Cannot inspect process command: exec() is disabled', [
                'pid' => $pid,
            ]);

            return null;
        }

        $output = $result['output'];
        $exitCode = $result['exitCode'];

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

    public function waitForProcessToStopWithin(int $pid, int $timeout): bool
    {
        return Wait::until(fn(): bool => !$this->isProcessAlive($pid), $timeout + self::TIMEOUT_BUFFER);
    }

    private function isLinux(): bool
    {
        return $this->osFamily === 'Linux';
    }

    /**
     * @param list<string> $arguments
     * @return array{output: list<string>, exitCode: int}|null
     * @phpstan-impure
     */
    private function execPs(array $arguments): ?array
    {
        if (!\function_exists('exec')) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        @\exec('ps ' . \implode(' ', $arguments) . ' 2>/dev/null', $output, $exitCode);

        return ['output' => $output, 'exitCode' => $exitCode];
    }

    /**
     * @phpstan-impure
     */
    private function readProcessStateFromStat(int $pid): ?string
    {
        $content = ProcFileReader::read("/proc/{$pid}/stat");
        if ($content === null) {
            return null;
        }

        $afterParts = ProcStatParser::splitFields($content);
        if ($afterParts === null || $afterParts === [] || $afterParts[0] === '') {
            return null;
        }

        return $afterParts[0][0];
    }

    /**
     * @phpstan-impure
     */
    private function readLinuxProcessSnapshot(int $pid): ?ProcessSnapshot
    {
        $content = ProcFileReader::read("/proc/{$pid}/stat");
        if ($content === null) {
            return null;
        }

        $afterParts = ProcStatParser::splitFields($content);
        if ($afterParts === null || $afterParts === [] || $afterParts[0] === '') {
            return null;
        }

        $state = $afterParts[0][0];
        $startTime = \count($afterParts) >= 20 ? max((int) $afterParts[19], 0) : 0;

        $uid = MasterFingerprint::readUidForPid($pid);

        return new ProcessSnapshot($state, $startTime, $uid);
    }

    private function snapshotMatchesFingerprint(\CrazyGoat\WorkermanBundle\ProcessSnapshot $snapshot, \CrazyGoat\WorkermanBundle\MasterFingerprint $fingerprint): bool
    {
        if ($snapshot->uid === null) {
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
     * @phpstan-impure
     */
    private function isAliveNonLinux(int $pid): bool
    {
        $result = pcntl_waitpid($pid, $status, \WNOHANG);
        if ($result > 0) {
            return false;
        }
        if ($result === 0) {
            return true;
        }

        $state = $this->readProcessStateViaPs($pid);
        if ($state === null) {
            return true;
        }

        return $state !== '' && !str_starts_with($state, 'Z');
    }

    /**
     * @phpstan-impure
     */
    private function readProcessStateViaPs(int $pid): ?string
    {
        $result = ($this->psRunner)(['-o', 'stat=', '-p', (string) $pid]);

        if ($result === null) {
            $this->logger->warning('Cannot inspect process state: exec() is disabled; treating process as alive', [
                'pid' => $pid,
            ]);

            return null;
        }

        $output = $result['output'];
        $exitCode = $result['exitCode'];

        if ($exitCode === 126 || $exitCode === 127) {
            $this->logger->warning('Cannot inspect process state: ps command not available; treating process as alive', [
                'pid' => $pid,
                'exit_code' => $exitCode,
            ]);

            return null;
        }

        if ($exitCode !== 0) {
            if (posix_kill($pid, 0)) {
                $this->logger->warning('Cannot inspect process state: ps exited non-zero on a signalable PID; treating process as alive', [
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
}
