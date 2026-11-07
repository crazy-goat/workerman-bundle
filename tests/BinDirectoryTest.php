<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class BinDirectoryTest extends TestCase
{
    /** Ignore the global and system git config and the git env of the caller: the test must not touch the developer's hooks. */
    private const CLEAN_GIT_ENV = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_COMMON_DIR GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1 ';

    private string $projectDir;

    /** @var list<string> */
    private array $sandboxes = [];

    protected function tearDown(): void
    {
        foreach ($this->sandboxes as $sandbox) {
            exec('rm -rf ' . escapeshellarg($sandbox) . ' ' . escapeshellarg($sandbox . '-wt') . ' ' . escapeshellarg($sandbox . '-other') . ' ' . escapeshellarg($sandbox . '-out'));
        }

        $this->sandboxes = [];
    }

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
     * (which calls `bin/lint.sh`) and a lint failure must actually block the push.
     */
    public function testThePrePushHookBlocksOnALintFailure(): void
    {
        $installer = file_get_contents($this->projectDir . '/bin/install-git-hook.php');
        $this->assertNotFalse($installer);
        $this->assertStringContainsString('LINT_SKIP_AUDIT=1 composer lint || exit 1', $installer);
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
        $this->assertStringContainsString('LINT_SKIP_AUDIT=1 composer lint || exit 1', (string) file_get_contents($hook));

        foreach ([$hook, $sandbox . '/bin/install-git-hook.php'] as $file) {
            unlink($file);
        }

        foreach ([$sandbox . '/.git/hooks', $sandbox . '/.git', $sandbox . '/bin', $sandbox] as $dir) {
            rmdir($dir);
        }
    }

    public function testTheInstallerFailsLoudlyWhenChmodFails(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can always chmod');
        }

        if (!is_writable('/dev/null')) {
            $this->markTestSkipped('needs a writable /dev/null that the user does not own');
        }

        $sandbox = $this->makeSandbox();
        // The write goes through the link and works, but the user does not own /dev/null, so chmod() fails.
        $this->assertTrue(symlink('/dev/null', $sandbox . '/.git/hooks/pre-push'));

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/bin/install-git-hook.php') . ' 2>&1', $output, $code);

        $this->assertSame(1, $code, implode("\n", $output));
        $this->assertStringNotContainsString('installed successfully', implode("\n", $output));
        $this->assertStringContainsString('chmod', implode("\n", $output));

    }

    public function testTheInstallerWorksInALinkedWorktreeAndWithCoreHooksPath(): void
    {
        $sandbox = $this->makeSandbox();
        $git = self::CLEAN_GIT_ENV . 'git -C ' . escapeshellarg($sandbox) . ' ';
        exec($git . 'init -q 2>&1 && ' . $git . '-c user.name=t -c user.email=t@example.com commit -q --allow-empty -m init 2>&1 && ' . $git . 'worktree add -q ' . escapeshellarg($sandbox . '-wt') . ' 2>&1', $gitOutput, $gitCode);
        $this->assertSame(0, $gitCode, implode("\n", $gitOutput));

        // A linked worktree: `.git` is a file there.
        mkdir($sandbox . '-wt/bin', 0o775, true);
        copy($sandbox . '/bin/install-git-hook.php', $sandbox . '-wt/bin/install-git-hook.php');
        exec(self::CLEAN_GIT_ENV . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '-wt/bin/install-git-hook.php') . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertFileExists($sandbox . '/.git/hooks/pre-push');
        $this->assertTrue(is_executable($sandbox . '/.git/hooks/pre-push'));
        unlink($sandbox . '/.git/hooks/pre-push');

        // core.hooksPath: the hook goes there, not into .git/hooks.
        $hooksPath = $sandbox . '/custom-hooks';
        mkdir($hooksPath);
        exec($git . 'config core.hooksPath ' . escapeshellarg($hooksPath) . ' 2>&1');
        $output = [];
        exec(self::CLEAN_GIT_ENV . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/bin/install-git-hook.php') . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertTrue(is_executable($hooksPath . '/pre-push'));
        $this->assertFileDoesNotExist($sandbox . '/.git/hooks/pre-push');

    }

    private function makeSandbox(): string
    {
        $sandbox = sys_get_temp_dir() . '/hook-test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->assertTrue(mkdir($sandbox . '/bin', 0o775, true));
        $this->assertTrue(mkdir($sandbox . '/.git/hooks', 0o775, true));
        $this->assertNotFalse(copy($this->projectDir . '/bin/install-git-hook.php', $sandbox . '/bin/install-git-hook.php'));

        return $sandbox;
    }

    public function testWaitForPortsScriptExists(): void
    {
        $this->assertFileExists($this->projectDir . '/bin/wait-for-ports.php');
    }

    public function testWaitForTestPortsScriptExists(): void
    {
        $this->assertFileExists($this->projectDir . '/bin/wait-for-test-ports.php');
    }

    public function testWaitForTestPortsReportsAnInvalidPortOverride(): void
    {
        $output = [];
        $code = null;
        exec(
            'env WMB_PORT_HTTP=abc ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->projectDir . '/bin/wait-for-test-ports.php') . ' 2>&1',
            $output,
            $code,
        );

        $this->assertNotSame(0, $code, 'invalid WMB_PORT_* must fail the readiness check');
        $this->assertStringContainsString(
            'WMB_PORT_HTTP must be a TCP port number between 1 and 65535',
            implode("\n", $output),
        );
    }

    public function testWaitForPortsConnectsToTheConfiguredHost(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.2:0', $errorCode, $errorMessage);
        if ($server === false) {
            $this->markTestSkipped(sprintf('127.0.0.2 is not available: %s (%d)', $errorMessage, $errorCode));
        }

        try {
            $name = stream_socket_get_name($server, false);
            $this->assertIsString($name);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);

            $output = [];
            $code = null;
            exec(
                escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($this->projectDir . '/bin/wait-for-ports.php') . ' '
                . escapeshellarg((string) $port) . ' --host=127.0.0.2 --timeout=1 2>&1',
                $output,
                $code,
            );

            $this->assertSame(0, $code, implode("\n", $output));
        } finally {
            fclose($server);
        }
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
        $port = $this->unusedPort();
        $output = [];
        $code = null;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->projectDir . '/bin/wait-for-ports.php') . ' ' . escapeshellarg((string) $port) . ' --timeout=1 2>&1', $output, $code);
        $this->assertSame(1, $code, 'wait-for-ports.php must exit 1 on timeout');
        $this->assertStringContainsString('did not become ready', implode("\n", $output));
    }

    /**
     * A port the OS just handed out and that is not one of the test daemon
     * ports: hard-coding 19999 broke the suite when WMB_PORT_* used it.
     */
    private function unusedPort(): int
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            if ($socket === false) {
                self::fail(sprintf('Cannot allocate a test port: %s (%d)', $errorMessage, $errorCode));
            }
            $name = stream_socket_get_name($socket, false);
            fclose($socket);
            if ($name === false) {
                continue;
            }

            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
            if (!in_array($port, TestPorts::all(), true)) {
                return $port;
            }
        }

        self::fail('Cannot allocate a port outside the configured test ports');
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

    /**
     * CONTRIBUTING.md states the coverage threshold in prose while
     * composer.json's `coverage:check` is the single source of truth. Pin the
     * prose to the config so a threshold change cannot leave the doc stale,
     * the same drift class as the PHPStan level in #693.
     *
     * Every line naming the threshold is checked, so drift in any one
     * occurrence fails. The counter only requires at least one mention: a
     * deliberate consolidation of the prose must stay green, while an emptied
     * or reworded-away mention must not pass vacuously.
     */
    public function testContributingCoverageThresholdMatchesComposer(): void
    {
        $composerContent = file_get_contents($this->projectDir . '/composer.json');
        $this->assertNotFalse($composerContent);
        $composer = json_decode($composerContent, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);

        $scripts = $composer['scripts']['coverage:check'] ?? null;
        $this->assertIsArray($scripts, 'composer.json must define a coverage:check script');

        $threshold = null;
        foreach ($scripts as $script) {
            $this->assertIsString($script);
            if (preg_match('/check-coverage\.php\s+\S+\s+(\d+(?:\.\d+)?)/', $script, $matches) === 1) {
                $threshold = (float) $matches[1];
            }
        }
        $this->assertNotNull($threshold, 'coverage:check must pass an explicit threshold to check-coverage.php');

        // Prose form: integral values drop the trailing ".0" (80.0 -> "80"),
        // non-integral values keep one decimal (85.5 -> "85.5").
        $display = $threshold === floor($threshold)
            ? (string) (int) $threshold
            : number_format($threshold, 1, '.', '');

        $contribContent = file_get_contents($this->projectDir . '/CONTRIBUTING.md');
        $this->assertNotFalse($contribContent);

        $matchedLines = 0;
        foreach (preg_split('/\R/', $contribContent) ?: [] as $line) {
            if (str_contains($line, 'line-coverage threshold')) {
                ++$matchedLines;
                $this->assertStringContainsString(
                    $display . '%',
                    $line,
                    'CONTRIBUTING.md must state the same coverage threshold as composer.json coverage:check',
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $matchedLines,
            'CONTRIBUTING.md must mention the "line-coverage threshold" phrase at least once',
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

    public function testNoTrackedDocMentionsTheRemovedProcessTooling(): void
    {
        $files = [];
        exec('git -C ' . escapeshellarg($this->projectDir) . ' ls-files -- ' . escapeshellarg('*.md'), $files, $code);
        $this->assertSame(0, $code, 'git ls-files must succeed');

        foreach ($files as $file) {
            if ($file === 'CHANGELOG.md') {
                continue; // history legitimately names removed tools
            }

            $content = (string) file_get_contents($this->projectDir . '/' . $file);
            foreach (['proof_of_work', 'pick-issue.php', 'gh-branch', 'findings-coder.md', 'findings-review.md', 'process-changelog', 'process-notices'] as $removed) {
                $this->assertStringNotContainsString($removed, $content, $file . ' still mentions the removed ' . $removed);
            }
        }
    }

    /**
     * A linked worktree's `.git` is a file pointing at the main checkout's
     * `.git/worktrees/<name>`. The container mounts only the worktree, so the
     * helper must mount that common dir read-only at the same absolute path
     * (including paths with spaces) or `git ls-files` fails inside (#867).
     */
    public function testDockerTestWorktreeMountsTheGitCommonDirOfALinkedWorktree(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);

        $worktree = $sandbox . '-wt';
        $git = self::CLEAN_GIT_ENV . 'git -C ' . escapeshellarg($sandbox) . ' ';
        exec($git . 'worktree add -q --detach ' . escapeshellarg($worktree) . ' 2>&1', $gitOutput, $gitCode);
        $this->assertSame(0, $gitCode, implode("\n", $gitOutput));

        $commonDir = realpath($sandbox . '/.git');
        $this->assertNotFalse($commonDir);

        $binDir = $sandbox . '/fake-bin';
        $argsLog = $sandbox . '/docker-args.log';
        $stateLog = $sandbox . '/docker-state.log';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($worktree, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $argsLog,
            'FAKE_DOCKER_STATE_LOG' => $stateLog,
            'FAKE_DOCKER_WORKTREE' => $worktree,
            'FAKE_DOCKER_WRITE_CACHE' => '0',
        ]);

        $this->assertSame(0, $code, $output);
        $args = file($argsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($args, 'fake docker must record its arguments');
        $this->assertContains($commonDir . ':' . $commonDir . ':ro', $args, 'the common .git dir must be mounted read-only');
        $this->assertContains($worktree . ':/app', $args);
    }

    /**
     * The common-dir probe must not inherit the caller's git environment. An
     * exported GIT_DIR/GIT_COMMON_DIR pointing at another repository would
     * otherwise make the helper mount that repo (or nothing) instead of the
     * worktree's real common dir (#867).
     */
    public function testDockerTestWorktreeIgnoresTheCallersGitEnvironment(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);

        $worktree = $sandbox . '-wt';
        $git = self::CLEAN_GIT_ENV . 'git -C ' . escapeshellarg($sandbox) . ' ';
        exec($git . 'worktree add -q --detach ' . escapeshellarg($worktree) . ' 2>&1', $gitOutput, $gitCode);
        $this->assertSame(0, $gitCode, implode("\n", $gitOutput));

        $other = $sandbox . '-other';
        $this->initGitRepository($other);
        $otherGitDir = realpath($other . '/.git');
        $this->assertNotFalse($otherGitDir);

        $commonDir = realpath($sandbox . '/.git');
        $this->assertNotFalse($commonDir);

        $binDir = $sandbox . '/fake-bin';
        $argsLog = $sandbox . '/docker-args.log';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($worktree, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $argsLog,
            'FAKE_DOCKER_STATE_LOG' => $sandbox . '/docker-state.log',
            'FAKE_DOCKER_WORKTREE' => $worktree,
            'FAKE_DOCKER_WRITE_CACHE' => '0',
            'GIT_DIR' => $otherGitDir,
            'GIT_WORK_TREE' => $other,
            'GIT_COMMON_DIR' => $otherGitDir,
        ]);

        $this->assertSame(0, $code, $output);
        $args = file($argsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($args);
        $this->assertContains($commonDir . ':' . $commonDir . ':ro', $args, 'the real common .git dir must be mounted despite the caller git env');
        $this->assertNotContains($otherGitDir . ':' . $otherGitDir . ':ro', $args, 'the caller GIT_DIR must not be mounted');
    }

    /**
     * `git rev-parse --git-common-dir` returns a relative `.git` in the main
     * checkout. It is inside the worktree bind mount already, so mounting it
     * again would only shadow it.
     */
    public function testDockerTestWorktreeDoesNotMountAGitDirInsideTheWorktree(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);

        $commonDir = realpath($sandbox . '/.git');
        $this->assertNotFalse($commonDir);

        $binDir = $sandbox . '/fake-bin';
        $argsLog = $sandbox . '/docker-args.log';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($sandbox, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $argsLog,
            'FAKE_DOCKER_STATE_LOG' => $sandbox . '/docker-state.log',
            'FAKE_DOCKER_WORKTREE' => $sandbox,
            'FAKE_DOCKER_WRITE_CACHE' => '0',
        ]);

        $this->assertSame(0, $code, $output);
        $args = file($argsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($args);
        $this->assertNotContains($commonDir . ':' . $commonDir . ':ro', $args);
    }

    /**
     * The cache file stores absolute paths. Every env has one, and a cache
     * written by the container must not survive the run (#867).
     */
    public function testDockerTestWorktreeCleansEveryEnvironmentCacheAroundTheRun(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);
        $this->writeWorkermanCaches($sandbox);

        $binDir = $sandbox . '/fake-bin';
        $argsLog = $sandbox . '/docker-args.log';
        $stateLog = $sandbox . '/docker-state.log';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($sandbox, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $argsLog,
            'FAKE_DOCKER_STATE_LOG' => $stateLog,
            'FAKE_DOCKER_WORKTREE' => $sandbox,
            'FAKE_DOCKER_WRITE_CACHE' => '1',
        ]);

        $this->assertSame(0, $code, $output);
        $state = (string) file_get_contents($stateLog);
        foreach (['dev', 'test', 'prod'] as $env) {
            $this->assertStringContainsString('cache-' . $env . '-before=absent', $state, 'the cache must be removed before the container starts');
        }
        $this->assertWorkermanCachesAreGone($sandbox);
    }

    public function testDockerTestWorktreeCleansTheCacheWhenTheContainerFails(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);
        $this->writeWorkermanCaches($sandbox);

        $binDir = $sandbox . '/fake-bin';
        $argsLog = $sandbox . '/docker-args.log';
        $stateLog = $sandbox . '/docker-state.log';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($sandbox, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $argsLog,
            'FAKE_DOCKER_STATE_LOG' => $stateLog,
            'FAKE_DOCKER_WORKTREE' => $sandbox,
            'FAKE_DOCKER_WRITE_CACHE' => '1',
            'FAKE_DOCKER_EXIT' => '1',
        ]);

        $this->assertSame(1, $code, $output);
        $this->assertWorkermanCachesAreGone($sandbox);
    }

    /**
     * `rm -rf var/cache/<env>/` follows a symlinked env dir and deletes its
     * target. The cleanup must remove the link itself; a plain
     * `rm -rf var/cache/*` does that, the trailing slash does not (#867).
     */
    public function testDockerTestWorktreeDoesNotFollowASymlinkedEnvironmentCache(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);

        $outside = $sandbox . '-out';
        $this->assertTrue(mkdir($outside . '/dev', 0o775, true));
        $this->assertNotFalse(file_put_contents($outside . '/dev/protected.txt', 'keep'));

        $this->assertTrue(mkdir($sandbox . '/var/cache', 0o775, true));
        $this->assertTrue(symlink($outside . '/dev', $sandbox . '/var/cache/dev'));

        $binDir = $sandbox . '/fake-bin';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($sandbox, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $sandbox . '/docker-args.log',
            'FAKE_DOCKER_STATE_LOG' => $sandbox . '/docker-state.log',
            'FAKE_DOCKER_WORKTREE' => $sandbox,
            'FAKE_DOCKER_WRITE_CACHE' => '0',
        ]);

        $this->assertSame(0, $code, $output);
        $this->assertFalse(is_link($sandbox . '/var/cache/dev'), 'the symlinked env dir must be removed as a link');
        $this->assertFileExists($outside . '/dev/protected.txt', 'cleanup must not delete the symlink target');
    }

    /**
     * A symlinked var/cache itself can point anywhere. The helper must refuse
     * it before the first cleanup instead of deleting the target's contents.
     */
    public function testDockerTestWorktreeRefusesASymlinkedCacheDirectory(): void
    {
        $sandbox = sys_get_temp_dir() . '/docker-wt test-' . bin2hex(random_bytes(6));
        $this->sandboxes[] = $sandbox;
        $this->initGitRepository($sandbox);

        $outside = $sandbox . '-out';
        $this->assertTrue(mkdir($outside . '/dev', 0o775, true));
        $this->assertNotFalse(file_put_contents($outside . '/dev/protected.txt', 'keep'));

        $this->assertTrue(mkdir($sandbox . '/var', 0o775, true));
        $this->assertTrue(symlink($outside, $sandbox . '/var/cache'));

        $binDir = $sandbox . '/fake-bin';
        $this->makeFakeDocker($binDir);

        [$code, $output] = $this->runDockerTestWorktree($sandbox, $binDir, [
            'FAKE_DOCKER_ARGS_LOG' => $sandbox . '/docker-args.log',
            'FAKE_DOCKER_STATE_LOG' => $sandbox . '/docker-state.log',
            'FAKE_DOCKER_WORKTREE' => $sandbox,
            'FAKE_DOCKER_WRITE_CACHE' => '0',
        ]);

        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString('refusing to clean symlinked var/cache', $output);
        $this->assertFileExists($outside . '/dev/protected.txt', 'refusing must delete nothing');
    }

    private function initGitRepository(string $dir): void
    {
        $this->assertTrue(mkdir($dir, 0o775, true));
        $this->assertNotFalse(file_put_contents($dir . '/composer.json', "{}\n"));

        $git = self::CLEAN_GIT_ENV . 'git -C ' . escapeshellarg($dir) . ' ';
        exec($git . 'init -q 2>&1 && ' . $git . '-c user.name=t -c user.email=t@example.com add composer.json 2>&1 && ' . $git . '-c user.name=t -c user.email=t@example.com commit -q -m init 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    /**
     * A fake `docker` that records its arguments and simulates the container
     * writing the config cache into the bind mount.
     */
    private function makeFakeDocker(string $binDir): void
    {
        $this->assertTrue(mkdir($binDir, 0o775, true));
        $script = <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "$@" >> "${FAKE_DOCKER_ARGS_LOG}"
if [[ "$1" == "image" ]]; then
  exit 0
fi
if [[ "$1" == "run" ]]; then
  for env in dev test prod; do
    if [[ -e "${FAKE_DOCKER_WORKTREE}/var/cache/${env}/workerman/config.cache.php" ]]; then
      echo "cache-${env}-before=present" >> "${FAKE_DOCKER_STATE_LOG}"
    else
      echo "cache-${env}-before=absent" >> "${FAKE_DOCKER_STATE_LOG}"
    fi
  done
  if [[ "${FAKE_DOCKER_WRITE_CACHE:-0}" == "1" ]]; then
    for env in dev test prod; do
      mkdir -p "${FAKE_DOCKER_WORKTREE}/var/cache/${env}/workerman"
      : > "${FAKE_DOCKER_WORKTREE}/var/cache/${env}/workerman/config.cache.php"
    done
  fi
fi
exit "${FAKE_DOCKER_EXIT:-0}"
SH;
        $this->assertNotFalse(file_put_contents($binDir . '/docker', $script));
        $this->assertTrue(chmod($binDir . '/docker', 0o755));
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string}
     */
    private function runDockerTestWorktree(string $worktree, string $binDir, array $environment): array
    {
        $prefix = self::CLEAN_GIT_ENV . 'PATH=' . escapeshellarg($binDir . ':' . getenv('PATH')) . ' ';
        foreach ($environment as $name => $value) {
            $prefix .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $output = [];
        exec($prefix . 'bash ' . escapeshellarg($this->projectDir . '/bin/docker-test-worktree') . ' ' . escapeshellarg($worktree) . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function writeWorkermanCaches(string $dir): void
    {
        foreach (['dev', 'test', 'prod'] as $env) {
            $cacheDir = $dir . '/var/cache/' . $env . '/workerman';
            $this->assertTrue(mkdir($cacheDir, 0o775, true));
            $this->assertNotFalse(file_put_contents($cacheDir . '/config.cache.php', 'stale'));
            // A warm kernel container cache makes Runner::warmUpCache() skip
            // the warmers, so the config cache must not be deleted alone.
            $this->assertNotFalse(file_put_contents($dir . '/var/cache/' . $env . '/ContainerSentinel.php.meta', 'warm'));
        }
    }

    private function assertWorkermanCachesAreGone(string $dir): void
    {
        foreach (['dev', 'test', 'prod'] as $env) {
            $this->assertFileDoesNotExist($dir . '/var/cache/' . $env . '/workerman/config.cache.php');
            $this->assertFileDoesNotExist($dir . '/var/cache/' . $env . '/ContainerSentinel.php.meta', 'the whole env cache must be removed, not only the workerman file');
        }
    }
}
