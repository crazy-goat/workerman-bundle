<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\ProcessInspector;
use CrazyGoat\WorkermanBundle\Util\ProcStatParser;
use CrazyGoat\WorkermanBundle\Util\Wait;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProcessInspectorTest extends TestCase
{
    private ProcessInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new ProcessInspector();
    }

    private function invokeWaitForProcessToStop(
        ProcessInspector $inspector,
        int $pid,
        int $stopTimeout,
        bool $graceful,
    ): bool {
        $reflection = new ReflectionClass($inspector);
        $method = $reflection->getMethod('waitForProcessToStop');

        return $method->invoke($inspector, $pid, $stopTimeout, $graceful);
    }

    public function testGracefulStopRespectsTimeout(): void
    {
        $startTime = microtime(true);
        $result = $this->invokeWaitForProcessToStop($this->inspector, 0, 1, true);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return immediately for non-existent PID');
    }

    public function testRegularStopRespectsTimeout(): void
    {
        $startTime = microtime(true);
        $result = $this->invokeWaitForProcessToStop($this->inspector, 0, 1, false);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(1, $elapsed, 'Should return immediately for non-existent PID');
    }

    public function testTimeoutConstantsExist(): void
    {
        $reflection = new ReflectionClass(ProcessInspector::class);

        $this->assertTrue(
            $reflection->hasConstant('GRACEFUL_TIMEOUT_MULTIPLIER'),
            'GRACEFUL_TIMEOUT_MULTIPLIER constant must exist',
        );
        $this->assertTrue(
            $reflection->hasConstant('TIMEOUT_BUFFER'),
            'TIMEOUT_BUFFER constant must exist',
        );

        $multiplierRef = $reflection->getReflectionConstant('GRACEFUL_TIMEOUT_MULTIPLIER');
        $bufferRef = $reflection->getReflectionConstant('TIMEOUT_BUFFER');

        $this->assertInstanceOf(\ReflectionClassConstant::class, $multiplierRef);
        $this->assertInstanceOf(\ReflectionClassConstant::class, $bufferRef);

        $this->assertTrue($multiplierRef->isPrivate(), 'GRACEFUL_TIMEOUT_MULTIPLIER should be private');
        $this->assertTrue($bufferRef->isPrivate(), 'TIMEOUT_BUFFER should be private');

        $this->assertSame(3, $multiplierRef->getValue(), 'GRACEFUL_TIMEOUT_MULTIPLIER must be 3');
        $this->assertSame(3, $bufferRef->getValue(), 'TIMEOUT_BUFFER must be 3');
    }

    public function testGracefulTimeoutIsAlwaysLongerThanRegular(): void
    {
        $reflection = new ReflectionClass(ProcessInspector::class);
        $multiplierRef = $reflection->getReflectionConstant('GRACEFUL_TIMEOUT_MULTIPLIER');
        $bufferRef = $reflection->getReflectionConstant('TIMEOUT_BUFFER');
        /** @var int $multiplier */
        $multiplier = $multiplierRef instanceof \ReflectionClassConstant ? $multiplierRef->getValue() : 3;
        /** @var int $buffer */
        $buffer = $bufferRef instanceof \ReflectionClassConstant ? $bufferRef->getValue() : 3;

        $testCases = [
            ['stopTimeout' => 1],
            ['stopTimeout' => 2],
            ['stopTimeout' => 5],
            ['stopTimeout' => 10],
        ];

        foreach ($testCases as $case) {
            $stopTimeout = $case['stopTimeout'];
            $gracefulTimeout = $stopTimeout * $multiplier + $buffer;
            $regularTimeout = $stopTimeout + $buffer;

            $this->assertGreaterThan(
                $regularTimeout,
                $gracefulTimeout,
                "Graceful timeout ({$gracefulTimeout}s) must be longer than regular ({$regularTimeout}s) for stopTimeout={$stopTimeout}",
            );
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsProcessAliveReturnsFalseForDeadProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertTrue($this->inspector->isProcessAlive($pid), 'Child should be alive before kill');

            posix_kill($pid, SIGKILL);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 2);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'isProcessAlive() must return false for a dead process on ' . PHP_OS_FAMILY,
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsProcessAliveReturnsTrueForRunningProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'isProcessAlive() must return true for a running process on ' . PHP_OS_FAMILY,
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsProcessAliveReturnsTrueForNonChildProcess(): void
    {
        $parentPid = posix_getppid();

        $this->assertTrue(
            posix_kill($parentPid, 0),
            'Parent PID must be signalable for this test to be meaningful',
        );

        $this->assertTrue(
            $this->inspector->isProcessAlive($parentPid),
            'isProcessAlive() must return true for a non-child process on ' . PHP_OS_FAMILY,
        );
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsProcessAliveReturnsFalseForNonChildZombie(): void
    {
        $marker = \sys_get_temp_dir() . '/workerman_zombie_' . \bin2hex(\random_bytes(4));

        $childA = pcntl_fork();
        if ($childA === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($childA === 0) {
            $childB = pcntl_fork();
            if ($childB === -1) {
                exit(1);
            }
            if ($childB > 0) {
                $tmp = $marker . '.' . \bin2hex(\random_bytes(4)) . '.tmp';
                \file_put_contents($tmp, (string) $childB);
                \rename($tmp, $marker);
                for (;;) {
                    sleep(1);
                }
            }
            posix_kill(posix_getpid(), SIGKILL);
            exit(1);
        }

        try {
            $this->waitForFile($marker, 3);
            $zombiePid = (int) @file_get_contents($marker);
            $this->assertGreaterThan(0, $zombiePid, 'Child A must publish the grandchild PID');

            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($zombiePid), 2);

            $this->assertFalse(
                $this->inspector->isProcessAlive($zombiePid),
                'isProcessAlive() must return false for a non-child zombie on ' . PHP_OS_FAMILY,
            );
        } finally {
            @\unlink($marker);
            posix_kill($childA, SIGKILL);
            pcntl_waitpid($childA, $status);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsProcessAliveReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse($this->inspector->isProcessAlive(0));
        $this->assertFalse($this->inspector->isProcessAlive(-1));
        $this->assertFalse($this->inspector->isProcessAlive(999_999_999));
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testGetParentPidReturnsParentOnNonLinux(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertSame(
                \getmypid(),
                $this->inspector->getParentPid($pid),
                'getParentPid() must return the parent PID on non-Linux via ps',
            );
            $this->assertSame(0, $this->inspector->getParentPid(0));
            $this->assertSame(0, $this->inspector->getParentPid(-1));
            $this->assertSame(0, $this->inspector->getParentPid(999_999_999));
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testGetParentPidReturnsParentOnLinux(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertSame(
                \getmypid(),
                $this->inspector->getParentPid($pid),
                'getParentPid() must return the parent PID on Linux via /proc/{pid}/stat',
            );
            $this->assertSame(0, $this->inspector->getParentPid(0));
            $this->assertSame(0, $this->inspector->getParentPid(-1));
            $this->assertSame(0, $this->inspector->getParentPid(999_999_999));
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testGetParentPidHandlesCommWithSpacesAndParensOnLinux(): void
    {
        $sleep = \is_file('/bin/sleep') ? '/bin/sleep' : '/usr/bin/sleep';
        if (!\is_file($sleep)) {
            $this->markTestSkipped('sleep binary not found');
        }

        $copy = sys_get_temp_dir() . '/wmb (paren) ' . bin2hex(random_bytes(4));
        if (!@\copy($sleep, $copy) || !@\chmod($copy, 0755)) {
            $this->markTestSkipped('cannot stage renamed sleep binary');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            @\unlink($copy);
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            pcntl_exec($copy, ['60']);
            \fwrite(STDERR, 'Unable to exec renamed sleep' . PHP_EOL);
            exit(1);
        }

        try {
            $this->assertSame(
                \getmypid(),
                $this->inspector->getParentPid($pid),
                'getParentPid() must return the parent PID when comm contains spaces and parens',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            @\unlink($copy);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testMatchesFingerprintReturnsTrueForCurrentProcess(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $this->assertTrue(
            $this->inspector->matchesFingerprint($fingerprint->pid, $fingerprint),
            'matchesFingerprint() must return true for the current process with its own fingerprint',
        );
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testMatchesFingerprintReturnsFalseForDifferentPid(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertFalse(
                $this->inspector->matchesFingerprint($pid, $fingerprint),
                'matchesFingerprint() must return false for a PID different from the fingerprint PID',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    public function testMatchesFingerprintReturnsFalseForNonExistentPid(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $this->assertFalse($this->inspector->matchesFingerprint(0, $fingerprint));
        $this->assertFalse($this->inspector->matchesFingerprint(-1, $fingerprint));
        $this->assertFalse($this->inspector->matchesFingerprint(999_999_999, $fingerprint));
    }

    public function testMatchesFingerprintReturnsFalseForInvalidFingerprintPid(): void
    {
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(0, 0, 0);
        $currentPid = \getmypid();
        $this->assertIsInt($currentPid);

        $this->assertFalse(
            $this->inspector->matchesFingerprint($currentPid, $fingerprint),
            'matchesFingerprint() must return false when fingerprint PID is invalid',
        );
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsMasterRunningWithValidFingerprint(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $this->assertTrue(
            $this->inspector->isMasterRunning($fingerprint->pid, $fingerprint),
            'isMasterRunning() must return true when fingerprint matches the candidate PID',
        );
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsMasterRunningWithFingerprintRejectsUnrelatedProcess(): void
    {
        $fingerprint = \CrazyGoat\WorkermanBundle\MasterFingerprint::capture();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertFalse(
                $this->inspector->isMasterRunning($pid, $fingerprint),
                'isMasterRunning() with fingerprint must reject a PID different from the fingerprint PID',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsMasterRunningWithoutFingerprintRejectsPlainPhpProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertFalse(
                $this->inspector->isMasterRunning($pid),
                'isMasterRunning() without fingerprint must reject a plain PHP process',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsMasterRunningWithoutFingerprintAcceptsWorkermanMasterTitle(): void
    {
        $pid = $this->forkChildWithMasterTitle();

        try {
            $this->assertTrue(
                $this->inspector->isMasterRunning($pid),
                'isMasterRunning() must accept a process carrying the Workerman master title',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithoutFingerprintKillsMasterTitleProcess(): void
    {
        $pid = $this->forkChildWithMasterTitle();

        try {
            $this->inspector->killOrphanedIntermediateFork($pid);

            $this->waitForProcessDeath($pid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must kill a process carrying the Workerman master title',
            );
        } finally {
            if ($this->inspector->isProcessAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
        }
    }

    private function waitForProcessDeath(int $pid): void
    {
        Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);
    }

    private function forkChildWithMasterTitle(): int
    {
        $marker = \sys_get_temp_dir() . '/workerman_master_title_' . \bin2hex(\random_bytes(4));
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            $this->execAsMasterTitle($marker);
        }

        $this->waitForFile($marker, 3);
        @\unlink($marker);

        return $pid;
    }

    private function execAsMasterTitle(string $marker): never
    {
        $title = 'WorkerMan: master process  start_file=' . __FILE__;
        $script = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGINT, static function (): void { exit(0); });
pcntl_signal(SIGQUIT, static function (): void { exit(0); });
pcntl_signal(SIGTERM, static function (): void { exit(0); });
touch(__MARKER__);
while (true) {
    sleep(1);
}
PHP;
        $script = str_replace('__MARKER__', var_export($marker, true), $script);
        $command = 'exec -a ' . escapeshellarg($title)
            . ' ' . escapeshellarg(\PHP_BINARY)
            . ' -r ' . escapeshellarg($script)
            . ' < /dev/null > /dev/null 2>&1';

        pcntl_exec('/bin/bash', ['-c', $command]);
        \fwrite(\STDERR, 'Unable to exec master-title process' . \PHP_EOL);
        exit(1);
    }

    private function waitForFile(string $path, int $timeout): void
    {
        Wait::until(static fn(): bool => \file_exists($path), $timeout);
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithMatchingFingerprintKillsProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        $fingerprint = $this->captureFingerprintForPid($pid);

        try {
            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint);

            $this->waitForProcessDeath($pid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must kill the process when fingerprint matches',
            );
        } finally {
            if ($this->inspector->isProcessAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithFingerprintDoesNotKillUnrelatedProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
            pid: $pid + 1_000_000,
            startTime: 0,
            uid: \posix_getuid(),
        );

        try {
            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint);

            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must NOT kill a process whose PID does not match the fingerprint',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithoutFingerprintUsesLegacyCheck(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->inspector->killOrphanedIntermediateFork($pid);

            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() without fingerprint must use legacy cmdline check (no kill for non-WorkerMan cmdline)',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithFingerprintKillsParentViaAncestryOnDaemon(): void
    {
        $pair = $this->forkAncestryPair(true);
        $intermediatePid = $pair['parent'];
        $masterPid = $pair['child'];

        try {
            $fingerprint = $this->captureFingerprintForPid($masterPid);

            $this->assertSame(
                $intermediatePid,
                $this->inspector->getParentPid($masterPid),
                'Master must be child of the intermediate for this test to be meaningful',
            );
            $this->assertTrue(
                $this->inspector->isProcessAlive($intermediatePid),
                'Intermediate must be alive before kill',
            );

            $this->inspector->killOrphanedIntermediateFork($intermediatePid, $fingerprint);

            $this->waitForProcessDeath($intermediatePid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($intermediatePid),
                'killOrphanedIntermediateFork() must kill the intermediate via ancestry when fingerprint names its child master',
            );
            $this->assertTrue(
                $this->inspector->isProcessAlive($masterPid),
                'Master must stay alive after its intermediate parent is killed',
            );
        } finally {
            if ($this->inspector->isProcessAlive($masterPid)) {
                posix_kill($masterPid, SIGKILL);
            }
            if ($this->inspector->isProcessAlive($intermediatePid)) {
                posix_kill($intermediatePid, SIGKILL);
            }
            @pcntl_waitpid($intermediatePid, $status);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($masterPid), 1);
            @\unlink($pair['script']);
            @\unlink($pair['masterMarker']);
            @\unlink($pair['parentMarker']);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithFingerprintDoesNotKillNonWorkermanParentViaAncestry(): void
    {
        $pair = $this->forkAncestryPair(false);
        $parentPid = $pair['parent'];
        $masterPid = $pair['child'];

        try {
            $fingerprint = $this->captureFingerprintForPid($masterPid);

            $this->assertSame(
                $parentPid,
                $this->inspector->getParentPid($masterPid),
                'Master must be child of the parent for this test to be meaningful',
            );

            $this->inspector->killOrphanedIntermediateFork($parentPid, $fingerprint);

            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($parentPid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($parentPid),
                'killOrphanedIntermediateFork() must NOT kill a non-Workerman parent even when ancestry matches (shell safety)',
            );
        } finally {
            if ($this->inspector->isProcessAlive($masterPid)) {
                posix_kill($masterPid, SIGKILL);
            }
            if ($this->inspector->isProcessAlive($parentPid)) {
                posix_kill($parentPid, SIGKILL);
            }
            @pcntl_waitpid($parentPid, $status);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($masterPid), 1);
            @\unlink($pair['script']);
            @\unlink($pair['masterMarker']);
            @\unlink($pair['parentMarker']);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkUsesPreKillProofWhenMasterReaped(): void
    {
        $pid = $this->forkChildWithMasterTitle();

        try {
            $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
                pid: 999_999_999,
                startTime: 0,
                uid: \posix_getuid(),
            );
            $this->assertSame(
                0,
                $this->inspector->getParentPid(999_999_999),
                'Post-mortem getParentPid() must return 0 for a reaped master for this test to be meaningful',
            );

            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() without pre-kill proof must refuse once the master is reaped',
            );

            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint, $pid);

            $this->waitForProcessDeath($pid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must use the pre-kill proof instead of the post-mortem re-read',
            );
        } finally {
            if ($this->inspector->isProcessAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkRefusesWhenPreKillProofMismatches(): void
    {
        $pid = $this->forkChildWithMasterTitle();

        try {
            $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(
                pid: 999_999_999,
                startTime: 0,
                uid: \posix_getuid(),
            );

            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint, $pid + 1_000_000);
            $this->inspector->killOrphanedIntermediateFork($pid, $fingerprint, 0);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($pid),
                'killOrphanedIntermediateFork() must NOT kill when the pre-kill proof does not match the candidate PID',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithFingerprintKillsParentViaAncestryOnDarwin(): void
    {
        $pair = $this->forkAncestryPair(true);
        $intermediatePid = $pair['parent'];
        $masterPid = $pair['child'];

        try {
            $fingerprint = $this->captureFingerprintForPid($masterPid);

            $this->assertSame(
                $intermediatePid,
                $this->inspector->getParentPid($masterPid),
                'Master must be child of the intermediate for this test to be meaningful',
            );
            $this->assertTrue(
                $this->inspector->isProcessAlive($intermediatePid),
                'Intermediate must be alive before kill',
            );

            $this->inspector->killOrphanedIntermediateFork($intermediatePid, $fingerprint);

            $this->waitForProcessDeath($intermediatePid);

            $this->assertFalse(
                $this->inspector->isProcessAlive($intermediatePid),
                'killOrphanedIntermediateFork() must kill the intermediate via ancestry on Darwin',
            );
            $this->assertTrue(
                $this->inspector->isProcessAlive($masterPid),
                'Master must stay alive after its intermediate parent is killed',
            );
        } finally {
            if ($this->inspector->isProcessAlive($masterPid)) {
                posix_kill($masterPid, SIGKILL);
            }
            if ($this->inspector->isProcessAlive($intermediatePid)) {
                posix_kill($intermediatePid, SIGKILL);
            }
            @pcntl_waitpid($intermediatePid, $status);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($masterPid), 1);
            @\unlink($pair['script']);
            @\unlink($pair['masterMarker']);
            @\unlink($pair['parentMarker']);
        }
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testKillOrphanedIntermediateForkWithFingerprintDoesNotKillNonWorkermanParentViaAncestryOnDarwin(): void
    {
        $pair = $this->forkAncestryPair(false);
        $parentPid = $pair['parent'];
        $masterPid = $pair['child'];

        try {
            $fingerprint = $this->captureFingerprintForPid($masterPid);

            $this->assertSame(
                $parentPid,
                $this->inspector->getParentPid($masterPid),
                'Master must be child of the parent for this test to be meaningful',
            );

            $this->inspector->killOrphanedIntermediateFork($parentPid, $fingerprint);

            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($parentPid), 1);

            $this->assertTrue(
                $this->inspector->isProcessAlive($parentPid),
                'killOrphanedIntermediateFork() must NOT kill a non-Workerman parent even when ancestry matches on Darwin',
            );
        } finally {
            if ($this->inspector->isProcessAlive($masterPid)) {
                posix_kill($masterPid, SIGKILL);
            }
            if ($this->inspector->isProcessAlive($parentPid)) {
                posix_kill($parentPid, SIGKILL);
            }
            @pcntl_waitpid($parentPid, $status);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($masterPid), 1);
            @\unlink($pair['script']);
            @\unlink($pair['masterMarker']);
            @\unlink($pair['parentMarker']);
        }
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testGetParentPidReturnsZeroForNonExistentPidOnDarwin(): void
    {
        $this->assertSame(0, $this->inspector->getParentPid(999_999_999));
        $this->assertSame(0, $this->inspector->getParentPid(0));
        $this->assertSame(0, $this->inspector->getParentPid(-1));
    }

    /**
     * @return array{parent: int, child: int, script: string, masterMarker: string, parentMarker: string}
     */
    private function forkAncestryPair(bool $parentHasTitle): array
    {
        $tmpDir = sys_get_temp_dir();
        $suffix = bin2hex(random_bytes(4));
        $script = $tmpDir . '/workerman_ancestry_' . $suffix . '.php';
        $parentMarker = $tmpDir . '/workerman_ancestry_parent_' . $suffix;
        $masterMarker = $tmpDir . '/workerman_ancestry_master_' . $suffix;

        $scriptContent = <<<'PHP'
<?php
$masterMarker = $argv[1];
$parentMarker = $argv[2];
pcntl_async_signals(true);
pcntl_signal(SIGINT, static function (): void { exit(0); });
pcntl_signal(SIGTERM, static function (): void { exit(0); });
pcntl_signal(SIGQUIT, static function (): void { exit(0); });
$child = pcntl_fork();
if ($child === -1) {
    exit(1);
}
if ($child > 0) {
    // Parent (intermediate / shell parent)
    file_put_contents($parentMarker, (string) getmypid());
    while (true) {
        sleep(1);
    }
}
// Child (master)
file_put_contents($masterMarker, (string) getmypid());
while (true) {
    sleep(1);
}
PHP;
        file_put_contents($script, $scriptContent);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            if ($parentHasTitle) {
                $title = 'WorkerMan: master process  start_file=' . __FILE__;
                $command = 'exec -a ' . escapeshellarg($title)
                    . ' ' . escapeshellarg(PHP_BINARY)
                    . ' ' . escapeshellarg($script)
                    . ' ' . escapeshellarg($masterMarker)
                    . ' ' . escapeshellarg($parentMarker)
                    . ' < /dev/null > /dev/null 2>&1';
                pcntl_exec('/bin/bash', ['-c', $command]);
                fwrite(STDERR, 'Unable to exec ancestry parent' . PHP_EOL);
                exit(1);
            }

            pcntl_exec(PHP_BINARY, [$script, $masterMarker, $parentMarker]);
            fwrite(STDERR, 'Unable to exec ancestry parent' . PHP_EOL);
            exit(1);
        }

        $this->waitForFile($parentMarker, 3);
        $this->waitForFile($masterMarker, 3);
        $parentPid = (int) @file_get_contents($parentMarker);
        $childPid = (int) @file_get_contents($masterMarker);

        if ($parentPid <= 0 || $childPid <= 0) {
            posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $status);
            @unlink($script);
            @unlink($parentMarker);
            @unlink($masterMarker);
            $this->fail('Ancestry pair markers must contain valid PIDs');
        }

        return [
            'parent' => $parentPid,
            'child' => $childPid,
            'script' => $script,
            'masterMarker' => $masterMarker,
            'parentMarker' => $parentMarker,
        ];
    }

    private function captureFingerprintForPid(int $pid): \CrazyGoat\WorkermanBundle\MasterFingerprint
    {
        $startTime = 0;
        $statFile = "/proc/{$pid}/stat";
        if (\is_readable($statFile)) {
            $content = \file_get_contents($statFile);
            if (\is_string($content)) {
                $afterParts = ProcStatParser::splitFields($content);
                if (\is_array($afterParts) && \count($afterParts) >= 20) {
                    $startTime = (int) $afterParts[19];
                }
            }
        }

        $uid = \CrazyGoat\WorkermanBundle\MasterFingerprint::readUidForPid($pid) ?? \posix_getuid();

        return new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, $startTime, $uid);
    }

    private function invokePrivateMethod(string $name, int $pid): mixed
    {
        $reflection = new ReflectionClass($this->inspector);
        $method = $reflection->getMethod($name);

        return $method->invoke($this->inspector, $pid);
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadProcessStateViaPsReturnsEmptyForNonExistentPid(): void
    {
        if (!\function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, cannot test ps path');
        }

        $state = $this->invokePrivateMethod('readProcessStateViaPs', 999_999_999);

        $this->assertSame('', $state, 'ps must return empty string for a non-existent PID');
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadProcessStateViaPsReturnsStateForRunningPid(): void
    {
        if (!\function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, cannot test ps path');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $state = $this->invokePrivateMethod('readProcessStateViaPs', $pid);

            $this->assertNotSame('', $state, 'ps must return a non-empty state for a running PID');
            $this->assertFalse(
                str_starts_with($state, 'Z'),
                'ps must not report a running process as a zombie',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsAliveNonLinuxReturnsFalseForNonExistentPid(): void
    {
        $this->assertFalse(
            $this->invokePrivateMethod('isAliveNonLinux', 999_999_999),
            'isAliveNonLinux() must return false for a non-existent PID',
        );
    }

    #[RequiresOperatingSystem('Darwin')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testIsAliveNonLinuxReturnsTrueForRunningPid(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $this->assertTrue(
                $this->invokePrivateMethod('isAliveNonLinux', $pid),
                'isAliveNonLinux() must return true for a running direct child',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * @param list<string> $output
     * @return array{output: list<string>, exitCode: int}
     */
    private function psResult(array $output, int $exitCode = 0): array
    {
        return ['output' => $output, 'exitCode' => $exitCode];
    }

    /**
     * @param array{output: list<string>, exitCode: int}|null $result
     */
    private function inspectorWithPsResult(?array $result): ProcessInspector
    {
        /** @var \Closure(array<mixed>): (array{output: list<string>, exitCode: int}|null) $runner */
        $runner = static fn(array $arguments): ?array => $result;

        return new ProcessInspector(psRunner: $runner, osFamily: 'Darwin');
    }

    private function invokeInspectorPrivateMethod(ProcessInspector $inspector, string $name, int $pid): mixed
    {
        $method = (new ReflectionClass($inspector))->getMethod($name);

        return $method->invoke($inspector, $pid);
    }

    private function currentPid(): int
    {
        $pid = \getmypid();
        $this->assertIsInt($pid);

        return $pid;
    }

    public function testReadProcessStateViaPsUsesTheRealPsByDefault(): void
    {
        if (!\function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, cannot test the default ps runner');
        }

        $inspector = new ProcessInspector(osFamily: 'Darwin');

        $state = $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', $this->currentPid());

        $this->assertIsString($state);
        $this->assertNotSame('STAT', $state, 'The state query must suppress the ps header');
        $this->assertMatchesRegularExpression(
            '/^[A-Z][A-Za-z+<>]*$/',
            $state,
            'The default runner must return a parsed ps state token, not a header or free text',
        );
        $this->assertFalse(
            str_starts_with($state, 'Z'),
            'The current test process must not be reported as a zombie',
        );
    }

    public function testGetParentPidNonLinuxPassesTheExactPsArgv(): void
    {
        /** @var list<list<string>> $calls */
        $calls = [];
        $runner = function (array $arguments) use (&$calls): array {
            $calls[] = $arguments;

            return $this->psResult(['42']);
        };

        $inspector = new ProcessInspector(psRunner: $runner, osFamily: 'Darwin');

        $this->assertSame(42, $inspector->getParentPid(123));
        $this->assertSame([['-o', 'ppid=', '-p', '123']], $calls);
    }

    public function testIsProcessAliveNonLinuxPassesTheExactPsArgv(): void
    {
        $pid = $this->currentPid();
        /** @var list<list<string>> $calls */
        $calls = [];
        $runner = function (array $arguments) use (&$calls): array {
            $calls[] = $arguments;

            return $this->psResult(['S']);
        };

        $inspector = new ProcessInspector(psRunner: $runner, osFamily: 'Darwin');

        $this->assertTrue($inspector->isProcessAlive($pid));
        $this->assertSame([['-o', 'stat=', '-p', (string) $pid]], $calls);
    }

    public function testIsWorkermanMasterTitleNonLinuxPassesTheExactPsArgv(): void
    {
        $pid = $this->currentPid();
        /** @var list<list<string>> $calls */
        $calls = [];
        $runner = function (array $arguments) use (&$calls): array {
            $calls[] = $arguments;

            return $arguments[1] === 'args=' ? $this->psResult(['garbage output']) : $this->psResult(['S']);
        };

        $inspector = new ProcessInspector(psRunner: $runner, osFamily: 'Darwin');

        $inspector->killOrphanedIntermediateFork($pid);

        $this->assertSame([
            ['-o', 'stat=', '-p', (string) $pid],
            ['-ww', '-o', 'args=', '-p', (string) $pid],
        ], $calls);
    }

    public function testReadParentPidViaPsReturnsParsedPpid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['  4242 ']));

        $this->assertSame(
            4242,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', 123),
        );
    }

    public function testReadParentPidViaPsReturnsZeroForEmptyOutput(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([]));

        $this->assertSame(
            0,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', 123),
            'An empty ps result must read as "no parent"',
        );
    }

    public function testReadParentPidViaPsReturnsZeroForGarbageOutput(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['not-a-pid']));

        $this->assertSame(
            0,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', 123),
            'Unparseable ps output must read as "no parent"',
        );
    }

    public function testReadParentPidViaPsReturnsZeroWhenPsIsUnavailable(): void
    {
        $inspector = $this->inspectorWithPsResult(null);

        $this->assertSame(
            0,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', 123),
            'A missing ps must read as "no parent"',
        );
    }

    public function testReadParentPidViaPsReturnsZeroWhenPsIsNotInstalled(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 127));

        $this->assertSame(
            0,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', 123),
            'ps exit 127 must read as "no parent"',
        );
    }

    public function testReadParentPidViaPsReturnsZeroWhenPsFailsOnSignalablePid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 1));

        $this->assertSame(
            0,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', $this->currentPid()),
            'A failed ps on a still-signalable PID must read as "no parent"',
        );
    }

    public function testReadParentPidViaPsReturnsZeroWhenPsFailsOnGonePid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 1));

        $this->assertSame(
            0,
            $this->invokeInspectorPrivateMethod($inspector, 'readParentPidViaPs', 999_999_999),
            'A failed ps on an unsignalable PID must read as "no parent"',
        );
    }

    public function testReadProcessCommandViaPsReturnsTrimmedCommand(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['  WorkerMan: master process  start.php  ']));

        $this->assertSame(
            'WorkerMan: master process  start.php',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', 123),
        );
    }

    public function testReadProcessCommandViaPsReturnsEmptyForEmptyOutput(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([]));

        $this->assertSame(
            '',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', 123),
            'An empty ps result must read as "process gone"',
        );
    }

    public function testReadProcessCommandViaPsReturnsGarbageWithoutMatchingMasterTitle(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['garbage output']));

        $command = $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', 123);

        $this->assertSame('garbage output', $command);
        $this->assertFalse(
            str_contains($command, 'WorkerMan: master process'),
            'Garbage output must not be mistaken for a Workerman master',
        );
    }

    public function testReadProcessCommandViaPsReturnsNullWhenPsIsUnavailable(): void
    {
        $inspector = $this->inspectorWithPsResult(null);

        $this->assertNull(
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', 123),
            'A missing ps must fail closed',
        );
    }

    public function testReadProcessCommandViaPsReturnsNullWhenPsIsNotInstalled(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 127));

        $this->assertNull(
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', 123),
            'ps exit 127 must fail closed',
        );
    }

    public function testReadProcessCommandViaPsReturnsNullWhenPsFailsOnSignalablePid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 1));

        $this->assertNull(
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', $this->currentPid()),
            'A failed ps on a still-signalable PID must fail closed',
        );
    }

    public function testReadProcessCommandViaPsReturnsEmptyWhenPsFailsOnGonePid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 1));

        $this->assertSame(
            '',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessCommandViaPs', 999_999_999),
            'A failed ps on an unsignalable PID must read as "process gone"',
        );
    }

    public function testIsWorkermanMasterTitleNonLinuxReturnsTrueForMasterCommand(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['WorkerMan: master process start.php']));

        $this->assertTrue(
            $this->invokeInspectorPrivateMethod($inspector, 'isWorkermanMasterTitle', 123),
        );
    }

    public function testIsWorkermanMasterTitleNonLinuxReturnsFalseForEmptyCommand(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([]));

        $this->assertFalse(
            $this->invokeInspectorPrivateMethod($inspector, 'isWorkermanMasterTitle', 123),
            'An empty ps command must not match the master title',
        );
    }

    public function testIsWorkermanMasterTitleNonLinuxReturnsFalseForGarbageCommand(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['garbage output']));

        $this->assertFalse(
            $this->invokeInspectorPrivateMethod($inspector, 'isWorkermanMasterTitle', 123),
            'Garbage output must not match the master title',
        );
    }

    public function testIsWorkermanMasterTitleNonLinuxReturnsFalseWhenPsIsUnavailable(): void
    {
        $inspector = $this->inspectorWithPsResult(null);

        $this->assertFalse(
            $this->invokeInspectorPrivateMethod($inspector, 'isWorkermanMasterTitle', 123),
            'A missing ps must fail closed (no match)',
        );
    }

    public function testReadProcessStateViaPsReturnsTrimmedState(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([' R+ ']));

        $this->assertSame(
            'R+',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 123),
        );
    }

    public function testReadProcessStateViaPsReturnsEmptyForEmptyOutput(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([]));

        $this->assertSame(
            '',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 123),
            'An empty ps result must read as "process gone"',
        );
    }

    public function testReadProcessStateViaPsReturnsZombieState(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['Z']));

        $this->assertSame(
            'Z',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 123),
        );
    }

    public function testReadProcessStateViaPsReturnsGarbageState(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['garbage']));

        $this->assertSame(
            'garbage',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 123),
            'Unparseable ps output must be surfaced, not silently turned into a state',
        );
    }

    public function testReadProcessStateViaPsReturnsNullWhenPsIsUnavailable(): void
    {
        $inspector = $this->inspectorWithPsResult(null);

        $this->assertNull(
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 123),
            'A missing ps must fail closed by treating the process as alive',
        );
    }

    public function testReadProcessStateViaPsReturnsNullWhenPsIsNotInstalled(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 127));

        $this->assertNull(
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 123),
            'ps exit 127 must fail closed by treating the process as alive',
        );
    }

    public function testReadProcessStateViaPsReturnsNullWhenPsFailsOnSignalablePid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 1));

        $this->assertNull(
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', $this->currentPid()),
            'A failed ps on a still-signalable PID must fail closed',
        );
    }

    public function testReadProcessStateViaPsReturnsEmptyWhenPsFailsOnGonePid(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([], 1));

        $this->assertSame(
            '',
            $this->invokeInspectorPrivateMethod($inspector, 'readProcessStateViaPs', 999_999_999),
            'A failed ps on an unsignalable PID must read as "process gone"',
        );
    }

    public function testIsAliveNonLinuxReturnsTrueForRunningState(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['S']));

        $this->assertTrue(
            $this->invokeInspectorPrivateMethod($inspector, 'isAliveNonLinux', 999_999_999),
        );
    }

    public function testIsAliveNonLinuxReturnsFalseForZombieState(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['Z']));

        $this->assertFalse(
            $this->invokeInspectorPrivateMethod($inspector, 'isAliveNonLinux', 999_999_999),
            'A zombie must not be alive',
        );
    }

    public function testIsAliveNonLinuxReturnsFalseForZombieStateWithFlags(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['Z+']));

        $this->assertFalse(
            $this->invokeInspectorPrivateMethod($inspector, 'isAliveNonLinux', 999_999_999),
            'A zombie with ps state flags must not be alive',
        );
    }

    public function testIsAliveNonLinuxReturnsFalseForEmptyState(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult([]));

        $this->assertFalse(
            $this->invokeInspectorPrivateMethod($inspector, 'isAliveNonLinux', 999_999_999),
            'An empty state means the process is gone',
        );
    }

    public function testIsAliveNonLinuxTreatsGarbageStateAsAlive(): void
    {
        $inspector = $this->inspectorWithPsResult($this->psResult(['garbage']));

        $this->assertTrue(
            $this->invokeInspectorPrivateMethod($inspector, 'isAliveNonLinux', 999_999_999),
            'An unrecognised state must fail closed by treating the process as alive',
        );
    }

    public function testIsAliveNonLinuxTreatsMissingPsAsAlive(): void
    {
        $inspector = $this->inspectorWithPsResult(null);

        $this->assertTrue(
            $this->invokeInspectorPrivateMethod($inspector, 'isAliveNonLinux', 999_999_999),
            'A missing ps must fail closed by treating the process as alive',
        );
    }

    public function testMatchesFingerprintNonLinuxReturnsTrueForLiveProcessWithMatchingUid(): void
    {
        $pid = $this->currentPid();
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, 0, \posix_getuid());
        $inspector = $this->inspectorWithPsResult($this->psResult(['S']));

        $this->assertTrue($inspector->matchesFingerprint($pid, $fingerprint));
    }

    public function testMatchesFingerprintNonLinuxReturnsFalseForEmptyPsState(): void
    {
        $pid = $this->currentPid();
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, 0, \posix_getuid());
        $inspector = $this->inspectorWithPsResult($this->psResult([]));

        $this->assertFalse(
            $inspector->matchesFingerprint($pid, $fingerprint),
            'An empty ps state must fail closed (no match)',
        );
    }

    public function testMatchesFingerprintNonLinuxReturnsFalseForZombie(): void
    {
        $pid = $this->currentPid();
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, 0, \posix_getuid());
        $inspector = $this->inspectorWithPsResult($this->psResult(['Z']));

        $this->assertFalse(
            $inspector->matchesFingerprint($pid, $fingerprint),
            'A zombie must fail closed (no match)',
        );
    }

    public function testMatchesFingerprintNonLinuxReturnsFalseForUidMismatch(): void
    {
        $pid = $this->currentPid();
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, 0, \posix_getuid() + 1);
        $inspector = $this->inspectorWithPsResult($this->psResult(['S']));

        $this->assertFalse(
            $inspector->matchesFingerprint($pid, $fingerprint),
            'A UID mismatch must fail closed (no match)',
        );
    }

    public function testMatchesFingerprintNonLinuxTreatsMissingPsAsMatchByDesign(): void
    {
        $pid = $this->currentPid();
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, 0, \posix_getuid());
        $inspector = $this->inspectorWithPsResult(null);

        $this->assertTrue(
            $inspector->matchesFingerprint($pid, $fingerprint),
            'ps unavailable: treated as alive and matched by design so stop still works with exec() disabled',
        );
    }

    public function testMatchesFingerprintNonLinuxTreatsGarbageStateAsMatchByDesign(): void
    {
        $pid = $this->currentPid();
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, 0, \posix_getuid());
        $inspector = $this->inspectorWithPsResult($this->psResult(['garbage']));

        $this->assertTrue(
            $inspector->matchesFingerprint($pid, $fingerprint),
            'Garbage ps state: treated as alive and matched by design (availability over strictness)',
        );
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadProcessStateFromStatReturnsStateForRunningPid(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $state = $this->invokePrivateMethod('readProcessStateFromStat', $pid);

            $this->assertNotNull($state, 'State must be readable for a running PID');
            $this->assertNotSame('Z', $state, 'A running process must not report zombie state');
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadProcessStateFromStatReturnsNullForNonExistentPid(): void
    {
        $state = $this->invokePrivateMethod('readProcessStateFromStat', 999_999_999);

        $this->assertNull($state, 'State must be null for a non-existent PID');
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadProcessStateFromStatReturnsZForZombie(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            posix_kill($pid, SIGKILL);
            Wait::until(fn(): bool => $this->invokePrivateMethod('readProcessStateFromStat', $pid) === 'Z', 2);

            $state = $this->invokePrivateMethod('readProcessStateFromStat', $pid);
            $this->assertSame('Z', $state, 'A zombie must report state Z');
            $this->assertFalse(
                $this->inspector->isProcessAlive($pid),
                'isProcessAlive() must return false for a zombie (state Z from /proc/stat)',
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadLinuxProcessSnapshotReturnsValidSnapshot(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $snapshot = $this->invokePrivateMethod('readLinuxProcessSnapshot', $pid);

            $this->assertInstanceOf(\CrazyGoat\WorkermanBundle\ProcessSnapshot::class, $snapshot);
            $this->assertNotNull($snapshot->state);
            $this->assertNotSame('Z', $snapshot->state);
            $this->assertGreaterThan(0, $snapshot->startTime);
            $this->assertSame(\posix_getuid(), $snapshot->uid);
            $this->assertTrue($snapshot->isAlive());
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testReadLinuxProcessSnapshotReturnsNullForNonExistentPid(): void
    {
        $snapshot = $this->invokePrivateMethod('readLinuxProcessSnapshot', 999_999_999);

        $this->assertNull($snapshot, 'Snapshot must be null for a non-existent PID');
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testMatchesFingerprintFailsClosedForZombieProcess(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        $fingerprint = $this->captureFingerprintForPid($pid);

        try {
            posix_kill($pid, SIGKILL);
            Wait::until(fn(): bool => !$this->inspector->isProcessAlive($pid), 2);

            $this->assertFalse(
                $this->inspector->matchesFingerprint($pid, $fingerprint),
                'matchesFingerprint() must fail closed for a zombie (process died mid-verification)',
            );
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testMatchesFingerprintFailsClosedForMismatchedUid(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $startTime = \CrazyGoat\WorkermanBundle\MasterFingerprint::readStartTimeForPid($pid);

            $wrongUid = \posix_getuid() === 0 ? 1 : \posix_getuid() + 1;
            $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, $startTime, $wrongUid);

            $this->assertFalse(
                $this->inspector->matchesFingerprint($pid, $fingerprint),
                'matchesFingerprint() must fail closed when UID does not match',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    #[RequiresOperatingSystem('Linux')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testMatchesFingerprintFailsClosedForMismatchedStartTime(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork failed');
        }

        if ($pid === 0) {
            for (;;) {
                sleep(1);
            }
        }

        try {
            $wrongStartTime = 1;
            $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint($pid, $wrongStartTime, \posix_getuid());

            $this->assertFalse(
                $this->inspector->matchesFingerprint($pid, $fingerprint),
                'matchesFingerprint() must fail closed when start time does not match',
            );
        } finally {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    public function testSnapshotMatchesFingerprintFailsClosedForUnreadableUidOnAliveProcess(): void
    {
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(1234, 100, \posix_getuid());
        $snapshot = new \CrazyGoat\WorkermanBundle\ProcessSnapshot('S', 100, null);

        $this->assertFalse(
            $this->invokeSnapshotMatchesFingerprint($snapshot, $fingerprint),
            'An alive process with an unreadable UID must fail closed',
        );
    }

    public function testSnapshotMatchesFingerprintFailsClosedForUnreadableStartTimeOnAliveProcess(): void
    {
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(1234, 100, \posix_getuid());
        $snapshot = new \CrazyGoat\WorkermanBundle\ProcessSnapshot('S', 0, \posix_getuid());

        $this->assertFalse(
            $this->invokeSnapshotMatchesFingerprint($snapshot, $fingerprint),
            'An alive process with an unreadable start time must fail closed',
        );
    }

    private function invokeSnapshotMatchesFingerprint(
        \CrazyGoat\WorkermanBundle\ProcessSnapshot $snapshot,
        \CrazyGoat\WorkermanBundle\MasterFingerprint $fingerprint,
    ): bool {
        $reflection = new ReflectionClass($this->inspector);
        $method = $reflection->getMethod('snapshotMatchesFingerprint');

        return $method->invoke($this->inspector, $snapshot, $fingerprint);
    }

    public function testMatchesFingerprintFailsClosedForNonExistentPidSnapshot(): void
    {
        $fingerprint = new \CrazyGoat\WorkermanBundle\MasterFingerprint(999_999_999, 0, \posix_getuid());

        $this->assertFalse(
            $this->inspector->matchesFingerprint(999_999_999, $fingerprint),
            'matchesFingerprint() must fail closed when the snapshot is null (non-existent PID)',
        );
    }

    public function testProcessSnapshotIsAlive(): void
    {
        $this->assertTrue(
            (new \CrazyGoat\WorkermanBundle\ProcessSnapshot('R', 100, 0))->isAlive(),
            'A running process (state R) must be alive',
        );
        $this->assertTrue(
            (new \CrazyGoat\WorkermanBundle\ProcessSnapshot('S', 100, 0))->isAlive(),
            'A sleeping process (state S) must be alive',
        );
        $this->assertFalse(
            (new \CrazyGoat\WorkermanBundle\ProcessSnapshot('Z', 100, 0))->isAlive(),
            'A zombie (state Z) must not be alive',
        );
        $this->assertFalse(
            (new \CrazyGoat\WorkermanBundle\ProcessSnapshot(null, 0, null))->isAlive(),
            'An unreadable snapshot (state null) must not be alive',
        );
    }
}
