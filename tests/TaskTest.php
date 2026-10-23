<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Util\Wait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskTest extends KernelTestCase
{
    /**
     * Timeout for waiting on a fresh scheduler timestamp, in seconds.
     *
     * The test task runs every second, so a few seconds is normally enough;
     * the extra headroom keeps the test green on slow or loaded CI runners
     * where a single read against a fixed window flakes (#1081).
     */
    private const FRESH_TIMESTAMP_TIMEOUT_SECONDS = 10;

    public function testTaskIsRunning(): void
    {
        $path = dirname(__DIR__) . '/var/task_status.log';

        $found = Wait::until(
            static fn(): bool => ($content = @file_get_contents($path)) !== false
                && (int) $content > time() - 4,
            self::FRESH_TIMESTAMP_TIMEOUT_SECONDS,
        );

        $this->assertTrue($found, 'No fresh task status timestamp appeared within 10 seconds');
    }

    /**
     * Test that flock-based locking prevents concurrent file access.
     * This verifies the core mechanism used by SchedulerWorker to prevent
     * double task execution.
     */
    public function testFileLockPreventsConcurrentAccess(): void
    {
        $pidFile = sys_get_temp_dir() . '/workerman_test_' . uniqid() . '.pid';

        try {
            $fp1 = fopen($pidFile, 'c');
            $this->assertNotFalse($fp1, 'Failed to open PID file for first handle');

            // First lock should succeed (non-blocking)
            $this->assertTrue(
                flock($fp1, LOCK_EX | LOCK_NB),
                'First process should acquire lock successfully',
            );

            $fp2 = fopen($pidFile, 'c');
            $this->assertNotFalse($fp2, 'Failed to open PID file for second handle');

            // Second lock should fail - another process holds the lock
            $this->assertFalse(
                flock($fp2, LOCK_EX | LOCK_NB),
                'Second process should fail to acquire lock while first holds it',
            );

            // After first releases lock, second should succeed
            flock($fp1, LOCK_UN);
            fclose($fp1);

            $this->assertTrue(
                flock($fp2, LOCK_EX | LOCK_NB),
                'Second process should acquire lock after first releases it',
            );

            flock($fp2, LOCK_UN);
            fclose($fp2);
        } finally {
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
        }
    }
}
