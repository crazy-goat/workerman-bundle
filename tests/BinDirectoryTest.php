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
        $this->assertNotSame(
            [],
            array_filter(
                $lint,
                static fn(mixed $step): bool => \is_string($step) && str_starts_with($step, 'php bin/check-pow.php'),
            ),
            'composer lint must run the advisory gate',
        );

        $checkPow = $composer['scripts']['check-pow'] ?? null;
        $this->assertIsArray($checkPow);
        $this->assertContains('php bin/check-pow.php', $checkPow);
    }

    public function testPrePushHookRunsTheGateButBlocksOnlyOnIssueBranches(): void
    {
        $installer = file_get_contents($this->projectDir . '/bin/install-git-hook.php');
        $this->assertNotFalse($installer);
        $this->assertStringContainsString(
            'composer lint || exit 1',
            $installer,
            'FAQ-015 is promoted against this test: the hook must run composer lint and a failure must block the push',
        );
        $this->assertStringContainsString('php bin/check-pow.php', $installer);
        $this->assertSame(
            '^(fix|feat|process)/issue-[0-9]+',
            $this->prePushIssueBranchPattern($installer),
            'the hook must block only on an issue branch — a hook that blocks every push gets bypassed',
        );
    }

    /**
     * The branch pattern the generated hook greps for, whether the template
     * spells it out or interpolates the shared one from `bin/pow-common.php`.
     */
    private function prePushIssueBranchPattern(string $installer): string
    {
        $literal = '^(fix|feat|process)/issue-[0-9]+';

        if (str_contains($installer, "'" . $literal . "'")) {
            return $literal;
        }

        $this->assertStringContainsString(
            'powcIssueBranchEre()',
            $installer,
            'the hook must derive its branch pattern from the shared one, or spell it out',
        );

        require_once $this->projectDir . '/bin/pow-common.php';
        $this->assertTrue(\function_exists('powcIssueBranchEre'));

        return powcIssueBranchEre();
    }

    public function testComposerLintRunsTheGateInReportOnlyMode(): void
    {
        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true);
        $this->assertIsArray($composer);

        $lint = $composer['scripts']['lint'] ?? null;
        $this->assertIsArray($lint);
        // Composer aborts an array script on the first non-zero command, so a
        // gate that can fail inside `lint` blocks every push on every branch —
        // the `--no-verify` failure mode the design exists to avoid (DEC-008).
        $this->assertContains('php bin/check-pow.php --advisory', $lint);
        $this->assertNotContains('php bin/check-pow.php', $lint, 'the failing form must not run inside lint');
    }

    public function testTheInstalledHookGatesTheBlockingRunOnTheBranch(): void
    {
        $sandbox = sys_get_temp_dir() . '/hook-test-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($sandbox . '/bin', 0o775, true));
        $this->assertTrue(mkdir($sandbox . '/.git/hooks', 0o775, true));
        $this->assertNotFalse(copy($this->projectDir . '/bin/install-git-hook.php', $sandbox . '/bin/install-git-hook.php'));
        $this->assertNotFalse(copy($this->projectDir . '/bin/pow-common.php', $sandbox . '/bin/pow-common.php'));

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/bin/install-git-hook.php') . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        $hook = file_get_contents($sandbox . '/.git/hooks/pre-push');
        $this->assertIsString($hook);
        $this->assertStringContainsString("grep -Eq '^(fix|feat|process)/issue-[0-9]+'", $hook);
        // The blocking run must sit INSIDE the branch condition. When it sat
        // outside, `composer lint || exit 1` had already blocked the push on
        // every branch before the condition was ever reached.
        $this->assertMatchesRegularExpression(
            '/if echo "\$branch" \| grep -Eq [^\n]+\n\s+if ! php bin\/check-pow\.php; then/',
            $hook,
        );

        foreach ([$sandbox . '/.git/hooks/pre-push', $sandbox . '/bin/install-git-hook.php', $sandbox . '/bin/pow-common.php'] as $file) {
            unlink($file);
        }

        foreach ([$sandbox . '/.git/hooks', $sandbox . '/.git', $sandbox . '/bin', $sandbox] as $dir) {
            rmdir($dir);
        }
    }

    public function testPowCommonIsTheSingleSourceForSharedRules(): void
    {
        $this->assertFileExists($this->projectDir . '/bin/pow-common.php');

        $readme = file_get_contents($this->projectDir . '/bin/README.md');
        $this->assertNotFalse($readme);
        $this->assertStringContainsString('### `pow-common.php`', $readme);
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
