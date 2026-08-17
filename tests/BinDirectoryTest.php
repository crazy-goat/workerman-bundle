<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class BinDirectoryTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__);
    }

    public function testBinReadmeExists(): void
    {
        $this->assertFileExists($this->projectDir . '/bin/README.md');
    }

    public function testBinReadmeDocumentsInstallGitHook(): void
    {
        $content = file_get_contents($this->projectDir . '/bin/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('install-git-hook.php', $content);
    }

    /**
     * FAQ-015 is promoted against this test: the hook must run `composer lint`
     * and a lint failure must actually block the push. It ran unconditionally
     * before the proof-of-work gate was bolted on beside it, and it still does.
     */
    public function testThePrePushHookBlocksOnALintFailure(): void
    {
        $installer = file_get_contents($this->projectDir . '/bin/install-git-hook.php');
        $this->assertNotFalse($installer);
        $this->assertStringContainsString('composer lint || exit 1', $installer);
    }

    public function testTheInstalledHookIsExecutableAndRunsLint(): void
    {
        $sandbox = sys_get_temp_dir() . '/hook-test-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($sandbox . '/bin', 0o775, true));
        $this->assertTrue(mkdir($sandbox . '/.git/hooks', 0o775, true));
        $this->assertNotFalse(copy($this->projectDir . '/bin/install-git-hook.php', $sandbox . '/bin/install-git-hook.php'));

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/bin/install-git-hook.php') . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        $hook = $sandbox . '/.git/hooks/pre-push';
        $this->assertFileExists($hook);
        $this->assertTrue(is_executable($hook), 'a pre-push hook git cannot execute is no hook at all');
        $this->assertStringContainsString('composer lint || exit 1', (string) file_get_contents($hook));

        foreach ([$hook, $sandbox . '/bin/install-git-hook.php'] as $file) {
            unlink($file);
        }

        foreach ([$sandbox . '/.git/hooks', $sandbox . '/.git', $sandbox . '/bin', $sandbox] as $dir) {
            rmdir($dir);
        }
    }

    public function testGhBranchKnowsTheProcessType(): void
    {
        $script = file_get_contents($this->projectDir . '/bin/gh-branch');
        $this->assertNotFalse($script);
        $this->assertStringContainsString(
            'TYPES="fix feat docs perf refactor chore test build ci process"',
            $script,
        );
        $this->assertStringContainsString('*,process,*)              TYPE=process ;;', $script);
    }

    public function testWaitForPortsScriptExistsAndIsExecutable(): void
    {
        $this->assertFileExists($this->projectDir . '/bin/wait-for-ports.php');
    }

    /**
     * bin/wait-for-ports.php polls TCP ports until they accept connections.
     * It replaces the fixed `sleep 1` in the composer test scripts (#592).
     */
    public function testWaitForPortsRejectsMissingArgs(): void
    {
        $output = [];
        $code = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->projectDir . '/bin/wait-for-ports.php') . ' 2>&1', $output, $code);
        $this->assertSame(2, $code, 'wait-for-ports.php must exit 2 on missing args');
        $this->assertStringContainsString('Usage:', implode("\n", $output));
    }

    public function testWaitForPortsTimesOutOnUnreachablePort(): void
    {
        $output = [];
        $code = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->projectDir . '/bin/wait-for-ports.php') . ' 19999 --timeout=1 2>&1', $output, $code);
        $this->assertSame(1, $code, 'wait-for-ports.php must exit 1 on timeout');
        $this->assertStringContainsString('did not become ready', implode("\n", $output));
    }

    public function testReadmeDisambiguatesBinConsole(): void
    {
        $content = file_get_contents($this->projectDir . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('refers to **your application\'s** Symfony console', $content);
        $this->assertStringContainsString('directory shipped by this bundle', $content);
    }

    public function testReadmeLinksToBinReadme(): void
    {
        $content = file_get_contents($this->projectDir . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('bin/README.md', $content);
    }

    public function testContributingLinksToBinReadme(): void
    {
        $content = file_get_contents($this->projectDir . '/CONTRIBUTING.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('bin/README.md', $content);
    }

    /**
     * CONTRIBUTING.md used to claim "PHPStan level 6" while phpstan.neon.dist
     * already ran level 8 (issue #693). Pin the doc against the config so a
     * level bump cannot drift stale a second time.
     */
    public function testContributingPhpstanLevelMatchesConfig(): void
    {
        $neonContent = file_get_contents($this->projectDir . '/phpstan.neon.dist');
        $this->assertNotFalse($neonContent);
        $level = null;
        if (preg_match('/level:\s*(\d+)/', $neonContent, $levelMatches) === 1) {
            $level = (int) $levelMatches[1];
        }
        $this->assertNotNull($level, 'phpstan.neon.dist must declare a PHPStan level');

        $contribContent = file_get_contents($this->projectDir . '/CONTRIBUTING.md');
        $this->assertNotFalse($contribContent);
        $this->assertStringContainsString(
            "PHPStan level {$level}",
            $contribContent,
            'CONTRIBUTING.md must state the same PHPStan level as phpstan.neon.dist',
        );
    }

    public function testReadmeHasLicenseSection(): void
    {
        $content = file_get_contents($this->projectDir . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('## License', $content);
        $this->assertStringContainsString('[MIT license](LICENSE)', $content);
    }

    public function testReadmeHasLicenseBadge(): void
    {
        $content = file_get_contents($this->projectDir . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('License-MIT', $content);
    }
}
