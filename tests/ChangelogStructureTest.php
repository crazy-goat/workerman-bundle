<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap gate mined from `.pi-subagents/artifacts/` review history (issue
 * #686 phase 4): CHANGELOG accuracy/structure (duplicate headings, a missing
 * `[Unreleased]`, out-of-order versions) recurred across ~9 review artifacts,
 * including repeat offences fixed by #641, #255 and #356. This is the
 * automated check the rule of two calls for on a second occurrence — see
 * `docs/workflow.md`, step 4c.
 *
 * Narrowed rule (documented, not silently dropped — see `docs/workflow.md`
 * step 4c and the task that added this test): "every `- ` entry carries a
 * `(#NNN)` reference" does not hold for the whole history. Twelve pre-existing
 * top-level entries predate the convention entirely (the earliest, 0.12.0,
 * predates per-entry issue tracking; a handful of small refactor/chore
 * entries and one already-merged `bin/gh-branch` entry were never filed
 * against a tracked issue). Rather than rewrite history or weaken the rule for
 * every entry, those twelve are frozen in {@see LEGACY_ENTRIES_WITHOUT_A_REFERENCE}
 * by their exact first line: any entry NOT on that list must carry a
 * reference, so a *new* unreferenced entry still fails this test. Also: the
 * reference format actually used is a markdown link (`[#123](url)`), not the
 * bare `(#123)` the issue phrasing suggested — both are accepted.
 *
 * @coversNothing
 */
final class ChangelogStructureTest extends TestCase
{
    private const KEEP_A_CHANGELOG_SUBHEADINGS = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

    /**
     * Exact first line (including the leading "- ") of every changelog entry
     * that predates the "every entry carries an issue reference" convention.
     * Frozen on purpose: adding to this list should be rare and deliberate,
     * never the fix for a newly written entry that forgot its reference.
     *
     * @var list<string>
     */
    private const LEGACY_ENTRIES_WITHOUT_A_REFERENCE = [
        '- New developer helper `bin/gh-branch`: creates or switches to the',
        '- Extract `configureHandler()` from `ServerWorker::onWorkerStart()` — reduces closure complexity',
        '- Add `ext-zip` and `ext-inotify` to CI, fix test assertions for missing extensions',
        '- Fix PHPStan type annotations in test helpers',
        '- New `e2e/README.md` explaining e2e directory purpose and contributor guidance',
        '- Add `e2e/README.md` explaining e2e directory purpose and contributor guidance',
        '- **Breaking**: `ResponseConverterStrategyInterface::convert()` now requires a `TcpConnection` parameter',
        '- `BinaryFileResponseStrategy` now deletes temp files immediately after connection closes',
        '- `RequestConverter::toSymfonyRequest()` now returns empty content for multipart/form-data requests',
        '- **Critical**: Priority-based strategy ordering is now enforced in compiler pass',
        '- **Breaking**: Replaced generic PHP exceptions with typed exceptions throughout the codebase',
        '- **Breaking**: Removed root-level exception classes (moved to `Exception` namespace)',
    ];

    private string $projectDir;

    /** @var list<string> */
    private array $lines;

    protected function setUp(): void
    {
        $projectDir = realpath(__DIR__ . '/..');
        self::assertNotFalse($projectDir, 'cannot determine project root');
        $this->projectDir = $projectDir;

        $contents = file_get_contents($this->projectDir . '/CHANGELOG.md');
        self::assertIsString($contents, 'unable to read CHANGELOG.md');
        $this->lines = explode("\n", $contents);
    }

    public function testUnreleasedSectionExistsExactlyOnceAndComesFirst(): void
    {
        $headings = $this->versionHeadings();
        self::assertNotEmpty($headings, 'CHANGELOG.md has no "## [...]" version headings at all');

        $unreleasedIndexes = array_keys(array_filter(
            $headings,
            static fn(array $heading): bool => $heading['raw'] === '## [Unreleased]',
        ));

        self::assertCount(1, $unreleasedIndexes, 'CHANGELOG.md must have exactly one "## [Unreleased]" heading');
        self::assertSame(
            array_key_first($headings),
            $unreleasedIndexes[0],
            '"## [Unreleased]" must be the first version heading in the file',
        );
    }

