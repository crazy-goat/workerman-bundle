<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Tests for SIGCHLD signal handling in SchedulerWorker.
 * 
 * Issue #41: Ignored SIGCHLD Prevents Crash Detection
 * 
 * Previously, SIGCHLD was set to SIG_IGN which prevented detecting
 * child process exit codes. This test verifies the fix is in place.
 */
final class SchedulerWorkerSigchldTest extends TestCase
{
    /**
     * Test that the SIGCHLD handler code pattern exists in SchedulerWorker.
     * This verifies the implementation of issue #41 fix.
     */
    public function testSigchldHandlerIsImplemented(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Worker/SchedulerWorker.php';
        $this->assertFileExists($sourceFile, 'SchedulerWorker.php should exist');
        
        $content = file_get_contents($sourceFile);
        
        // Verify that SIG_IGN is NOT used (this was the bug)
        $this->assertStringNotContainsString(
            'pcntl_signal(SIGCHLD, SIG_IGN)',
            $content,
            'SIGCHLD should not be ignored (SIG_IGN) - this prevents crash detection'
        );
        
        // Verify that a proper callback handler is used instead
        $this->assertMatchesRegularExpression(
            '/pcntl_signal\s*\(\s*SIGCHLD\s*,\s*(?:function|fn|\\Closure)/',
            $content,
            'SIGCHLD should have a proper callback handler'
        );
        
        // Verify that pcntl_waitpid is used (for reaping children)
        $this->assertStringContainsString(
            'pcntl_waitpid',
            $content,
            'pcntl_waitpid should be used to reap child processes'
        );
        
        // Verify that WNOHANG flag is used (to prevent blocking)
        $this->assertStringContainsString(
            'WNOHANG',
            $content,
            'WNOHANG flag should be used to prevent blocking in signal handler'
        );
        
        // Verify that exit codes are checked
        $this->assertStringContainsString(
            'pcntl_wexitstatus',
            $content,
            'pcntl_wexitstatus should be used to get exit codes'
        );
        
        // Verify that non-zero exit codes are logged as warnings
        $this->assertStringContainsString(
            "'warning'",
            $content,
            'Non-zero exit codes should be logged with warning level'
        );
        
        // Verify the log message format includes exit code
        $this->assertStringContainsString(
            'exited with code',
            $content,
            'Log message should include exit code information'
        );
    }
    
    /**
     * Test that the handler uses a while loop to reap all children.
     * This is important when multiple children terminate simultaneously.
     */
    public function testHandlerReapsAllChildrenInLoop(): void
    {
        $sourceFile = dirname(__DIR__) . '/src/Worker/SchedulerWorker.php';
        $content = file_get_contents($sourceFile);
        
        // Extract the SIGCHLD handler function
        preg_match(
            '/pcntl_signal\s*\(\s*SIGCHLD\s*,\s*(?:function|fn)\s*\([^)]*\)(?:\s*:\s*void)?\s*\{([^}]+)\}/s',
            $content,
            $matches
        );
        
        $this->assertNotEmpty($matches, 'Should find SIGCHLD handler function');
        
        $handlerBody = $matches[1];
        
        // Verify while loop is used (not just if)
        $this->assertMatchesRegularExpression(
            '/while\s*\(\s*\(\$pid\s*=\s*pcntl_waitpid/',
            $handlerBody,
            'Handler should use while loop to reap all terminated children'
        );
    }
}
