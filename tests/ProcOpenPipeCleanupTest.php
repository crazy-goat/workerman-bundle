<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for proc_open pipe cleanup in bootstrap.php and WorkermanCommandTest.php.
 *
 * Issue #170: tests/App/bootstrap.php: proc_open pipes not closed - file descriptor leak.
 *
 * Structural tests verify the source code contains the expected pipe cleanup
 * pattern as a regression safety net.
 */
final class ProcOpenPipeCleanupTest extends TestCase
{
    public function testBootstrapClosesProcOpenPipes(): void
    {
        $sourceFile = dirname(__DIR__) . '/tests/App/bootstrap.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            '\fclose($pipe)',
            $content,
            'bootstrap.php must close proc_open pipes to prevent file descriptor leak',
        );

        $this->assertStringContainsString(
            '\proc_close($process)',
            $content,
            'bootstrap.php must close the process handle after proc_open',
        );

        $this->assertStringContainsString(
            '\is_resource($process)',
            $content,
            'bootstrap.php must check proc_open result before using pipes',
        );
    }

    public function testWorkermanCommandClosesProcOpenPipes(): void
    {
        $sourceFile = dirname(__DIR__) . '/tests/WorkermanCommandTest.php';
        $this->assertFileExists($sourceFile);

        $content = file_get_contents($sourceFile);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            '\fclose($pipe)',
            $content,
            'WorkermanCommandTest must close proc_open pipes to prevent file descriptor leak',
        );

        $this->assertStringContainsString(
            '\proc_close($process)',
            $content,
            'WorkermanCommandTest must close the process handle after proc_open',
        );

        $this->assertStringContainsString(
            '\is_resource($process)',
            $content,
            'WorkermanCommandTest must check proc_open result before using pipes',
        );
    }
}