    public function testReleasedVersionHeadingsAreWellFormedAndStrictlyDescending(): void
    {
        $headings = $this->versionHeadings();
        $released = array_values(array_filter(
            $headings,
            static fn(array $heading): bool => $heading['raw'] !== '## [Unreleased]',
        ));

        self::assertNotEmpty($released, 'CHANGELOG.md has no released version headings');

        $previousVersion = null;

        foreach ($released as $heading) {
            self::assertMatchesRegularExpression(
                '/^## \[\d+\.\d+\.\d+\] - \d{4}-\d{2}-\d{2}$/',
                $heading['raw'],
                sprintf('line %d: "%s" does not match "## [x.y.z] - YYYY-MM-DD"', $heading['line'], $heading['raw']),
            );

            if (preg_match('/^## \[(\d+\.\d+\.\d+)\]/', $heading['raw'], $matches) !== 1) {
                self::fail('line ' . $heading['line'] . ': unable to extract the version from "' . $heading['raw'] . '"');
            }

            $version = $matches[1];

            if ($previousVersion !== null) {
                self::assertLessThan(
                    0,
                    version_compare($version, $previousVersion),
                    sprintf(
                        'line %d: version %s is not strictly older than the preceding heading\'s %s — released versions must be in descending order',
                        $heading['line'],
                        $version,
                        $previousVersion,
                    ),
                );
            }

            $previousVersion = $version;
        }
    }

    public function testKeepAChangelogSubheadingsAppearAtMostOncePerVersionBlock(): void
    {
        foreach ($this->versionBlocks() as $block) {
            $counts = [];

            foreach ($block['subheadings'] as $subheading) {
                if (!in_array($subheading, self::KEEP_A_CHANGELOG_SUBHEADINGS, true)) {
                    continue;
                }

                $counts[$subheading] = ($counts[$subheading] ?? 0) + 1;
            }

            foreach ($counts as $subheading => $count) {
                self::assertSame(
                    1,
                    $count,
                    sprintf('"%s" has %d "### %s" subheadings — a Keep a Changelog subheading must appear at most once per version block', $block['heading'], $count, $subheading),
                );
            }
        }
    }

    public function testEveryEntryCarriesAnIssueReferenceExceptFrozenLegacyEntries(): void
    {
        $violations = [];

        foreach ($this->topLevelEntries() as $entry) {
            if (preg_match('/\[#\d+\]|\(#\d+\)/', $entry['text']) === 1) {
                continue;
            }

            if (in_array($entry['firstLine'], self::LEGACY_ENTRIES_WITHOUT_A_REFERENCE, true)) {
                continue;
            }

            $violations[] = sprintf('line %d: %s', $entry['line'], $entry['firstLine']);
        }

        self::assertSame(
            [],
            $violations,
            "changelog entries with no issue/PR reference and not on the frozen legacy list:\n" . implode("\n", $violations),
        );
    }

    /**
     * @return list<array{raw: string, line: int}>
     */
    private function versionHeadings(): array
    {
        $headings = [];

        foreach ($this->lines as $index => $line) {
            if (preg_match('/^## \[/', $line) === 1) {
                $headings[] = ['raw' => $line, 'line' => $index + 1];
            }
        }

        return $headings;
    }

    /**
     * @return list<array{heading: string, subheadings: list<string>}>
     */
    private function versionBlocks(): array
    {
        $blocks = [];
        $current = null;

        foreach ($this->lines as $line) {
            if (preg_match('/^## \[/', $line) === 1) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = ['heading' => $line, 'subheadings' => []];

                continue;
            }

            if ($current !== null && preg_match('/^### (.+)$/', $line, $matches) === 1) {
                $current['subheadings'][] = $matches[1];
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Top-level `- ` bullets (no leading indentation — a nested `  - ` bullet
     * is a continuation detail of its parent, not its own entry), each
     * gathered together with its wrapped continuation lines up to the next
     * bullet, heading or blank line.
     *
     * @return list<array{text: string, firstLine: string, line: int}>
     */
    private function topLevelEntries(): array
    {
        $entries = [];
        $count = count($this->lines);
        $i = 0;

        while ($i < $count) {
            $line = $this->lines[$i];

            if (preg_match('/^-\s/', $line) !== 1) {
                $i++;

                continue;
            }

            $text = $line;
            $j = $i + 1;

            while (
                $j < $count
                && preg_match('/^-\s/', $this->lines[$j]) !== 1
                && preg_match('/^#{2,3}\s/', $this->lines[$j]) !== 1
                && trim($this->lines[$j]) !== ''
            ) {
                $text .= "\n" . $this->lines[$j];
                $j++;
            }

            $entries[] = ['text' => $text, 'firstLine' => $line, 'line' => $i + 1];
            $i = $j;
        }

        return $entries;
    }
}
