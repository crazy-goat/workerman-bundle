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
            '^(fix|feat|refactor|perf|process)/issue-[0-9]+',
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
        $literal = '^(fix|feat|refactor|perf|process)/issue-[0-9]+';

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
        $this->assertStringContainsString("grep -Eq '^(fix|feat|refactor|perf|process)/issue-[0-9]+'", $hook);
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

    /**
     * bin/README.md used to omit `bin/pow-common.php` from the protected-path
     * list at "Allowed types", while the `POW-10` table row, `docs/workflow.md`
     * and `CPOW_PROTECTED_FILES` all included it — one file, three descriptions,
     * only two of them agreeing. `bin/check-pow.php` self-executes on require
     * (it calls `exit()` at the bottom), so its constant is read from the
     * source text rather than by loading the script in-process.
     */
    public function testProtectedFileListsAgreeEverywhere(): void
    {
        $source = file_get_contents($this->projectDir . '/bin/check-pow.php');
        $this->assertNotFalse($source);
        $this->assertMatchesRegularExpression(
            "/const CPOW_PROTECTED_FILES = \\['([^']+)', '([^']+)', '([^']+)'\\];/",
            $source,
        );
        preg_match("/const CPOW_PROTECTED_FILES = \\['([^']+)', '([^']+)', '([^']+)'\\];/", $source, $matches);
        $protectedFiles = [$matches[1] ?? '', $matches[2] ?? '', $matches[3] ?? ''];

        $readme = file_get_contents($this->projectDir . '/bin/README.md');
        $this->assertNotFalse($readme);
        $workflow = file_get_contents($this->projectDir . '/docs/workflow.md');
        $this->assertNotFalse($workflow);

        foreach ($protectedFiles as $file) {
            $this->assertStringContainsString(
                $file,
                $readme,
                'bin/README.md must list every protected file exactly once, including ' . $file,
            );
            $this->assertStringContainsString(
                $file,
                $workflow,
                'docs/workflow.md must list every protected file exactly once, including ' . $file,
            );
        }

        // bin/README.md's "Allowed types" paragraph and its POW-10 table row
        // must independently name every protected file too — that pairing is
        // exactly what drifted before.
        $allowedTypesSection = substr($readme, (int) strpos($readme, 'Allowed types:'), 400);

        foreach ($protectedFiles as $file) {
            $this->assertStringContainsString($file, $allowedTypesSection);
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

    /**
     * `gh-branch` is bash and cannot `require` `pow-common.php`, so its
     * `TYPES=` list is a fourth, hand-maintained copy of the set that
     * `POWC_FULL_PREFIXES`/`POWC_LIGHT_PREFIXES` define — bin/README.md used
     * to claim the hook, the gate and `gh-branch` "cannot drift apart" for
     * exactly this reason, which was false for `gh-branch`. This cannot make
     * them share one source, but it does make a drift fail a test instead of
     * being noticed months later.
     */
    public function testGhBranchTypesMatchThePowCommonPrefixSets(): void
    {
        require_once $this->projectDir . '/bin/pow-common.php';

        $script = file_get_contents($this->projectDir . '/bin/gh-branch');
        $this->assertNotFalse($script);
        $this->assertSame(1, preg_match('/^TYPES="([^"]+)"$/m', $script, $matches));
        $ghBranchTypes = explode(' ', $matches[1] ?? '');

        $expected = [...(array) constant('POWC_FULL_PREFIXES'), ...(array) constant('POWC_LIGHT_PREFIXES')];
        sort($expected);
        sort($ghBranchTypes);

        $this->assertSame(
            $expected,
            $ghBranchTypes,
            'gh-branch\'s TYPES must be exactly POWC_FULL_PREFIXES union POWC_LIGHT_PREFIXES',
        );
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
