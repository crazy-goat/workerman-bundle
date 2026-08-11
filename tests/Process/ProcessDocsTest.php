<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Process;

use PHPUnit\Framework\TestCase;

/**
 * Convention test for the phase-4 process artifacts of issue #686:
 * `docs/process-changelog.md`, `docs/process-notices.md`, and the new steps
 * documented in `docs/workflow.md`.
 *
 * @coversNothing
 */
final class ProcessDocsTest extends TestCase
{
    private const NOTICE_IDS = ['N-01', 'N-02', 'N-03', 'N-04', 'N-05', 'N-06', 'N-07', 'N-08', 'N-09', 'N-10', 'N-11', 'N-12', 'N-13'];

    private string $projectDir;

    protected function setUp(): void
    {
        $projectDir = realpath(__DIR__ . '/../..');
        self::assertNotFalse($projectDir, 'cannot determine project root');
        $this->projectDir = $projectDir;
    }

    public function testProcessChangelogExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/process-changelog.md');
    }

    public function testProcessChangelogHasTheCycleZeroEntryNamingTheIssueAndPr(): void
    {
        $content = $this->read('docs/process-changelog.md');

        self::assertStringContainsString('Cycle zero', $content);
        self::assertStringContainsString('#686', $content);
        self::assertStringContainsString('#687', $content);
        self::assertMatchesRegularExpression(
            '/no-pow.{0,80}#687.{0,40}#686|no-pow.{0,80}#686.{0,40}#687/s',
            $content,
            'the bypass line must name both no-pow and the exact issue/PR number, on one line, for bin/check-pow.php\'s POW-09 to match it',
        );
    }

    public function testProcessChangelogDefinesItsFormatUpFront(): void
    {
        $content = $this->read('docs/process-changelog.md');

        self::assertStringContainsString('Success criterion', $content);
        self::assertMatchesRegularExpression('/pending.{0,20}\|.{0,20}kept.{0,20}\|.{0,20}reverted/', $content);
    }

    public function testProcessChangelogHasTheBootstrapGateMiningEntryNamingAtLeastThreeCandidates(): void
    {
        $content = $this->read('docs/process-changelog.md');

        self::assertStringContainsString('#2 —', $content, 'the bootstrap gate-mining entry must be recorded as entry #2');
        self::assertStringContainsString('Bootstrap', $content);

        $section = $this->extractChangelogEntry($content, '#2');
        self::assertNotNull($section, 'entry #2 must be its own section');

        // The phase 4 acceptance criterion is "a retro over the existing
        // artifacts returns >=3 gate candidates" — assert the entry names at
        // least three, not just gestures at "some".
        self::assertStringContainsString('tests/MarkdownLinkTest.php', $section);
        self::assertStringContainsString('tests/ChangelogStructureTest.php', $section);
        self::assertStringContainsString('tests/LintScopeTest.php', $section);

        // The third candidate was deliberately deferred, not silently
        // dropped — the deferral must be recorded as a decision with a
        // reason, not merely omitted from what shipped.
        self::assertMatchesRegularExpression('/[Dd]eferred/', $section);
        self::assertStringContainsString('fails by design', $section);
    }

    public function testProcessNoticesExists(): void
    {
        self::assertFileExists($this->projectDir . '/docs/process-notices.md');
    }

    public function testProcessNoticesContainsEveryNoticeWithATrigger(): void
    {
        $content = $this->read('docs/process-notices.md');

        foreach (self::NOTICE_IDS as $id) {
            self::assertStringContainsString($id, $content, $id . ' must be present in docs/process-notices.md');

            $section = $this->extractNoticeSection($content, $id);
            self::assertNotNull($section, $id . ' must have its own section');
            self::assertStringContainsStringIgnoringCase(
                '**Trigger:**',
                $section,
                $id . ' must state a measurable trigger for revisiting it',
            );
        }
    }

    public function testWorkflowDocumentsAllPhaseFourSteps(): void
    {
        $content = $this->read('docs/workflow.md');

        foreach ([
            '## 4b. Classify Findings',
            '## 4c. Escalate to a Gate',
            '## 13.5. Audit the Proof of Work',
            '## 15. Retro',
            '## 16. Apply',
            '## 17. Verify the Change Stuck',
        ] as $heading) {
            self::assertStringContainsString($heading, $content, 'docs/workflow.md must document "' . $heading . '"');
        }
    }

    public function testWorkflowStatesTheRuleOfTwo(): void
    {
        $content = $this->read('docs/workflow.md');

        self::assertStringContainsString('rule of two', $content);
    }

    public function testWorkflowReferencesPowMetricsForTheRetro(): void
    {
        $content = $this->read('docs/workflow.md');

        self::assertStringContainsString('bin/pow-metrics.php', $content);
        self::assertStringContainsString('--min-cycles', $content);
    }

    private function extractNoticeSection(string $content, string $id): ?string
    {
        $pattern = '/^## ' . preg_quote($id, '/') . ' —.*?(?=^## N-|\z)/ms';

        if (preg_match($pattern, $content, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function extractChangelogEntry(string $content, string $marker): ?string
    {
        $pattern = '/^### ' . preg_quote($marker, '/') . ' —.*?(?=^### #|\z)/ms';

        if (preg_match($pattern, $content, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents($this->projectDir . '/' . $relativePath);
        self::assertIsString($content, 'unable to read ' . $relativePath);

        return $content;
    }
}
