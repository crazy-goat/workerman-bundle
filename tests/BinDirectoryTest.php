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

    public function testBinReadmeDocumentsPow(): void
    {
        $content = file_get_contents($this->projectDir . '/bin/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('### `pow.php`', $content);
        $this->assertStringContainsString('proof of work', $content);
        $this->assertStringContainsString('0 = ok, 1 = runtime/validation error, 2 = usage error', $content);
    }

    public function testBinReadmeDocumentsCheckPow(): void
    {
        $content = file_get_contents($this->projectDir . '/bin/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('### `check-pow.php`', $content);
        $this->assertStringContainsString('0 = pass or skip, 1 = gate violation, 2 = usage error', $content);
        $this->assertStringContainsString('no-pow', $content, 'the escape hatch must be documented');
        $this->assertStringContainsString(
            'git show origin/master:bin/check-pow.php',
            $content,
            'the master-copy invocation must be documented — it is what stops a PR weakening its own gate',
        );
    }

    public function testCheckPowScriptExists(): void
    {
        $this->assertFileExists($this->projectDir . '/bin/check-pow.php');
    }

    public function testComposerLintRunsTheProofOfWorkGate(): void
    {
        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true);
        $this->assertIsArray($composer);

        $lint = $composer['scripts']['lint'] ?? null;
        $this->assertIsArray($lint);
        $this->assertContains('php bin/check-pow.php', $lint, 'composer lint must run the advisory gate');

        $checkPow = $composer['scripts']['check-pow'] ?? null;
        $this->assertIsArray($checkPow);
        $this->assertContains('php bin/check-pow.php', $checkPow);
    }

    public function testPrePushHookRunsTheGateButBlocksOnlyOnIssueBranches(): void
    {
        $installer = file_get_contents($this->projectDir . '/bin/install-git-hook.php');
        $this->assertNotFalse($installer);
        $this->assertStringContainsString('php bin/check-pow.php', $installer);
        $this->assertStringContainsString(
            "'^(fix|feat|process)/issue-[0-9]+'",
            $installer,
            'the hook must block only on an issue branch — a hook that blocks every push gets bypassed',
        );
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
