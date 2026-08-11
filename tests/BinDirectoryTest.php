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
