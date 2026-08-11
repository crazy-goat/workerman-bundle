<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\ProofOfWork;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/check-pow.php` as a subprocess against throw-away fixture
 * repositories.
 *
 * The script operates on `CHECK_POW_ROOT` and replaces every `gh` call with the
 * JSON file in `CHECK_POW_GH_FIXTURE`, so these tests never reach GitHub and
 * never touch the real `docs/proof_of_work/`.
 *
 * One test per cheat scenario: the point of the gate is not that it passes on a
 * good cycle, it is that each specific way of faking a cycle is caught with a
 * distinct, greppable message.
 *
 * @coversNothing
 */
final class CheckPowScriptTest extends TestCase
{
    private const ISSUE = 4242;
    private const SLUG = 'sample-issue';
    private const BRANCH = 'fix/issue-4242-sample-issue';
    private const POW_DIR = 'docs/proof_of_work/4242-sample-issue';

    private const ROUND_ONE_BODY = "---\nround: 1\n---\n\ncoder report\n";
    private const ROUND_TWO_BODY = "---\nround: 1\n---\n\nreview report\n";

    private string $sandbox = '';

    private string $script = '';

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__, 2) . '/bin/check-pow.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/check-pow-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox, 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }
    }

    // ---------------------------------------------------------------------
    // Scope gate
    // ---------------------------------------------------------------------

    public function testAnOrdinaryBranchIsSkippedSoComposerLintNeverBreaks(): void
    {
        $this->buildHappyRepository();

        $result = $this->check('--branch=chore/tidy-up');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('skipped', $result['out']);
        self::assertStringContainsString('is not an issue branch', $result['out']);
    }

    public function testMissingPullRequestIsASkipButAStrictFailure(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture(['comments' => $this->comments()]);

        $advisory = $this->check();
        self::assertSame(0, $advisory['code'], $advisory['err']);
        self::assertStringContainsString('[POW-00] no pull request to validate', $advisory['err']);

        $strict = $this->check('--strict');
        self::assertSame(1, $strict['code']);
        self::assertStringContainsString('FAIL    [POW-00]', $strict['err']);
    }

    // ---------------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------------

    public function testTheHappyPathPasses(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('check-pow: ok', $result['out']);
        self::assertStringContainsString('proof of work for issue #4242 verified', $result['err']);
    }

    public function testTheHappyPathPassesWithVerifyReality(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->checkWith([
            'CHECK_POW_LINT_CMD' => 'exit 0',
            'CHECK_POW_TEST_CMD' => 'exit 0',
        ], '--strict', '--verify-reality');

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('coverage matches var/coverage.xml (81.50%)', $result['err']);
    }

    // ---------------------------------------------------------------------
    // Cheat scenarios
    // ---------------------------------------------------------------------

    public function testPullRequestWithoutAClosingIssueIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['closingIssuesReferences'] = [];
        $this->writeFixture($fixture);

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-01]', $result['err']);
        self::assertStringContainsString('no work without an issue', $result['err']);
    }

    public function testMissingProofOfWorkDirectoryIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->git('rm', '-r', '-q', self::POW_DIR);
        $this->commit('drop the proof of work');

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-02]', $result['err']);
        self::assertStringContainsString('no docs/proof_of_work/4242-<slug>/ for issue #4242', $result['err']);
    }

    public function testTamperedCommentBodyIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['comments']['222']['body'] = "---\nround: 1\n---\n\nreview report — all clean, honest\n";
        $this->writeFixture($fixture);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('body sha256', $result['err']);
        self::assertStringContainsString('the comment was tampered with', $result['err']);
    }

    public function testEditedCommentIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['comments']['222']['updated_at'] = '2026-08-11T12:00:00Z';
        $this->writeFixture($fixture);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('was edited after publication', $result['err']);
    }

    public function testDeletedCommentIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        unset($fixture['comments']['222']);
        $this->writeFixture($fixture);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('no longer exists — a round comment was deleted', $result['err']);
    }

    public function testDroppingARoundBreaksThePrevChain(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // The orchestrator deletes the round that reported findings and keeps
        // only the clean one; `prev` no longer points at anything real.
        $manifest = $this->manifest();
        $manifest['rounds'] = [$manifest['rounds'][1]];
        $this->writeManifest($manifest);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('the first round must have prev=null', $result['err']);
    }

    public function testBackfilledProofOfWorkIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        // Server-assigned timestamps cannot be reordered: round 2 was published
        // before round 1, so the narrative was written after the fact.
        $fixture['comments']['222']['created_at'] = '2026-08-11T09:00:00Z';
        $fixture['comments']['222']['updated_at'] = '2026-08-11T09:00:00Z';
        $this->writeFixture($fixture);

        $manifest = $this->manifest();
        $manifest['rounds'][1]['created_at'] = '2026-08-11T09:00:00Z';
        $this->writeManifest($manifest);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('the proof of work was backfilled', $result['err']);
    }

    public function testDeletedFindingBreaksTheAppendOnlyLedger(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // A second ledger commit that drops a row instead of appending one.
        $ledger = $this->read(self::POW_DIR . '/findings.md');
        $this->write(
            self::POW_DIR . '/findings.md',
            str_replace("| F-01 | 1 | src/Foo.php:1 | first finding | high | open |  |\n", '', $ledger)
                . "| F-02 | 2 | src/Foo.php:2 | second finding | low | fixed | done |\n",
        );
        $this->git('add', '-A');
        $this->commit('docs(pow): tidy the ledger');

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-06]', $result['err']);
        self::assertStringContainsString('the ledger is not append-only', $result['err']);
    }

    public function testOpenLedgerEntriesAtFinishAreRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->write(
            self::POW_DIR . '/findings.md',
            $this->read(self::POW_DIR . '/findings.md')
                . "| F-03 | 2 | src/Foo.php:9 | still open | high | open |  |\n",
        );
        $this->git('add', '-A');
        $this->commit('docs(pow): one more finding');

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-03]', $result['err']);
        self::assertStringContainsString('the ledger still has open finding(s) F-03', $result['err']);
    }

    public function testNonCleanVerdictWithoutEscalationIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $manifest = $this->manifest();
        $manifest['verdict'] = 'ACCEPT';
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-03]', $result['err']);
        self::assertStringContainsString('verdict ACCEPT requires a non-empty escalation.md', $result['err']);
    }

    public function testMissingVerdictIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $manifest = $this->manifest();
        $manifest['verdict'] = null;
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('no verdict recorded and no escalation.md', $result['err']);
    }

    public function testTooFewRoundsForTheProfileIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $manifest = $this->manifest();
        $manifest['rounds'] = [$manifest['rounds'][0]];
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('1 round(s) recorded, the full profile needs at least 2', $result['err']);
    }

    public function testScratchBufferInTheDiffIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->write('docs/proof_of_work/current/manifest.json', "{}\n");
        $this->git('add', '-f', 'docs/proof_of_work/current/manifest.json');
        $this->commit('leak the scratch buffer');

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-04]', $result['err']);
        self::assertStringContainsString('the scratch buffer leaked into the diff', $result['err']);
    }

    public function testReRolledReviewIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // A third review run inside the branch window that appears neither in
        // rounds[] nor in aborted[] — the loop was re-run until it said clean.
        $this->writeArtifact('cccc3333', 'review', time());

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-07]', $result['err']);
        self::assertStringContainsString('silent re-roll: cccc3333 (review)', $result['err']);
    }

    public function testARecordedReRollIsAccepted(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());
        $this->writeArtifact('cccc3333', 'review', time());

        $manifest = $this->manifest();
        $manifest['aborted'] = [['run_id' => 'cccc3333', 'reason' => 'crashed halfway, re-ran']];
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testFalsifiedManifestIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->checkWith([
            'CHECK_POW_LINT_CMD' => 'exit 0',
            'CHECK_POW_TEST_CMD' => 'exit 3',
        ], '--verify-reality');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-08]', $result['err']);
        self::assertStringContainsString('manifest falsified: test_exit is declared as 0 but recomputing test exited 3', $result['err']);
    }

    public function testFalsifiedCoverageIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $manifest = $this->manifest();
        $manifest['coverage'] = 91.0;
        $this->writeManifest($manifest);

        $result = $this->checkWith([
            'CHECK_POW_LINT_CMD' => 'exit 0',
            'CHECK_POW_TEST_CMD' => 'exit 0',
        ], '--verify-reality');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('manifest falsified: coverage is declared as 91.00%', $result['err']);
    }

    public function testProtectedPathOnANonProcessBranchIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->write('bin/pow.php', "<?php // weakened\n");
        $this->git('add', '-A');
        $this->commit('quietly relax the recorder');

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-10]', $result['err']);
        self::assertStringContainsString('protected path(s) bin/pow.php', $result['err']);
        self::assertStringContainsString('require a process/ branch', $result['err']);
    }

    public function testProtectedPathOnAProcessBranchNeedsAnApproval(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['reviews'] = [];
        $this->writeFixture($fixture);

        $this->git('switch', '-qc', 'process/issue-4242-sample-issue');
        $this->write('bin/pow.php', "<?php // rewritten in the open\n");
        $this->git('add', '-A');
        $this->commit('process: rewrite the recorder');

        $advisory = $this->check('--branch=process/issue-4242-sample-issue');
        self::assertSame(0, $advisory['code'], $advisory['err']);
        self::assertStringContainsString('carries no maintainer approval', $advisory['err']);

        $strict = $this->check('--strict', '--branch=process/issue-4242-sample-issue');
        self::assertSame(1, $strict['code']);
        self::assertStringContainsString('FAIL    [POW-10]', $strict['err']);
    }

    public function testProtectedPathIsCheckedEvenOnANonIssueBranch(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->git('switch', '-qc', 'chore/sneaky');
        $this->write('.github/workflows/tests.yaml', "name: nothing\n");
        $this->git('add', '-A');
        $this->commit('chore: disable CI');

        $result = $this->check('--branch=chore/sneaky');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-10]', $result['err']);
        self::assertStringContainsString('.github/workflows/tests.yaml', $result['err']);
    }

    public function testAnUnrelatedComposerJsonChangeIsNotAProtectedPath(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->write('composer.json', json_encode(
            ['name' => 'sandbox/app', 'description' => 'now with a description', 'scripts' => ['lint' => 'true']],
            JSON_PRETTY_PRINT,
        ) . "\n");
        $this->git('add', '-A');
        $this->commit('chore: describe the package');

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testChangingTheComposerScriptsBlockIsAProtectedPath(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->write('composer.json', json_encode(
            ['name' => 'sandbox/app', 'scripts' => ['lint' => 'echo skipped']],
            JSON_PRETTY_PRINT,
        ) . "\n");
        $this->git('add', '-A');
        $this->commit('chore: adjust the lint script');

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('composer.json (scripts)', $result['err']);
    }

    // ---------------------------------------------------------------------
    // Escape hatch
    // ---------------------------------------------------------------------

    public function testNoPowLabelSkipsTheChecksButShoutsAboutIt(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['labels'] = [['name' => 'no-pow']];
        $fixture['pr']['closingIssuesReferences'] = [];
        $this->writeFixture($fixture);
        $this->write('docs/process-changelog.md', "# Process changelog\n\n- #700 release PR, no proof of work.\n");

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('BYPASS', $result['err']);
        self::assertStringContainsString('checks 1-8 are skipped', $result['err']);
        self::assertStringNotContainsString('[POW-01]', $result['err'], 'the hatch skips the closing-issue check');
    }

    public function testNoPowLabelWithoutAnApprovalIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['labels'] = [['name' => 'no-pow']];
        $fixture['pr']['reviews'] = [];
        $this->writeFixture($fixture);
        $this->write('docs/process-changelog.md', "# Process changelog\n\n- #700 release PR.\n");

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-09]', $result['err']);
        self::assertStringContainsString('requires a maintainer approval', $result['err']);
    }

    public function testNoPowLabelUnrecordedInTheProcessChangelogIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['labels'] = [['name' => 'no-pow']];
        $this->writeFixture($fixture);
        $this->write('docs/process-changelog.md', "# Process changelog\n\n- nothing to see here.\n");

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-09]', $result['err']);
        self::assertStringContainsString('is not recorded in docs/process-changelog.md', $result['err']);
    }

    // ---------------------------------------------------------------------
    // The gate runs from master
    // ---------------------------------------------------------------------

    public function testAPullRequestModifyingTheGateIsValidatedWithTheMasterCopy(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // origin/master carries the real gate; the branch replaces it with one
        // that always passes. --self-check must run the master copy.
        self::assertSame(0, $this->git('switch', '-q', 'master')['code']);
        $this->write('bin/check-pow.php', "<?php\nfwrite(STDERR, \"MASTER COPY SPEAKING\\n\");\nexit(7);\n");
        $this->commit('add the gate to master');
        $this->git('update-ref', 'refs/remotes/origin/master', 'master');
        self::assertSame(0, $this->git('switch', '-q', self::BRANCH)['code']);
        self::assertSame(0, $this->git('merge', '-q', '--no-edit', 'master')['code']);

        $this->write('bin/check-pow.php', "<?php\nfwrite(STDERR, \"TAMPERED COPY SPEAKING\\n\");\nexit(0);\n");
        $this->git('add', '-A');
        $this->commit('process: "improve" the gate');

        $result = $this->check('--self-check', '--strict');

        self::assertSame(7, $result['code'], 'the exit code must come from the master copy');
        self::assertStringContainsString('MASTER COPY SPEAKING', $result['err']);
        self::assertStringNotContainsString('TAMPERED COPY SPEAKING', $result['err']);
        self::assertStringContainsString('running the origin/master copy', $result['err']);
    }

    public function testSelfCheckFallsBackLoudlyWhenMasterHasNoGateYet(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->check('--self-check', '--strict');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('origin/master has no bin/check-pow.php yet', $result['err']);
        self::assertStringContainsString('FALLING BACK to the in-tree copy', $result['err']);
    }

    // ---------------------------------------------------------------------
    // CLI
    // ---------------------------------------------------------------------

    public function testUnknownOptionIsAUsageError(): void
    {
        $this->buildHappyRepository();

        $result = $this->check('--nonsense');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('unknown option --nonsense', $result['err']);
    }

    public function testSkipEnvironmentVariableShortCircuits(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->checkWith(['CHECK_POW_SKIP' => '1'], '--strict');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('CHECK_POW_SKIP=1', $result['out']);
    }

    // ---------------------------------------------------------------------
    // Fixture repository
    // ---------------------------------------------------------------------

    /**
     * A repository in the state a finished, honest cycle leaves behind:
     * master, an issue branch, a committed proof of work whose ledger grew by
     * appending, two harness artifacts, and a clover file.
     */
    private function buildHappyRepository(): void
    {
        self::assertSame(0, $this->git('init', '-q')['code'], 'git is required for these tests');
        $this->git('symbolic-ref', 'HEAD', 'refs/heads/master');

        $this->write('.gitignore', "/docs/proof_of_work/current/*\n!/docs/proof_of_work/current/.gitkeep\n");
        $this->write('composer.json', json_encode(['name' => 'sandbox/app', 'scripts' => ['lint' => 'true']], JSON_PRETTY_PRINT) . "\n");
        $this->write('bin/pow.php', "<?php // recorder\n");
        $this->write('.github/workflows/tests.yaml', "name: Tests\n");
        $this->write('docs/proof_of_work/current/.gitkeep', '');
        $this->git('add', '-A');
        $this->commit('base');

        self::assertSame(0, $this->git('switch', '-qc', self::BRANCH)['code']);

        $this->write('src/Foo.php', "<?php\n");
        $this->git('add', '-A');
        $this->commit('fix: implement the change');

        $ledger = "# Findings ledger — issue #4242\n\n"
            . "| ID | round | file:line | description | severity | status | resolution |\n"
            . "| --- | --- | --- | --- | --- | --- | --- |\n"
            . "| F-01 | 1 | src/Foo.php:1 | first finding | high | open |  |\n";
        $this->write(self::POW_DIR . '/findings.md', $ledger);
        $this->writeManifest($this->baseManifest());
        $this->git('add', '-A');
        $this->commit('docs(pow): first ledger rows');

        $this->write(
            self::POW_DIR . '/findings.md',
            $ledger . "| F-01 | 2 | src/Foo.php:1 | first finding | high | fixed | patched |\n",
        );
        $this->git('add', '-A');
        $this->commit('docs(pow): proof of work for #4242');

        $this->writeArtifact('bbbb2222', 'coder', time());
        $this->writeArtifact('aaaa1111', 'review', time());

        $this->write('var/coverage.xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <coverage>
              <project>
                <file name="src/Foo.php">
                  <metrics statements="1000" coveredstatements="815"/>
                </file>
              </project>
            </coverage>

            XML);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseManifest(): array
    {
        return [
            'issue' => self::ISSUE,
            'slug' => self::SLUG,
            'branch' => self::BRANCH,
            'profile' => 'full',
            'round_cap' => 4,
            'created_at' => '2026-08-11T09:00:00Z',
            'rounds' => [
                [
                    'n' => 1,
                    'role' => 'coder',
                    'agent' => 'coder',
                    'run_id' => 'bbbb2222',
                    'comment_id' => 111,
                    'comment_sha256' => hash('sha256', self::ROUND_ONE_BODY),
                    'prev' => null,
                    'created_at' => '2026-08-11T10:00:00Z',
                ],
                [
                    'n' => 1,
                    'role' => 'review',
                    'agent' => 'review',
                    'run_id' => 'aaaa1111',
                    'comment_id' => 222,
                    'comment_sha256' => hash('sha256', self::ROUND_TWO_BODY),
                    'prev' => hash('sha256', self::ROUND_ONE_BODY),
                    'created_at' => '2026-08-11T10:05:00Z',
                ],
            ],
            'commits' => [],
            'files_changed' => ['src/Foo.php'],
            'lint_exit' => 0,
            'test_exit' => 0,
            'coverage' => 81.5,
            'findings' => ['total' => 1, 'round1' => 1, 'escaped' => 0, 'open' => 0],
            'gates_added' => [],
            'aborted' => [],
            'verdict' => 'CLEAN',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return [
            'pr' => [
                'number' => 700,
                'state' => 'OPEN',
                'isDraft' => false,
                'headRefName' => self::BRANCH,
                'labels' => [['name' => 'bug']],
                'closingIssuesReferences' => [['number' => self::ISSUE]],
                'reviews' => [
                    ['state' => 'APPROVED', 'authorAssociation' => 'OWNER', 'author' => ['login' => 'maintainer']],
                ],
            ],
            'comments' => $this->comments(),
        ];
    }

    /**
     * @return array<array-key, array<string, string|int>>
     */
    private function comments(): array
    {
        return [
            '111' => [
                'id' => 111,
                'body' => self::ROUND_ONE_BODY,
                'created_at' => '2026-08-11T10:00:00Z',
                'updated_at' => '2026-08-11T10:00:00Z',
            ],
            '222' => [
                'id' => 222,
                'body' => self::ROUND_TWO_BODY,
                'created_at' => '2026-08-11T10:05:00Z',
                'updated_at' => '2026-08-11T10:05:00Z',
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function check(string ...$args): array
    {
        return $this->checkWith([], ...$args);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{code: int, out: string, err: string}
     */
    private function checkWith(array $env, string ...$args): array
    {
        return $this->exec(array_merge([PHP_BINARY, $this->script], array_values($args)), $env);
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     *
     * @return array{code: int, out: string, err: string}
     */
    private function exec(array $cmd, array $env = []): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $fixture = $this->sandbox . '/gh-fixture.json';
        $base = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'CHECK_POW_ROOT' => $this->sandbox,
        ];

        if (is_file($fixture)) {
            $base['CHECK_POW_GH_FIXTURE'] = $fixture;
        }

        $process = proc_open($cmd, $descriptors, $pipes, $this->sandbox, array_merge($base, $env));

        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function git(string ...$args): array
    {
        return $this->exec(array_merge(
            ['git', '-c', 'user.email=pow@example.com', '-c', 'user.name=POW'],
            array_values($args),
        ));
    }

    private function commit(string $message): void
    {
        $this->git('add', '-A');
        $result = $this->git('commit', '-qm', $message);
        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function writeFixture(array $fixture): void
    {
        $json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);
        $this->write('gh-fixture.json', $json);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);
        $this->write(self::POW_DIR . '/manifest.json', $json . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $decoded = json_decode($this->read(self::POW_DIR . '/manifest.json'), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function writeArtifact(string $runId, string $agent, int $timestamp): void
    {
        $meta = json_encode([
            'runId' => $runId,
            'agent' => $agent,
            'model' => 'test-vendor/Test Model',
            'timestamp' => $timestamp * 1000,
        ], JSON_PRETTY_PRINT);
        self::assertIsString($meta);

        $this->write('.pi-subagents/artifacts/' . $runId . '_' . $agent . '_0_meta.json', $meta);
        $this->write('.pi-subagents/artifacts/' . $runId . '_' . $agent . '_0_output.md', "report\n");
    }

    private function path(string $relative): string
    {
        return $this->sandbox . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));
        self::assertIsString($contents);

        return $contents;
    }

    private function write(string $relative, string $contents): void
    {
        $file = $this->path($relative);
        $dir = \dirname($file);

        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0o775, true));
        }

        self::assertNotFalse(file_put_contents($file, $contents));
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
