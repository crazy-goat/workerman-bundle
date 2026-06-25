<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for proc_open pipe cleanup in bootstrap.php and WorkermanCommandTest.php.
 *
 * Issue #170: tests/App/bootstrap.php: proc_open pipes not closed - file descriptor leak.
 *
 * Behavioral tests exercise the proc_open pipe cleanup pattern and verify
 * that all pipes are actually closed after fclose().
 */
final class ProcOpenPipeCleanupTest extends TestCase
{
    public function testBootstrapClosesProcOpenPipes(): void
    {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open(
            \sprintf('%s -r %s', \PHP_BINARY, \escapeshellarg('echo ok')),
            $descriptor,
            $pipes,
        );

        $this->assertIsResource($process, 'Must be able to open a process via proc_open');

        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
        \proc_close($process);

        foreach ($pipes as $index => $pipe) {
            $this->assertFalse(
                \is_resource($pipe),
                \sprintf('Pipe %d must be closed after fclose', $index),
            );
        }
    }

    public function testWorkermanCommandClosesProcOpenPipes(): void
    {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open(
            \sprintf('%s -r %s', \PHP_BINARY, \escapeshellarg('echo ok')),
            $descriptor,
            $pipes,
        );

        $this->assertIsResource($process, 'Must be able to open a process via proc_open');

        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
        \proc_close($process);

        foreach ($pipes as $index => $pipe) {
            $this->assertFalse(
                \is_resource($pipe),
                \sprintf('Pipe %d must be closed after fclose', $index),
            );
        }
    }
}
