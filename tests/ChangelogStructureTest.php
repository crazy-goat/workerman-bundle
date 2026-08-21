<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/check-changelog.php` as a subprocess (issue #654).
 *
 * The structural rules themselves live in the script — the single source of
 * truth — so `composer lint` (and through it the pre-push hook and the CI
 * Lint job) enforces exactly what these tests assert. They were mined from
 * `.pi-subagents/artifacts/` review history (#686 phase 4): duplicate
 * subheadings (#641), out-of-order versions (#255) and a stale `[Unreleased]`
 * section (#356) recurred across ~9 review artifacts. See the script header
 * for the rules and for the narrowed legacy-reference rationale.
 *
 * Only the passing case touches the real CHANGELOG.md; every failure scenario
 * runs against a synthetic fixture in a throw-away sandbox.
 *
 * @coversNothing
 */
final class ChangelogStructureTest extends TestCase
{
    private string $sandbox = '';

    private string $script = '';

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__) . '/bin/check-changelog.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/check-changelog-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox, 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }
    }

    public function testTheRealChangelogPassesWithNoArguments(): void
    {
        $result = $this->runScript([], []);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('check-changelog: OK', $result['out']);
        self::assertSame('', $result['err']);
    }

    public function testADuplicateSubheadingFails(): void
    {
        // #641: two `### Fixed` sections inside one version block.
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Fixed

            - First fix ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ### Fixed

            - Second fix ([#901](https://github.com/crazy-goat/workerman-bundle/issues/901))

            ## [0.26.0] - 2026-08-15

            ### Added

            - Released entry ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('"## [Unreleased]" has 2 "### Fixed" subheadings', $result['err']);
        self::assertStringContainsString('must appear at most once per version block', $result['err']);
    }

    public function testOutOfOrderVersionHeadingsFail(): void
    {
        // #255: an older version heading below a newer one.
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.10.0] - 2026-01-01

            ### Fixed

            - Old ([#898](https://github.com/crazy-goat/workerman-bundle/issues/898))

            ## [0.11.0] - 2026-02-01

            ### Fixed

            - Newer still ([#897](https://github.com/crazy-goat/workerman-bundle/issues/897))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('line 15: version 0.11.0 is not strictly older than', $result['err']);
        self::assertStringContainsString('released versions must be in descending order', $result['err']);
    }

    public function testAMissingUnreleasedSectionFails(): void
    {
        // #356: everything already dumped into released headings.
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('must have exactly one "## [Unreleased]" heading, found 0', $result['err']);
    }

    public function testAnUnreleasedSectionThatIsNotFirstFails(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('"## [Unreleased]" must be the first version heading in the file', $result['err']);
    }

    public function testAMalformedReleasedHeadingFails(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.27] - 2026-08-20

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('line 9: "## [0.27] - 2026-08-20" does not match "## [x.y.z] - YYYY-MM-DD"', $result['err']);
    }

    public function testAnEntryWithoutAReferenceFailsAndReportsItsLine(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - A brand new feature nobody filed an issue for

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('changelog entries with no issue/PR reference and not on the frozen legacy list: 1', $result['err']);
        self::assertStringContainsString('line 7: - A brand new feature nobody filed an issue for', $result['err']);
    }

    public function testAFrozenLegacyEntryWithoutAReferenceIsAccepted(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Fixed

            - Fix PHPStan type annotations in test helpers

            ## [0.26.0] - 2026-08-15

            ### Added

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testABareParenthesisedReferenceIsAlsoAccepted(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New (#900)

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testEveryViolationIsReportedInOneRun(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [0.26.0] - 2026-08-15

            ### Fixed

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('must have exactly one "## [Unreleased]" heading', $result['err']);
        self::assertStringContainsString('has 2 "### Fixed" subheadings', $result['err']);
        self::assertSame(
            1,
            $result['code'],
            'all violations are collected before the script decides — one run reports them all',
        );
    }

    public function testTheRootCanBeRedirectedThroughTheEnvironmentAndIsWarnedAbout(): void
    {
        $this->writeValidChangelog();

        $result = $this->runScript(['CHANGELOG_CHECK_ROOT' => $this->sandbox], []);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('check-changelog: root ' . $this->sandboxRoot() . "\n", $result['out']);
        self::assertStringContainsString('check-changelog: warning: root overridden via CHANGELOG_CHECK_ROOT', $result['out']);
    }

    public function testAnExplicitRootWinsOverTheEnvironmentAndIsNotWarnedAbout(): void
    {
        $this->writeValidChangelog();

        $decoy = $this->sandbox . '/decoy';
        self::assertTrue(mkdir($decoy, 0o775, true));

        $result = $this->runScript(['CHANGELOG_CHECK_ROOT' => $decoy], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringNotContainsString('root overridden', $result['out']);
    }

    public function testAMissingChangelogFileIsAUsageError(): void
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('CHANGELOG.md is missing or unreadable', $result['err']);
    }

    public function testAnUnknownOptionIsAUsageError(): void
    {
        $this->writeValidChangelog();

        $result = $this->runScript([], ['--nope']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('Unknown argument: --nope', $result['err']);
    }

    /**
     * A minimal changelog that satisfies every rule of the script.
     */
    private function writeValidChangelog(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);
    }

    private function writeChangelog(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->sandbox . '/CHANGELOG.md', $contents));
    }

    /**
     * Runs the script against the sandbox and asserts it failed with exit
     * code 1 — every call site asserts on the message, none on the mechanics.
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runChecked(): array
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(1, $result['code'], 'expected a structural violation, got: ' . $result['out']);

        return $result;
    }

    /**
     * Runs the script with exactly the given arguments and environment.
     *
     * @param array<string, string> $env
     * @param list<string> $args
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runScript(array $env, array $args): array
    {
        $command = [\PHP_BINARY, $this->script, ...$args];

        $outFile = $this->sandbox . '/stdout.log';
        $errFile = $this->sandbox . '/stderr.log';

        $descriptors = [
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        $pipes = [];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->sandbox,
            ['PATH' => (string) getenv('PATH'), ...$env],
        );
        self::assertIsResource($process);

        $code = proc_close($process);

        return [
            'code' => $code,
            'out' => (string) file_get_contents($outFile),
            'err' => (string) file_get_contents($errFile),
        ];
    }

    private function sandboxRoot(): string
    {
        $root = realpath($this->sandbox);
        self::assertIsString($root);

        return $root;
    }

    private function removeRecursively(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
