<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\ProofOfWork;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testDroppingTheFirstRoundBreaksTheFirstRoundRule(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // Keeping only the later round leaves a first entry whose `prev` points
        // at a round that is no longer in the manifest.
        $manifest = $this->manifest();
        $manifest['rounds'] = [$manifest['rounds'][1]];
        $this->writeManifest($manifest);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('the first round must have prev=null', $result['err']);
    }

    /**
     * The realistic cheat is the other end: truncate the review that found
     * problems. The prev chain stays intact — round 1 legitimately has
     * prev=null — so only the round count catches it, and only if the profile
     * cannot be forged down along with it.
     */
    public function testDroppingTheLastRoundIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());
        $this->removeArtifact('aaaa1111', 'review');

        $manifest = $this->manifest();
        $manifest['rounds'] = [$manifest['rounds'][0]];
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-03]', $result['err']);
        self::assertStringContainsString('1 round(s) recorded, the full profile needs at least 2', $result['err']);
    }

    public function testDroppingTheLastRoundAndForgingTheProfileIsStillRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());
        $this->removeArtifact('aaaa1111', 'review');

        // `light` needs only one round — so the profile is the second thing an
        // orchestrator would edit. It is re-derived from the branch prefix and
        // the issue labels, never read from the manifest.
        $manifest = $this->manifest();
        $manifest['rounds'] = [$manifest['rounds'][0]];
        $manifest['profile'] = 'light';
        $this->writeManifest($manifest);

        $result = $this->check();

        self::assertSame(1, $result['code'], 'a forged profile is tampering, not incompleteness');
        self::assertStringContainsString('[POW-03]', $result['err']);
        self::assertStringContainsString('manifest declares profile "light"', $result['err']);
        self::assertStringContainsString('entitle it to "full"', $result['err']);
    }

    /**
     * A `docs/` prefix is entitled to `light` — but only when the issue is
     * known not to carry the `process` label. When `gh` cannot answer, the
     * strict choice wins; "we could not check" must never buy a smaller cycle.
     * (The branch enters the gate's scope through a protected path, which is
     * the only way a light-prefix branch is enforced at all today.)
     */
    public function testTheProfileFallsBackToFullWhenTheIssueLabelsCannotBeRead(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        unset($fixture['issues']);
        $this->writeFixture($fixture);

        $this->git('switch', '-qc', 'docs/issue-4242-sample-issue');
        $this->write('.github/workflows/tests.yaml', "name: Tests\n# tweaked\n");

        $manifest = $this->manifest();
        $manifest['branch'] = 'docs/issue-4242-sample-issue';
        $manifest['profile'] = 'light';
        $manifest['rounds'] = [$manifest['rounds'][0]];
        $this->writeManifest($manifest);
        $this->commit('docs: tweak CI');

        $result = $this->check('--branch=docs/issue-4242-sample-issue');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('could not be read — assuming the full profile', $result['err']);
        self::assertStringContainsString('entitle it to "full"', $result['err']);
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

    /**
     * GitHub's `created_at` has one-second resolution. Two rounds published
     * back to back (coder then review, with no mandated pause between them —
     * exactly what steps 3-4 do) can come back with an identical timestamp.
     * A real reviewer reproduced this against live GitHub: three comments
     * posted in parallel to a real PR all came back `created_at =
     * 2026-08-11T05:44:02Z`, with strictly increasing, insertion-order ids.
     * Requiring a strictly later `created_at` failed that honest cycle before
     * this test existed; the `comment_id` — also server-assigned and
     * monotonic — tie-breaks it instead.
     */
    public function testTwoRoundsSharingACreatedAtPassWhenTheCommentIdIncreases(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        // Round 1's comment_id is 111, round 2's is 222 — already increasing.
        // Only the timestamp collides.
        $fixture['comments']['222']['created_at'] = '2026-08-11T10:00:00Z';
        $fixture['comments']['222']['updated_at'] = '2026-08-11T10:00:00Z';
        $this->writeFixture($fixture);

        $manifest = $this->manifest();
        $manifest['rounds'][1]['created_at'] = '2026-08-11T10:00:00Z';
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringNotContainsString('backfilled', $result['err']);
    }

    /**
     * The tie-break is not a blanket pass for equal timestamps: it only
     * excuses the collision when the comment_id also increases. A round
     * recorded against an OLDER comment than the previous round, wearing the
     * same timestamp to look honest, is still backfilled.
     */
    public function testTwoRoundsSharingACreatedAtStillFailWhenTheCommentIdDoesNotIncrease(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['comments']['90'] = [
            'id' => 90,
            'body' => self::ROUND_TWO_BODY,
            'issue_url' => 'https://api.github.com/repos/o/r/issues/700',
            'created_at' => '2026-08-11T10:00:00Z',
            'updated_at' => '2026-08-11T10:00:00Z',
        ];
        $this->writeFixture($fixture);

        $manifest = $this->manifest();
        // Round 2 now points at comment 90 (lower than round 1's 111) but
        // claims the same created_at as round 1 — the id order contradicts
        // the "honest tie" story.
        $manifest['rounds'][1]['comment_id'] = 90;
        $manifest['rounds'][1]['created_at'] = '2026-08-11T10:00:00Z';
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
        self::assertStringContainsString('the ledger still has open findings: F-03', $result['err']);
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
        self::assertStringContainsString('no verdict recorded', $result['err']);
    }

    public function testTooFewRoundsForTheProfileIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // Dropping the review round would otherwise orphan its artifact and
        // trip POW-07 as well, leaving the exit code over-determined: this test
        // is about the round count and nothing else.
        $this->removeArtifact('aaaa1111', 'review');

        $manifest = $this->manifest();
        $manifest['rounds'] = [$manifest['rounds'][0]];
        $this->writeManifest($manifest);

        $result = $this->check('--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('1 round(s) recorded, the full profile needs at least 2', $result['err']);
        self::assertStringNotContainsString('[POW-07]', $result['err'], 'exit 1 must be caused by the round count alone');
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

    public function testProtectedPathOnAProcessBranchPassesWithoutAnApproval(): void
    {
        // POW-10 used to also require a maintainer approval submitted after
        // the newest protected-path commit. Dropped in #686 phase 5: this
        // repository has a single collaborator with write access and GitHub
        // refuses a self-approval, so the requirement was unsatisfiable, not
        // merely strict (docs/process-notices.md, N-13). What remains is the
        // `process/` branch prefix alone — no reviews on the PR at all.
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['reviews'] = [];
        $this->writeFixture($fixture);

        $this->onProcessBranch();

        $result = $this->check('--strict', '--branch=process/issue-4242-sample-issue');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('[POW-10]', $result['err']);
        self::assertStringContainsString('protected path(s) touched from a process/ branch', $result['err']);
    }

    public function testAnUntrackedProtectedFileIsSeenBeforeItIsCommitted(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $this->git('switch', '-qc', 'chore/sneaky');
        // Never committed, so `git diff base...HEAD` says nothing about it.
        $this->write('.github/workflows/release.yaml', "name: publish everything\n");

        $result = $this->check('--branch=chore/sneaky');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-10]', $result['err']);
        self::assertStringContainsString('.github/workflows/release.yaml', $result['err']);
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
        $this->write('docs/process-changelog.md', "# Process changelog\n\n- #700 release PR, no-pow, approved by @maintainer.\n");

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('BYPASS', $result['err']);
        self::assertStringContainsString('checks 1-8 are skipped', $result['err']);
        self::assertStringNotContainsString('[POW-01]', $result['err'], 'the hatch skips the closing-issue check');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unconvincingChangelogProvider(): iterable
    {
        yield 'a longer number that merely starts with it' => ["# Process changelog\n\n- #7001 no-pow for an unrelated release.\n"];
        yield 'a denial, which used to read as a record' => ["# Process changelog\n\n- this is not #700; it went through a full cycle.\n"];
        yield 'the number without the marker' => ["# Process changelog\n\n- #700 was merged on Tuesday.\n"];
        yield 'the marker without the number' => ["# Process changelog\n\n- a no-pow bypass was granted last year.\n"];
    }

    #[DataProvider('unconvincingChangelogProvider')]
    public function testABypassNeedsTheMarkerAndTheExactNumberOnOneLine(string $changelog): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['labels'] = [['name' => 'no-pow']];
        $this->writeFixture($fixture);
        $this->write('docs/process-changelog.md', $changelog);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-09]', $result['err']);
        self::assertStringContainsString('is not recorded in docs/process-changelog.md', $result['err']);
    }

    public function testAnUnrecordedNoPowLabelDoesNotSwitchOffChecks1To8(): void
    {
        // The bypass is not authorised by the label alone (there is no
        // approval concept to gate it on: this repository has one
        // collaborator with write access, and GitHub refuses a
        // self-approval — docs/process-notices.md, N-13). Until the
        // changelog records it, checks 1-8 run exactly as if the label were
        // absent, so a real defect (here: no closing issue) still surfaces
        // as its own finding instead of being silently switched off.
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['labels'] = [['name' => 'no-pow']];
        $fixture['pr']['closingIssuesReferences'] = [];
        $this->writeFixture($fixture);
        $this->write('docs/process-changelog.md', "# Process changelog\n\n- #700 release PR.\n");

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringNotContainsString('BYPASS', $result['err'], 'not recorded — the bypass must not activate');
        self::assertStringContainsString('[POW-09]', $result['err']);
        self::assertStringContainsString('is not recorded in docs/process-changelog.md', $result['err']);
        self::assertStringContainsString('[POW-01]', $result['err'], 'checks 1-8 ran normally and found the missing closing issue');
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
    // Replay: a whole proof of work lifted from another cycle
    // ---------------------------------------------------------------------

    public function testAProofOfWorkRenamedFromAnotherIssueIsRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['pr']['closingIssuesReferences'] = [['number' => 9001]];
        $this->writeFixture($fixture);

        // `git mv` the recorded directory of #4242 onto issue #9001 and open a
        // PR closing #9001. The manifest still says 4242, and the comments it
        // points at are real, so every hash and timestamp still checks out.
        $this->git('mv', self::POW_DIR, 'docs/proof_of_work/9001-other');
        $this->git('switch', '-qc', 'fix/issue-9001-other');
        $this->commit('fix: reuse someone else\'s proof of work');

        $result = $this->check('--strict', '--branch=fix/issue-9001-other');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-02]', $result['err']);
        self::assertStringContainsString('records issue #4242 but this pull request closes #9001', $result['err']);
        self::assertStringContainsString('records branch "fix/issue-4242-sample-issue"', $result['err']);
    }

    public function testRoundCommentsFromAnotherPullRequestAreRejected(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        // Real, unedited, correctly hashed comments — on somebody else's PR.
        $fixture['comments']['111']['issue_url'] = 'https://api.github.com/repos/o/r/issues/42';
        $fixture['comments']['222']['issue_url'] = 'https://api.github.com/repos/o/r/issues/42';
        $this->writeFixture($fixture);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-05]', $result['err']);
        self::assertStringContainsString('belongs to https://api.github.com/repos/o/r/issues/42, not to PR #700', $result['err']);
    }

    public function testACommentWithNoIssueUrlCannotBeBound(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        unset($fixture['comments']['111']['issue_url'], $fixture['comments']['222']['issue_url']);
        $this->writeFixture($fixture);

        $advisory = $this->check();
        self::assertSame(0, $advisory['code'], $advisory['err']);
        self::assertStringContainsString('carries no issue_url', $advisory['err']);

        $strict = $this->check('--strict');
        self::assertSame(1, $strict['code']);
    }

    public function testAManifestWithoutAPowVersionIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $manifest = $this->manifest();
        unset($manifest['pow_version']);
        $this->writeManifest($manifest);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('declares no pow_version', $result['err']);
    }

    public function testAManifestFromAFutureSchemaIsRejected(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $manifest = $this->manifest();
        $manifest['pow_version'] = 2;
        $this->writeManifest($manifest);

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('declares pow_version 2, this gate reads version 1', $result['err']);
    }

    // ---------------------------------------------------------------------
    // "Cannot determine" must never look like "nothing to see here"
    // ---------------------------------------------------------------------

    public function testAnUnreadableDiffIsNotAnEmptyDiff(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // A shallow clone: `git diff origin/master...HEAD` exits 128 with
        // "no merge base" and an empty stdout. Returning [] there used to turn
        // POW-04 and POW-10 off without a word.
        $this->write('bin/pow.php', "<?php // weakened\n");
        $this->write('docs/proof_of_work/current/manifest.json', "{}\n");
        $this->git('add', '-A', '-f');
        $this->commit('rewrite the recorder and leak the buffer');
        $this->makeUnrelatedBase();

        $advisory = $this->check();
        self::assertStringContainsString('[POW-00]', $advisory['err']);
        self::assertStringContainsString('the changed-file list is incomplete', $advisory['err']);

        $strict = $this->check('--strict');
        self::assertSame(1, $strict['code'], 'in --strict, "cannot determine" is a failure');
        self::assertStringContainsString('FAIL    [POW-00]', $strict['err']);
    }

    public function testASingleLedgerCommitReportsThatPow06DidNotRun(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // The documented flow commits findings.md once, at step 11.5. Squash the
        // two ledger commits into one and POW-06 has nothing to compare — which
        // it must say out loud rather than pass silently.
        self::assertSame(0, $this->git('reset', '-q', '--soft', 'HEAD~2')['code']);
        $this->commit('docs(pow): proof of work for #4242');

        $result = $this->check('--strict');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('[POW-06]', $result['err']);
        self::assertStringContainsString('the append-only comparison needs two, so it did not run', $result['err']);
    }

    public function testAMalformedLedgerRowIsAViolationNotASilentSkip(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // Six cells instead of seven. The row parses as nothing, so the open
        // finding it carries used to be invisible to POW-03.
        $this->write(
            self::POW_DIR . '/findings.md',
            $this->read(self::POW_DIR . '/findings.md')
                . "| F-09 | 2 | src/Foo.php:9 | still open | high | open |\n",
        );
        $this->git('add', '-A');
        $this->commit('docs(pow): one more finding');

        $result = $this->check();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-03]', $result['err']);
        self::assertStringContainsString('findings.md is malformed', $result['err']);
        self::assertStringContainsString('has 6 cells, not 7', $result['err']);
    }

    // ---------------------------------------------------------------------
    // CLI and the environment
    // ---------------------------------------------------------------------

    public function testUnknownOptionIsAUsageError(): void
    {
        $this->buildHappyRepository();

        $result = $this->check('--nonsense');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('unknown option --nonsense', $result['err']);
    }

    /**
     * A reused branch name leaves closed pull requests behind. Resolving one of
     * those would make the gate validate that PR's comment chain instead of the
     * one under review, so the open PR is asked for first.
     */
    public function testAnOpenPullRequestWinsOverAClosedOneOnTheSameBranch(): void
    {
        $this->buildHappyRepository();
        $this->fakeGh(
            ['open' => '[{"number":700}]', 'all' => '[{"number":123}]'],
            (string) json_encode(['number' => 700, 'closingIssuesReferences' => [['number' => self::ISSUE]], 'labels' => [], 'reviews' => []]),
        );

        $this->check();

        self::assertStringContainsString('--state open', $this->ghLog());
        self::assertStringNotContainsString('--state all', $this->ghLog(), 'the open lookup answered, so `all` is never asked');
        self::assertStringContainsString('pr view 700', $this->ghLog());
    }

    public function testAClosedPullRequestIsStillFoundWhenNoOpenOneExists(): void
    {
        $this->buildHappyRepository();
        $this->fakeGh(
            ['open' => '[]', 'all' => '[{"number":123}]'],
            (string) json_encode(['number' => 123, 'closingIssuesReferences' => [['number' => self::ISSUE]], 'labels' => [], 'reviews' => []]),
        );

        $this->check();

        self::assertStringContainsString('--state open', $this->ghLog());
        self::assertStringContainsString('--state all', $this->ghLog());
        self::assertStringContainsString('pr view 123', $this->ghLog());
    }

    /**
     * CI materialises the gate into $RUNNER_TEMP so a pull request cannot
     * supply its own. The script's parent directory is then not the checkout,
     * and resolving the root from it made the gate see no git repository, no
     * proof of work and no pull request — POW-00/POW-02 whatever the branch had
     * recorded. The working tree of the current directory wins instead.
     */
    public function testTheGateMaterialisedOutsideTheRepositoryStillFindsIt(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $gate = $this->sandbox . '/elsewhere/gate';
        self::assertTrue(mkdir($gate, 0o775, true));

        foreach (['check-pow.php', 'pow-common.php'] as $file) {
            self::assertTrue(copy(\dirname(__DIR__, 2) . '/bin/' . $file, $gate . '/' . $file));
        }

        // No CHECK_POW_ROOT: the gate has to work it out from the cwd.
        $result = $this->exec([PHP_BINARY, $gate . '/check-pow.php', '--strict'], [
            'CHECK_POW_ROOT' => '',
            'CHECK_POW_GH_FIXTURE' => $this->sandbox . '/gh-fixture.json',
        ]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('running from outside the repository', $result['err']);
        self::assertStringContainsString('proof of work for issue #4242 verified', $result['err']);
    }

    public function testStrictAndAdvisoryAreContradictory(): void
    {
        $this->buildHappyRepository();

        $result = $this->check('--strict', '--advisory');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('contradictory', $result['err']);
    }

    public function testAdvisoryModeReportsTamperingButNeverFails(): void
    {
        $this->buildHappyRepository();
        $fixture = $this->fixture();
        $fixture['comments']['222']['body'] = "---\nround: 1\n---\n\nall clean, honest\n";
        $this->writeFixture($fixture);

        $blocking = $this->check();
        self::assertSame(1, $blocking['code'], 'the default mode still fails on evidence of tampering');

        $advisory = $this->check('--advisory');

        self::assertSame(0, $advisory['code'], 'composer lint must never go red because of the gate');
        self::assertStringContainsString('WARN    [POW-05]', $advisory['err']);
        self::assertStringContainsString('report-only mode', $advisory['out']);
    }

    public function testABaseBranchIsSkippedInsteadOfBeingGatedAgainstItself(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        // master ahead of a stale origin/master: every protected file in the
        // repository reads as freshly touched from a non-process/ branch.
        self::assertSame(0, $this->git('switch', '-q', 'master')['code']);
        $this->git('update-ref', 'refs/remotes/origin/master', 'master');
        $this->write('bin/pow.php', "<?php // an ordinary commit on master\n");
        $this->commit('chore: touch the recorder on master');

        $result = $this->check('--branch=master');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('is a base branch', $result['out']);
        self::assertStringNotContainsString('[POW-10]', $result['err']);
    }

    public function testSkipEnvironmentVariableShortCircuitsOutsideStrict(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->checkWith(['CHECK_POW_SKIP' => '1']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('CHECK_POW_SKIP=1', $result['out']);
    }

    public function testSkipEnvironmentVariableCannotSwitchOffTheStrictGate(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->checkWith(['CHECK_POW_SKIP' => '1'], '--strict');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-11]', $result['err']);
        self::assertStringContainsString('set but ignored under --strict', $result['err']);
        self::assertStringNotContainsString('skipped (CHECK_POW_SKIP=1)', $result['out']);
    }

    public function testTheGhFixtureIsIgnoredOnARunner(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $result = $this->checkWith(['GITHUB_ACTIONS' => 'true', 'CI' => 'true']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('[POW-11]', $result['err']);
        self::assertStringContainsString('ignored on a CI runner', $result['err']);
        // With the fixture ignored there is no `gh` in the sandbox PATH either,
        // so the pull request becomes unresolvable rather than fabricated.
        self::assertStringContainsString('[POW-00]', $result['err']);
    }

    /**
     * `--verify-reality` runs `composer lint` and `composer test:coverage`, the
     * two commands most likely to fill a pipe buffer. A gate that drains stdout
     * to EOF before touching stderr hangs here forever instead of failing —
     * in CI that means the six-hour job timeout, not a red build.
     */
    public function testAVerifyRealityChildFloodingStderrDoesNotDeadlock(): void
    {
        $this->buildHappyRepository();
        $this->writeFixture($this->fixture());

        $flood = 'php -r \'$c = str_repeat("x", 1024); for ($i = 0; $i < 512; $i++) { fwrite(STDERR, $c); } exit(0);\'';

        $result = $this->checkWith([
            'CHECK_POW_LINT_CMD' => $flood,
            'CHECK_POW_TEST_CMD' => 'exit 0',
        ], '--verify-reality');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('lint_exit matches the recomputed value (0)', $result['err']);
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
            'pow_version' => 1,
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
                    [
                        'state' => 'APPROVED',
                        'authorAssociation' => 'OWNER',
                        'author' => ['login' => 'maintainer'],
                        // Later than any commit the sandbox can produce, so the
                        // staleness rule only fires where a test asks for it.
                        'submittedAt' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ],
            'comments' => $this->comments(),
            'issues' => [
                (string) self::ISSUE => ['labels' => [['name' => 'bug']]],
            ],
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
                'issue_url' => 'https://api.github.com/repos/o/r/issues/700',
                'created_at' => '2026-08-11T10:00:00Z',
                'updated_at' => '2026-08-11T10:00:00Z',
            ],
            '222' => [
                'id' => 222,
                'body' => self::ROUND_TWO_BODY,
                'issue_url' => 'https://api.github.com/repos/o/r/issues/700',
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
        $fakeBin = $this->sandbox . '/fakebin';
        $base = [
            'PATH' => (is_dir($fakeBin) ? $fakeBin . ':' : '') . getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'CHECK_POW_ROOT' => $this->sandbox,
        ];

        if (is_file($fixture)) {
            $base['CHECK_POW_GH_FIXTURE'] = $fixture;
        }

        $process = proc_open($cmd, $descriptors, $pipes, $this->sandbox, array_merge($base, $env));

        self::assertIsResource($process);

        // Both pipes together: the child can outrun a 64 KB buffer on either.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buffers = ['', ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 30) === false) {
                break;
            }

            foreach ($open as $key => $stream) {
                if (!\in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = fread($stream, 65536);

                if ($chunk !== false && $chunk !== '') {
                    $buffers[$key - 1] .= $chunk;

                    continue;
                }

                if (feof($stream)) {
                    unset($open[$key]);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $buffers[0], 'err' => $buffers[1]];
    }

    /**
     * Installs a stub `gh` first on the child PATH and records every
     * invocation, so the real `gh pr list` / `gh pr view` path can be driven
     * without the JSON fixture that short-circuits it.
     *
     * @param array<string, string> $prByState `gh pr list --state <state>` payloads
     */
    private function fakeGh(array $prByState, string $prView): void
    {
        $dir = $this->sandbox . '/fakebin';

        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0o775, true));
        }

        $config = json_encode(['list' => $prByState, 'view' => $prView], JSON_UNESCAPED_SLASHES);
        self::assertIsString($config);
        $this->write('fakegh.json', $config);

        $stub = '#!' . PHP_BINARY . "\n" . <<<'PHP'
            <?php

            $args = array_slice($argv, 1);
            $root = (string) getenv('CHECK_POW_ROOT');
            file_put_contents($root . '/fakegh.log', implode(' ', $args) . "\n", FILE_APPEND);
            $config = json_decode((string) file_get_contents($root . '/fakegh.json'), true);

            if (($args[0] ?? '') === '--version' || (($args[0] ?? '') === 'auth' && ($args[1] ?? '') === 'status')) {
                exit(0);
            }

            if (($args[0] ?? '') === 'pr' && ($args[1] ?? '') === 'list') {
                $state = 'all';

                foreach ($args as $i => $arg) {
                    if ($arg === '--state') {
                        $state = (string) ($args[$i + 1] ?? 'all');
                    }
                }

                echo (string) ($config['list'][$state] ?? '[]');
                exit(0);
            }

            if (($args[0] ?? '') === 'pr' && ($args[1] ?? '') === 'view') {
                echo (string) ($config['view'] ?? '{}');
                exit(0);
            }

            fwrite(STDERR, "fake gh: unsupported: " . implode(' ', $args) . "\n");
            exit(1);
            PHP;

        self::assertNotFalse(file_put_contents($dir . '/gh', $stub));
        self::assertTrue(chmod($dir . '/gh', 0o755));
    }

    private function ghLog(): string
    {
        $log = $this->path('fakegh.log');

        return is_file($log) ? (string) file_get_contents($log) : '';
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

    /**
     * Switches to the process/ branch and rewrites a protected file there, the
     * shape every POW-10 test needs.
     */
    private function onProcessBranch(): void
    {
        $this->git('switch', '-qc', 'process/issue-4242-sample-issue');
        $this->write('bin/pow.php', "<?php // rewritten in the open\n");
        $manifest = $this->manifest();
        $manifest['branch'] = 'process/issue-4242-sample-issue';
        $this->writeManifest($manifest);
        $this->git('add', '-A');
        $this->commit('process: rewrite the recorder');
    }

    /**
     * Points origin/master at an unrelated root commit, which is what
     * `git diff origin/master...HEAD` calls "no merge base" — the shallow-clone
     * failure mode, reproducible without a shallow clone.
     */
    private function makeUnrelatedBase(): void
    {
        self::assertSame(0, $this->git('switch', '-q', '--orphan', 'unrelated')['code']);
        $this->write('unrelated.txt', "no shared history\n");
        $this->commit('unrelated root');
        $this->git('update-ref', 'refs/remotes/origin/master', 'unrelated');
        self::assertSame(0, $this->git('switch', '-q', self::BRANCH)['code']);
        $this->git('branch', '-D', 'master');
    }

    private function removeArtifact(string $runId, string $agent): void
    {
        foreach (['_0_meta.json', '_0_output.md'] as $suffix) {
            $file = $this->path('.pi-subagents/artifacts/' . $runId . '_' . $agent . $suffix);

            if (is_file($file)) {
                self::assertTrue(unlink($file));
            }
        }
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
