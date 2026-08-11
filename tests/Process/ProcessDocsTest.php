<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Process;

use PHPUnit\Framework\TestCase;

/**
 * Convention test for the process artifacts of issue #686:
 * `docs/process-changelog.md`, `docs/process-notices.md`, and the
 * proof-of-work format documented in `docs/workflow.md`.
 *
 * @coversNothing
 */
final class ProcessDocsTest extends TestCase
{
    private const NOTICE_IDS = ['N-01', 'N-02', 'N-03', 'N-04', 'N-05', 'N-06', 'N-07', 'N-08', 'N-09', 'N-10', 'N-11', 'N-12', 'N-13'];

    /**
     * The whole proof of work, after the machinery that used to enforce it was
     * removed. Both documents must name all four, or an agent reading either
     * one learns an incomplete format.
     */
    private const POW_FILES = ['findings-coder.md', 'findings-review.md', 'code-decision-', 'review-'];

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

        // The acceptance criterion was "a retro over the existing artifacts
        // returns >=3 gate candidates" — assert the entry names at least
        // three, not just gestures at "some".
        self::assertStringContainsString('tests/MarkdownLinkTest.php', $section);
        self::assertStringContainsString('tests/ChangelogStructureTest.php', $section);
        self::assertStringContainsString('tests/LintScopeTest.php', $section);

        // The third candidate was deliberately deferred, not silently
        // dropped — the deferral must be recorded as a decision with a
        // reason, not merely omitted from what shipped.
        self::assertMatchesRegularExpression('/[Dd]eferred/', $section);
        self::assertStringContainsString('fails by design', $section);
    }

    /**
     * Removing the proof-of-work machinery is itself a process change, and the
     * one most likely to be mistaken later for "it was never there". The entry
     * must survive, and it must be honest about what the repository gave up
     * rather than reading as a pure win.
     */
    public function testProcessChangelogRecordsWhatTheSimplificationGaveUp(): void
    {
        $section = $this->extractChangelogEntry($this->read('docs/process-changelog.md'), '#3');

        self::assertNotNull($section, 'the proof-of-work simplification must be recorded as entry #3');
        self::assertStringContainsString('bin/check-pow.php', $section, 'the entry must name what was deleted');
        self::assertMatchesRegularExpression(
            '/What is lost|no longer detect|nothing now detects/i',
            $section,
            'a process change that removes a safeguard must say so plainly',
        );
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

    /**
     * Most of these triggers name tooling that no longer exists, so they can
     * never fire. Keeping the notices without saying that would leave a reader
     * treating dead policy as live.
     */
    public function testProcessNoticesSaysItsTriggersReferToRemovedTooling(): void
    {
        $header = substr($this->read('docs/process-notices.md'), 0, (int) strpos($this->read('docs/process-notices.md'), '## N-01'));

        self::assertMatchesRegularExpression(
            '/history|no longer exist|cannot fire|removed/i',
            $header,
            'the notices file must warn that N-01..N-13 describe a mechanism that was removed',
        );
    }

    public function testWorkflowDocumentsTheFourProofOfWorkFiles(): void
    {
        $content = $this->read('docs/workflow.md');

        foreach (self::POW_FILES as $file) {
            self::assertStringContainsString($file, $content, 'docs/workflow.md must document ' . $file);
        }
    }

    public function testProofOfWorkReadmeDocumentsTheFourFiles(): void
    {
        $content = $this->read('docs/proof_of_work/README.md');

        foreach (self::POW_FILES as $file) {
            self::assertStringContainsString($file, $content, 'docs/proof_of_work/README.md must document ' . $file);
        }
    }

    /**
     * The scripts are gone; a document still telling an agent to run them
     * sends it to a file that does not exist, which is worse than saying
     * nothing.
     */
    public function testNoProcessDocumentStillInstructsRunningTheDeletedTooling(): void
    {
        foreach (['docs/workflow.md', 'docs/proof_of_work/README.md', 'bin/README.md', 'CONTRIBUTING.md'] as $doc) {
            self::assertDoesNotMatchRegularExpression(
                '/(php|composer) +bin\/(pow|check-pow|pow-metrics|pow-common)/',
                $this->read($doc),
                $doc . ' must not tell anyone to run a script this repository no longer has',
            );
        }
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
