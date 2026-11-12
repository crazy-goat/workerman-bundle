<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
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

    public function testDuplicateReleasedVersionHeadingsFail(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.2.0] - 2025-01-01

            ### Fixed

            - First ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            ## [0.2.0] - 2025-01-01

            ### Fixed

            - Second ([#898](https://github.com/crazy-goat/workerman-bundle/issues/898))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('line 15: version 0.2.0 is not strictly older than', $result['err']);
    }

    public function testAFileWithNoVersionHeadingsAtAllFails(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            - An orphan entry with nowhere to live ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('CHANGELOG.md has no "## [...]" version headings at all', $result['err']);
        self::assertStringContainsString('must have exactly one "## [Unreleased]" heading, found 0', $result['err']);
    }

    public function testAnOnlyUnreleasedFileFailsForWantOfReleasedHeadings(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('CHANGELOG.md has no released version headings', $result['err']);
    }

    public function testAnUnknownSubheadingIsSilentlySkipped(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Fix

            - Something ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.26.0] - 2026-08-15

            ### Fix

            - Older ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testAReferenceOnAContinuationLineIsAccepted(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - A long wrapped entry that keeps talking across the line break
              and only cites its issue where the sentence ends
              ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testAFencedHeadingNeverCountsAsStructure(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ```
            ## [0.26.0] - 2026-08-15
            ```

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('CHANGELOG.md has no released version headings', $result['err']);
    }

    public function testAFencedSubheadingCopyIsIgnoredSoOneRealSubheadingPasses(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Fixed

            - The real one ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ```
            ### Fixed
            ```

            ## [0.26.0] - 2026-08-15

            ### Added

            - Released ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
    }

    public function testADuplicateSubheadingIsStillCaughtWhenACopyHidesInAFence(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Fixed

            - First ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ### Fixed

            - Second ([#901](https://github.com/crazy-goat/workerman-bundle/issues/901))

            ```
            ### Fixed
            ```

            ## [0.26.0] - 2026-08-15

            ### Added

            - Released ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('"## [Unreleased]" has 2 "### Fixed" subheadings', $result['err']);
    }

    public function testABacktickedReferenceShapeIsNotAReference(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - Mentions the convention `(#123)` in passing

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('line 7: - Mentions the convention', $result['err']);
    }

    public function testAnAnchorLinkIsNotAReference(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - Jumps to [the sweeper note](#123) further down

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('changelog entries with no issue/PR reference and not on the frozen legacy list: 1', $result['err']);
        self::assertStringContainsString('line 7: - Jumps to [the sweeper note](#123) further down', $result['err']);
    }

    public function testAnImpossibleCalendarDateFails(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ## [0.3.0] - 2026-13-45

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('line 9: "2026-13-45" is not an ISO-8601 calendar date (YYYY-MM-DD)', $result['err']);
    }

    public function testATrailingSpaceOnTheUnreleasedHeadingIsTolerated(): void
    {
        $this->writeChangelog("# Changelog\n\n## [Unreleased] \n\n### Added\n\n"
            . "- New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))\n\n"
            . "## [0.26.0] - 2026-08-15\n\n### Fixed\n\n"
            . "- Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))\n");

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
    }

    public function testATildeFencedHeadingNeverCountsAsStructure(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ~~~
            ## [0.26.0] - 2026-08-15
            ~~~

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('CHANGELOG.md has no released version headings', $result['err']);
    }

    public function testABacktickFenceIsNotClosedByATildeMarker(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ```
            ~~~
            ### Fixed
            ~~~
            ```

            ## [0.26.0] - 2026-08-15

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
    }

    public function testAnUnterminatedFenceIsReportedAtItsOpeningLine(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ```
            ## [0.26.0] - 2026-08-15

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('line 9: unterminated code fence opened here', $result['err']);
        self::assertStringNotContainsString('no released version headings', $result['err']);
    }

    public function testASubheadingWithTrailingSpaceStillCountsAsADuplicate(): void
    {
        $this->writeChangelog((string) preg_replace(
            '/^### Fixed$/m',
            '### Fixed ',
            <<<MARKDOWN
                # Changelog

                ## [Unreleased]

                ### Fixed

                - First ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

                ### Fixed

                - Second ([#901](https://github.com/crazy-goat/workerman-bundle/issues/901))

                ## [0.26.0] - 2026-08-15

                ### Added

                - Released ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

                MARKDOWN,
        ));

        $result = $this->runChecked();

        self::assertStringContainsString('"## [Unreleased]" has 2 "### Fixed" subheadings', $result['err']);
    }

    public function testAFourBacktickFenceIsNotClosedByAnInnerTripleBacktick(): void
    {
        $this->writeChangelog(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - New ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

            ````
            ```
            ## [0.9.9] - 2020-01-01
            ````

            ### Fixed

            - Something ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

            MARKDOWN);

        $result = $this->runChecked();

        self::assertStringContainsString('CHANGELOG.md has no released version headings', $result['err']);
    }

    public function testASubheadingWithLeadingSpacesStillCountsAsADuplicate(): void
    {
        $this->writeChangelog(str_replace(
            '### Fixed',
            '###   Fixed',
            <<<'MARKDOWN'
                # Changelog

                ## [Unreleased]

                ### Fixed

                - First ([#900](https://github.com/crazy-goat/workerman-bundle/issues/900))

                ### Fixed

                - Second ([#901](https://github.com/crazy-goat/workerman-bundle/issues/901))

                ## [0.26.0] - 2026-08-15

                ### Added

                - Released ([#899](https://github.com/crazy-goat/workerman-bundle/issues/899))

                MARKDOWN,
        ));

        $result = $this->runChecked();

        self::assertStringContainsString('"## [Unreleased]" has 2 "### Fixed" subheadings', $result['err']);
    }

    public function testHelpExitsZeroAndPrintsUsage(): void
    {
        foreach ([['--help'], ['-h']] as $flag) {
            $result = $this->runScript([], $flag);

            self::assertSame(0, $result['code'], $result['err']);
            self::assertStringContainsString('Usage: php bin/check-changelog.php', $result['out']);
            self::assertStringContainsString('--root=DIR', $result['out']);
            self::assertStringContainsString('CHANGELOG_CHECK_ROOT', $result['out']);
        }
    }

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
     * @return array{code: int, out: string, err: string}
     */
    private function runChecked(): array
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(1, $result['code'], 'expected a structural violation, got: ' . $result['out']);

        return $result;
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $args
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
