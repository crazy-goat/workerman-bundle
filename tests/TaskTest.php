<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskTest extends KernelTestCase
{
    public function testTaskIsRunning(): void
    {
        $content = $this->getTaskStatusFileContent() ?? $this->fail('Task status file is not found');

        $this->assertTrue((int) $content > time() - 4, 'Task was called more than 4 seconds ago');
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

    private function getTaskStatusFileContent(): string|null
    {
        $i = 0;
        do {
            if (($content = @file_get_contents(dirname(__DIR__) . '/var/task_status.log')) !== false) {
                return $content;
            }
            usleep(200000);
        } while (++$i < 10);
        return null;
    }
}
