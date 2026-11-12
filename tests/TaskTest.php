<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Util\Wait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskTest extends KernelTestCase
{
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

    public function testFileLockPreventsConcurrentAccess(): void
    {
        $pidFile = sys_get_temp_dir() . '/workerman_test_' . uniqid() . '.pid';

        try {
            $fp1 = fopen($pidFile, 'c');
            $this->assertNotFalse($fp1, 'Failed to open PID file for first handle');

            $this->assertTrue(
                flock($fp1, LOCK_EX | LOCK_NB),
                'First process should acquire lock successfully',
            );

            $fp2 = fopen($pidFile, 'c');
            $this->assertNotFalse($fp2, 'Failed to open PID file for second handle');

            $this->assertFalse(
                flock($fp2, LOCK_EX | LOCK_NB),
                'Second process should fail to acquire lock while first holds it',
            );

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
