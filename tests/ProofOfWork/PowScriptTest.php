<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\ProofOfWork;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/pow.php` as a subprocess inside a throw-away sandbox.
 *
 * The script operates on the repository pointed at by `POW_ROOT` and makes no
 * `gh` call at all when `POW_NO_GH=1`, so these tests never touch the real
 * `docs/proof_of_work/` and never reach GitHub.
 *
 * @coversNothing
 */
final class PowScriptTest extends TestCase
{
    private const ISSUE = 4242;
    private const SLUG = 'sample-issue';
    private const BRANCH = 'feat/issue-4242-sample-issue';

    /** `feat/` mandates the full profile, so a light cycle needs a light prefix. */
    private const LIGHT_BRANCH = 'docs/issue-4242-sample-issue';

    private string $sandbox = '';

    private string $script = '';

    /** Non-null once a stub `gh` is on PATH; then POW_NO_GH is NOT set. */
    private ?string $ghMode = null;

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__, 2) . '/bin/pow.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/pow-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox . '/.pi-subagents/artifacts', 0o775, true));
        $this->sandbox = $sandbox;

        foreach ((array) glob(__DIR__ . '/Fixtures/*') as $fixture) {
            self::assertIsString($fixture);
            self::assertTrue(copy($fixture, $sandbox . '/.pi-subagents/artifacts/' . basename($fixture)));
        }

        $this->initGitRepository();
    }

    protected function tearDown(): void
    {
        $this->ghMode = null;

        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }
    }

    public function testStartCreatesTheSkeleton(): void
    {
        $result = $this->pow('--start', '--issue=' . self::ISSUE, '--slug=' . self::SLUG);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertFileExists($this->path('docs/proof_of_work/current/manifest.json'));
        self::assertFileExists($this->path('docs/proof_of_work/current/findings.md'));

        $manifest = $this->manifest('docs/proof_of_work/current/manifest.json');
        self::assertSame(1, $manifest['pow_version'], 'the schema version pins the shape for consumers');
        self::assertSame('pow_version', array_key_first($manifest));
        self::assertSame(self::ISSUE, $manifest['issue']);
        self::assertSame(self::SLUG, $manifest['slug']);
        self::assertSame(self::BRANCH, $manifest['branch'], 'the branch defaults to the checked-out one');
        self::assertSame('full', $manifest['profile'], 'a feat/ branch selects the full profile');
        self::assertSame(4, $manifest['round_cap']);
        self::assertSame([], $manifest['rounds']);
        self::assertNull($manifest['verdict']);
        self::assertNull($manifest['lint_exit']);
        self::assertSame(['total' => 0, 'round1' => 0, 'escaped' => 0, 'open' => 0], $manifest['findings']);

        $ledger = $this->read('docs/proof_of_work/current/findings.md');
        self::assertStringContainsString('# Findings ledger — issue #' . self::ISSUE, $ledger);
        self::assertStringContainsString(
            '| ID | round | file:line | description | severity | status | resolution |',
            $ledger,
        );
        self::assertStringContainsString('Append-only.', $ledger);
    }

    public function testStartArchivesADirtyCurrentInsteadOfDeletingIt(): void
    {
        $this->startCycle();
        $this->write('docs/proof_of_work/current/escalation.md', "F-01 stays open on purpose.\n");

        $result = $this->pow('--start', '--issue=4343', '--slug=other-issue');
        self::assertSame(0, $result['code'], $result['err']);

        $archive = $this->onlyArchive();
        self::assertFileExists($archive . '/manifest.json');
        self::assertFileExists($archive . '/findings.md');
        self::assertFileExists($archive . '/escalation.md');

        $archived = json_decode((string) file_get_contents($archive . '/manifest.json'), true);
        self::assertIsArray($archived);
        self::assertSame(self::ISSUE, $archived['issue'] ?? null);

        $manifest = $this->manifest('docs/proof_of_work/current/manifest.json');
        self::assertSame(4343, $manifest['issue']);
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/current/escalation.md'));
    }

    public function testRoundRefusesAnUnknownRunId(): void
    {
        $this->startCycle();

        $result = $this->pow('--round=1', '--role=review', '--run=deadbeef', '--dry-run');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('pow: unknown run_id deadbeef', $result['err']);
    }

    public function testRoundDryRunInjectsFrontMatterAndKeepsTheArtifactVerbatim(): void
    {
        $this->startCycle();

        $result = $this->pow('--round=2', '--role=review', '--run=aaaa1111', '--dry-run');
        self::assertSame(0, $result['code'], $result['err']);

        $artifact = (string) file_get_contents(__DIR__ . '/Fixtures/aaaa1111_review_0_output.md');
        self::assertStringContainsString($artifact, $result['out'], 'the artifact body must be published verbatim');
        self::assertStringStartsWith("---\n", $result['out']);

        $frontMatter = substr($result['out'], 0, strpos($result['out'], "\n---\n\n") ?: 0);
        self::assertStringContainsString('round: 2', $frontMatter);
        self::assertStringContainsString('role: "review"', $frontMatter);
        self::assertStringContainsString('agent: "review"', $frontMatter);
        self::assertStringContainsString('run_id: "aaaa1111"', $frontMatter);
        self::assertStringContainsString('model: "test-vendor/Test Model:high"', $frontMatter);
        self::assertStringContainsString('issue: ' . self::ISSUE, $frontMatter);
        self::assertStringContainsString('branch: "' . self::BRANCH . '"', $frontMatter);
        self::assertStringContainsString('generated_by: "bin/pow.php"', $frontMatter);

        $manifest = $this->manifest('docs/proof_of_work/current/manifest.json');
        self::assertSame([], $manifest['rounds'], 'a dry run records nothing');
    }

    public function testRoundDerivesTheAgentFromTheArtifactNotTheCaller(): void
    {
        $this->startCycle();

        $result = $this->pow('--round=1', '--role=coder', '--run=bbbb2222', '--dry-run');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('agent: "coder"', $result['out']);
        self::assertStringContainsString('role: "coder"', $result['out']);
    }

    public function testRoundCapIsEnforcedForTheFullProfile(): void
    {
        $this->startCycle();

        self::assertSame(0, $this->pow('--round=4', '--role=review', '--run=aaaa1111', '--dry-run')['code']);

        $result = $this->pow('--round=5', '--role=review', '--run=aaaa1111', '--dry-run');
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('exceeds the full profile cap of 4', $result['err']);
        self::assertStringContainsString('there is no round 5', $result['err']);
        self::assertStringContainsString('oracle', $result['err']);
        self::assertStringContainsString('escalation.md', $result['err']);
    }

    public function testRoundCapIsEnforcedForTheLightProfile(): void
    {
        $this->startCycle('light');

        self::assertSame(0, $this->pow('--round=2', '--role=review', '--run=aaaa1111', '--dry-run')['code']);

        $result = $this->pow('--round=3', '--role=review', '--run=aaaa1111', '--dry-run');
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('exceeds the light profile cap of 2', $result['err']);
        self::assertStringContainsString('there is no round 3', $result['err']);
        self::assertStringContainsString('escalation.md', $result['err']);
    }

    public function testAcceptIsRejectedWhileOpenFindingsAreUnjustified(): void
    {
        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');
        $this->addFinding('F-02', 2, 'low');

        $noEscalation = $this->pow('--verdict=ACCEPT');
        self::assertSame(1, $noEscalation['code']);
        self::assertStringContainsString('requires a non-empty', $noEscalation['err']);

        $this->write('docs/proof_of_work/current/escalation.md', "Oracle verdict: ACCEPT.\nF-01 is a doc nit.\n");

        $unjustified = $this->pow('--verdict=ACCEPT');
        self::assertSame(1, $unjustified['code']);
        self::assertStringContainsString('ACCEPT with unjustified findings: F-02', $unjustified['err']);
        self::assertStringNotContainsString('F-01', $unjustified['err']);
        self::assertNull($this->manifest('docs/proof_of_work/current/manifest.json')['verdict']);

        $this->write(
            'docs/proof_of_work/current/escalation.md',
            "Oracle verdict: ACCEPT.\nF-01 is a doc nit.\nF-02 is cosmetic and tracked separately.\n",
        );

        $accepted = $this->pow('--verdict=ACCEPT');
        self::assertSame(0, $accepted['code'], $accepted['err']);
        self::assertSame('ACCEPT', $this->manifest('docs/proof_of_work/current/manifest.json')['verdict']);
    }

    public function testCleanVerdictNeedsNoEscalation(): void
    {
        $this->startCycle();

        $result = $this->pow('--verdict=CLEAN');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertSame('CLEAN', $this->manifest('docs/proof_of_work/current/manifest.json')['verdict']);
    }

    public function testWontfixWithoutACitationIsRejected(): void
    {
        $this->startCycle();
        $this->addFinding('F-01', 1, 'low');

        $rejected = $this->pow('--resolve', '--id=F-01', '--round=2', '--status=wontfix', '--resolution=not worth it');
        self::assertSame(1, $rejected['code']);
        self::assertStringContainsString('must cite decisions.md#<anchor> or escalation.md', $rejected['err']);

        $accepted = $this->pow(
            '--resolve',
            '--id=F-01',
            '--round=2',
            '--status=wontfix',
            '--resolution=by design, see docs/helpers/decisions.md#large-responses-are-sent-in-a-single-write',
        );
        self::assertSame(0, $accepted['code'], $accepted['err']);
        self::assertStringContainsString('| F-01 | 2 |', $this->read('docs/proof_of_work/current/findings.md'));
    }

    public function testLedgerIsAppendOnly(): void
    {
        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');

        $before = $this->read('docs/proof_of_work/current/findings.md');

        $result = $this->pow('--resolve', '--id=F-01', '--round=2', '--status=fixed', '--resolution=patched in abc1234');
        self::assertSame(0, $result['code'], $result['err']);

        $after = $this->read('docs/proof_of_work/current/findings.md');
        self::assertTrue(
            str_starts_with($after, $before),
            'the previous ledger content must stay a prefix of the new one',
        );
        self::assertSame(
            substr_count($before, "\n| F-") + 1,
            substr_count($after, "\n| F-"),
            'a resolve appends exactly one new row',
        );
        self::assertStringContainsString('| F-01 | 1 |', $after, 'the original row is never rewritten');
        self::assertStringContainsString('| open |', $after);
        self::assertStringContainsString('| fixed | patched in abc1234 |', $after);

        $manifest = $this->manifest('docs/proof_of_work/current/manifest.json');
        self::assertSame(['total' => 1, 'round1' => 1, 'escaped' => 0, 'open' => 0], $manifest['findings']);
    }

    public function testFinishMovesTheFilesAndDerivesEscapedFindings(): void
    {
        $this->startCycle();
        $this->seedRounds();

        $this->addFinding('F-01', 1, 'high');
        $this->addFinding('F-02', 3, 'medium');
        $this->addFinding('F-03', 1, 'nit');

        self::assertSame(0, $this->pow('--resolve', '--id=F-01', '--round=2', '--status=fixed', '--resolution=fixed')['code']);
        self::assertSame(0, $this->pow('--resolve', '--id=F-02', '--round=3', '--status=gated', '--resolution=regression test added')['code']);
        self::assertSame(0, $this->pow('--resolve', '--id=F-03', '--round=3', '--status=fixed', '--resolution=fixed')['code']);
        self::assertSame(0, $this->pow('--set', 'lint_exit=0', '--set', 'test_exit=0')['code']);
        self::assertSame(0, $this->pow('--set', 'coverage=81.5', '--gate=regression test for the escaped finding')['code']);
        self::assertSame(0, $this->pow('--verdict=CLEAN')['code']);

        $result = $this->pow('--finish');
        self::assertSame(0, $result['code'], $result['err']);

        $target = 'docs/proof_of_work/' . sprintf('%04d', self::ISSUE) . '-' . self::SLUG;
        self::assertFileExists($this->path($target . '/manifest.json'));
        self::assertFileExists($this->path($target . '/findings.md'));
        self::assertFileDoesNotExist($this->path($target . '/escalation.md'));
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/current/manifest.json'));
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/current/findings.md'));
        self::assertFileExists($this->path('docs/proof_of_work/current/.gitkeep'));

        $manifest = $this->manifest($target . '/manifest.json');
        self::assertSame(
            ['total' => 3, 'round1' => 2, 'escaped' => 1, 'open' => 0],
            $manifest['findings'],
            'escaped counts the IDs first seen in round 2 or later',
        );
        self::assertSame(0, $manifest['lint_exit']);
        self::assertSame(0, $manifest['test_exit']);
        self::assertSame(81.5, $manifest['coverage']);
        self::assertSame(['regression test for the escaped finding'], $manifest['gates_added']);
        self::assertSame('CLEAN', $manifest['verdict']);

        $commits = $manifest['commits'];
        self::assertIsArray($commits);
        self::assertCount(1, $commits, 'commits are recomputed from git, not declared');
        self::assertSame(['worked-on.txt'], $manifest['files_changed']);
    }

    public function testFinishRefusesAnIncompleteManifest(): void
    {
        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');

        $result = $this->pow('--finish');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('manifest is incomplete for the full profile', $result['err']);
        self::assertStringContainsString('no verdict recorded', $result['err']);
        self::assertStringContainsString('lint_exit is not set', $result['err']);
        self::assertStringContainsString('the full profile needs at least 2', $result['err']);
        self::assertFileExists($this->path('docs/proof_of_work/current/manifest.json'));
    }

    public function testAbortArchivesTheCycle(): void
    {
        $this->startCycle();

        $result = $this->pow('--abort', '--reason=wrong issue picked');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/current/manifest.json'));
        self::assertFileExists($this->path('docs/proof_of_work/current/.gitkeep'));

        $archive = $this->onlyArchive();
        self::assertStringContainsString(
            'wrong issue picked',
            (string) file_get_contents($archive . '/abort-reason.txt'),
        );
    }

    public function testStatusSummarisesTheCycle(): void
    {
        $this->startCycle('light');
        $this->addFinding('F-01', 1, 'high');

        $result = $this->pow('--status');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('issue #' . self::ISSUE, $result['out']);
        self::assertStringContainsString('light (cap 2)', $result['out']);
        self::assertStringContainsString('1 open', $result['out']);
        self::assertStringContainsString('open ids: F-01', $result['out']);
    }

    public function testUnknownOptionIsAUsageError(): void
    {
        $result = $this->pow('--nonsense');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('unknown option --nonsense', $result['err']);
    }

    public function testCommandsAreMutuallyExclusive(): void
    {
        $result = $this->pow('--start', '--finish');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('mutually exclusive', $result['err']);
    }

    // ---------------------------------------------------------------------
    // Publishing a round for real (stub `gh`, no production hook)
    // ---------------------------------------------------------------------

    public function testRoundChainsThePublishedCommentsAndTakesTheServerTimestamp(): void
    {
        $this->startCycle();
        $this->fakeGh();

        $preview = $this->pow('--round=1', '--role=coder', '--run=bbbb2222', '--dry-run');
        self::assertSame(0, $preview['code'], $preview['err']);
        self::assertSame(1, preg_match('/sha256 ([0-9a-f]{64})/', $preview['err'], $matches));
        $previewSha = $matches[1] ?? '';

        $first = $this->pow('--round=1', '--role=coder', '--run=bbbb2222');
        self::assertSame(0, $first['code'], $first['err']);
        self::assertStringContainsString('as comment 101 on PR #7', $first['out']);

        $second = $this->pow('--round=1', '--role=review', '--run=aaaa1111');
        self::assertSame(0, $second['code'], $second['err']);

        $rounds = $this->rounds();
        self::assertCount(2, $rounds);

        self::assertNull($rounds[0]['prev'], 'the first round starts the chain');
        self::assertSame($previewSha, $rounds[0]['comment_sha256'], 'the recorded sha is the one --dry-run previews');
        self::assertSame($rounds[0]['comment_sha256'], $rounds[1]['prev'], 'every later round links to its predecessor');
        self::assertNotSame($rounds[0]['comment_sha256'], $rounds[1]['comment_sha256']);

        self::assertSame(101, $rounds[0]['comment_id']);
        self::assertSame(102, $rounds[1]['comment_id']);
        self::assertSame('2019-05-01T01:00:00Z', $rounds[0]['created_at'], 'created_at is the API value');
        self::assertSame('2019-05-02T02:00:00Z', $rounds[1]['created_at']);
        // manifest.created_at IS a local-clock stamp, written by --start in this
        // very run: a round timestamp equal to it would mean the clock, not the API.
        self::assertNotSame(
            $this->manifest('docs/proof_of_work/current/manifest.json')['created_at'],
            $rounds[0]['created_at'],
            'created_at is server-assigned, never taken from the local clock',
        );

        self::assertStringContainsString(
            'api --method POST repos/:owner/:repo/issues/7/comments --input',
            $this->ghLog(),
            'the body is posted once and the response is parsed, so no failure can orphan a comment',
        );
    }

    public function testRoundRefusesToPublishTheSameRunTwice(): void
    {
        $this->startCycle();
        $this->fakeGh();

        self::assertSame(0, $this->pow('--round=1', '--role=coder', '--run=bbbb2222')['code']);

        $again = $this->pow('--round=2', '--role=review', '--run=bbbb2222');
        self::assertSame(1, $again['code']);
        self::assertStringContainsString('run bbbb2222 is already recorded as round 1', $again['err']);
        self::assertCount(1, $this->rounds(), 'the rejected round is neither recorded nor published');
        self::assertSame(1, substr_count($this->ghLog(), '--method POST'));
    }

    public function testRoundRefusesToGoBackwardsForTheSameRole(): void
    {
        $this->startCycle();
        $this->fakeGh();

        self::assertSame(0, $this->pow('--round=2', '--role=review', '--run=aaaa1111')['code']);

        $backwards = $this->pow('--round=1', '--role=review', '--run=bbbb2222');
        self::assertSame(1, $backwards['code']);
        self::assertStringContainsString('lower than the highest recorded review round (2)', $backwards['err']);
        self::assertCount(1, $this->rounds());
    }

    public function testRoundRefusesADuplicateCommentId(): void
    {
        $this->startCycle();
        $this->fakeGh('same-comment-id');

        self::assertSame(0, $this->pow('--round=1', '--role=coder', '--run=bbbb2222')['code']);

        $duplicate = $this->pow('--round=1', '--role=review', '--run=aaaa1111');
        self::assertSame(1, $duplicate['code']);
        self::assertStringContainsString('comment 999 is already recorded', $duplicate['err']);
        self::assertCount(1, $this->rounds());
    }

    public function testRoundRecordsNothingWhenPublishingFails(): void
    {
        $this->startCycle();
        $this->fakeGh('post-fails');

        $result = $this->pow('--round=1', '--role=coder', '--run=bbbb2222');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('nothing was posted', $result['err']);
        self::assertStringContainsString('HTTP 502', $result['err']);
        self::assertSame([], $this->rounds());
    }

    public function testRoundFailsWhenGitHubStoresADifferentBody(): void
    {
        $this->startCycle();
        $this->fakeGh('normalises-newlines');

        $result = $this->pow('--round=1', '--role=coder', '--run=bbbb2222');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('GitHub stored a different body', $result['err']);
        self::assertStringContainsString('Comment 101', $result['err'], 'the operator is told which comment to delete');
        self::assertStringContainsString('#issuecomment-101', $result['err']);
        self::assertSame([], $this->rounds(), 'an unverifiable comment is never recorded');
    }

    public function testAChildFillingTheStderrBufferDoesNotDeadlock(): void
    {
        $this->startCycle();
        $this->fakeGh('chatty-stderr');

        $result = $this->pow('--round=1', '--role=coder', '--run=bbbb2222');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertCount(1, $this->rounds());
    }

    public function testRoundFailsWithoutAPullRequest(): void
    {
        $this->startCycle();
        $this->fakeGh('no-pr');

        $result = $this->pow('--round=1', '--role=coder', '--run=bbbb2222');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('no pull request found', $result['err']);
        self::assertSame([], $this->rounds());
    }

    // ---------------------------------------------------------------------
    // Input validation
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidRunIdProvider(): iterable
    {
        yield 'glob' => ['*'];
        yield 'traversal' => ['../../forged/zzz9'];
        yield 'space' => ['aa bb'];
        yield 'leading dash' => ['-aaaa1111'];
    }

    #[DataProvider('invalidRunIdProvider')]
    public function testRunIdMustBeAPlainToken(string $runId): void
    {
        $this->startCycle();

        $result = $this->pow('--round=1', '--role=review', '--run=' . $runId, '--dry-run');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('invalid --run "' . $runId . '"', $result['err']);
        self::assertSame('', $result['out'], 'no foreign file is ever published as a harness artifact');
    }

    public function testAnAmbiguousRunIdIsRefused(): void
    {
        $this->startCycle();
        $this->write('.pi-subagents/artifacts/cccc3333_alpha_0_output.md', "alpha\n");
        $this->write('.pi-subagents/artifacts/cccc3333_beta_0_output.md', "beta\n");

        $result = $this->pow('--round=1', '--role=review', '--run=cccc3333', '--dry-run');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('matches 2 artifacts', $result['err']);
        self::assertStringContainsString('cccc3333_alpha_0_output.md', $result['err']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unreadableFindingIdProvider(): iterable
    {
        yield 'header row' => ['ID'];
        yield 'separator row' => ['---sneaky'];
        yield 'leading dash' => ['-F-01'];
        yield 'pipe' => ['F|01'];
    }

    #[DataProvider('unreadableFindingIdProvider')]
    public function testAFindingIdTheLedgerCannotReadBackIsRefused(string $id): void
    {
        $this->startCycle();
        $before = $this->read('docs/proof_of_work/current/findings.md');

        $result = $this->pow(
            '--finding',
            '--id=' . $id,
            '--round=1',
            '--loc=src/Foo.php:1',
            '--desc=whatever',
            '--severity=high',
        );

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('invalid --id "' . $id . '"', $result['err']);
        self::assertStringNotContainsString('Recorded finding', $result['out']);
        self::assertSame($before, $this->read('docs/proof_of_work/current/findings.md'), 'no row is written');
        self::assertStringContainsString('0 total, 0 in round 1, 0 escaped, 0 open', $this->pow('--status')['out']);

        $resolve = $this->pow('--resolve', '--id=' . $id, '--round=1', '--status=fixed', '--resolution=x');
        self::assertSame(2, $resolve['code']);
        self::assertStringContainsString('invalid --id', $resolve['err']);
    }

    public function testAValueOptionDoesNotSwallowAFollowingFlag(): void
    {
        $this->startCycle();

        $result = $this->pow('--finding', '--id', '--round=1', '--loc=a:1', '--desc=x', '--severity=high');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('option --id requires a value', $result['err']);
        self::assertStringNotContainsString('--round=1', $this->read('docs/proof_of_work/current/findings.md'));
    }

    public function testControlCharactersNeverReachTheLedger(): void
    {
        $this->startCycle();

        $result = $this->pow(
            '--finding',
            '--id=F-01',
            '--round=1',
            '--loc=src/Foo.php:1',
            "--desc=alert\x1b[31mred\x1b[0m and a\x0cform feed",
            '--severity=high',
        );

        self::assertSame(0, $result['code'], $result['err']);

        $ledger = $this->read('docs/proof_of_work/current/findings.md');
        self::assertStringNotContainsString("\x1b", $ledger, 'ANSI escapes must not be injectable into a committed file');
        self::assertStringNotContainsString("\x0c", $ledger);
        self::assertStringContainsString('alert[31mred[0m and aform feed', $ledger);
    }

    // ---------------------------------------------------------------------
    // Manifest integrity
    // ---------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableManifestProvider(): iterable
    {
        yield 'object with one key' => ['{"issue":1}'];
        yield 'json array' => ['[1,2,3]'];
        yield 'wrong types' => ['{"pow_version":1,"issue":"4242","slug":"s","branch":"b","profile":"full","round_cap":4,"created_at":"now","rounds":{},"commits":[],"files_changed":[],"lint_exit":null,"test_exit":null,"coverage":null,"findings":{"total":0,"round1":0,"escaped":0,"open":0},"gates_added":[],"aborted":[],"verdict":null}'];
        yield 'unknown profile' => ['{"pow_version":1,"issue":4242,"slug":"s","branch":"b","profile":"turbo","round_cap":9,"created_at":"now","rounds":[],"commits":[],"files_changed":[],"lint_exit":null,"test_exit":null,"coverage":null,"findings":{"total":0,"round1":0,"escaped":0,"open":0},"gates_added":[],"aborted":[],"verdict":null}'];
        yield 'not json' => ['garbage'];
    }

    #[DataProvider('unusableManifestProvider')]
    public function testAManifestTheScriptCannotUnderstandIsNeverReportedAsUsable(string $contents): void
    {
        $this->startCycle();
        $this->write('docs/proof_of_work/current/manifest.json', $contents);

        $status = $this->pow('--status');

        self::assertSame(1, $status['code'], 'a tool CI parses must not exit 0 on a manifest it could not read');
        self::assertStringContainsString('manifest.json', $status['err']);
        self::assertStringNotContainsString('Undefined array key', $status['err']);
        self::assertStringNotContainsString('Warning', $status['err']);
        self::assertSame('', $status['out']);

        $round = $this->pow('--round=1', '--role=review', '--run=aaaa1111', '--dry-run');
        self::assertSame(1, $round['code'], 'round_cap must never be read from an unusable manifest');
    }

    public function testAbortRescuesACorruptManifest(): void
    {
        $this->startCycle();
        $this->write('docs/proof_of_work/current/manifest.json', 'garbage');

        $result = $this->pow('--abort', '--reason=oops');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringNotContainsString('for issue #', $result['out'], 'the issue number is unknown, not invented');
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/current/manifest.json'));
        self::assertFileExists($this->path('docs/proof_of_work/current/.gitkeep'));

        $archive = $this->onlyArchive();
        self::assertSame('garbage', (string) file_get_contents($archive . '/manifest.json'));
    }

    // ---------------------------------------------------------------------
    // Profile
    // ---------------------------------------------------------------------

    public function testLightProfileIsRefusedOnABranchWhosePrefixMandatesFull(): void
    {
        $result = $this->pow('--start', '--issue=' . self::ISSUE, '--slug=' . self::SLUG, '--profile=light');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('--profile=light is refused on a "feat/" branch', $result['err']);
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/current/manifest.json'));

        $process = $this->pow('--start', '--issue=' . self::ISSUE, '--slug=' . self::SLUG, '--profile=light', '--branch=process/issue-4242-x');
        self::assertSame(2, $process['code']);
        self::assertStringContainsString('--profile=light is refused on a "process/" branch', $process['err']);
    }

    public function testASkippedLabelLookupIsAnnounced(): void
    {
        $result = $this->startCycle('light');

        self::assertStringContainsString('POW_NO_GH=1', $result['err']);
        self::assertStringContainsString('were NOT checked', $result['err']);
        self::assertStringContainsString('unverified', $result['err']);
    }

    // ---------------------------------------------------------------------
    // Verdict rules survive any command order
    // ---------------------------------------------------------------------

    public function testAcceptCannotBeBypassedByRecordingFindingsAfterTheVerdict(): void
    {
        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');
        $this->write('docs/proof_of_work/current/escalation.md', "Oracle verdict: ACCEPT.\nF-01 is accepted.\n");
        $this->completeCycle('ACCEPT');

        $this->addFinding('F-77', 2, 'high');

        $result = $this->pow('--finish');
        self::assertSame(1, $result['code'], 'an open finding added after the verdict must not ship');
        self::assertStringContainsString('ACCEPT with unjustified findings: F-77', $result['err']);
        self::assertFileDoesNotExist($this->path('docs/proof_of_work/' . sprintf('%04d', self::ISSUE) . '-' . self::SLUG));
    }

    public function testAcceptCannotBeBypassedByEditingTheEscalationAfterTheVerdict(): void
    {
        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');
        $this->write('docs/proof_of_work/current/escalation.md', "Oracle verdict: ACCEPT.\nF-01 is accepted.\n");
        $this->completeCycle('ACCEPT');

        $this->write('docs/proof_of_work/current/escalation.md', "\n");

        $result = $this->pow('--finish');
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('verdict ACCEPT requires a non-empty', $result['err']);
    }

    public function testAcceptNeedsAWholeWordMatchOfEveryOpenId(): void
    {
        $this->startCycle();
        $this->addFinding('F-1', 1, 'high');
        $this->addFinding('F-10', 1, 'nit');
        $this->write('docs/proof_of_work/current/escalation.md', "Oracle verdict: ACCEPT.\nF-10 is cosmetic.\n");

        $prefix = $this->pow('--verdict=ACCEPT');
        self::assertSame(1, $prefix['code'], 'naming F-10 must not justify F-1 by prefix');
        self::assertStringContainsString('ACCEPT with unjustified findings: F-1', $prefix['err']);
        self::assertStringNotContainsString('F-10', $prefix['err']);

        $this->write(
            'docs/proof_of_work/current/escalation.md',
            "Oracle verdict: ACCEPT.\nF-10 is cosmetic.\nF-1 is accepted on purpose.\n",
        );
        self::assertSame(0, $this->pow('--verdict=ACCEPT')['code']);
    }

    // ---------------------------------------------------------------------
    // --finish never destroys recorded evidence
    // ---------------------------------------------------------------------

    public function testFinishNeverOverwritesARecordedCycle(): void
    {
        $target = 'docs/proof_of_work/' . sprintf('%04d', self::ISSUE) . '-' . self::SLUG;

        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');
        $this->addFinding('F-02', 1, 'high');
        $this->write('docs/proof_of_work/current/escalation.md', "ACCEPT: F-01 and F-02 stay open.\n");
        $this->completeCycle('ACCEPT');
        self::assertSame(0, $this->pow('--finish')['code']);

        $recordedLedger = $this->read($target . '/findings.md');
        self::assertFileExists($this->path($target . '/escalation.md'));

        $this->startCycle();
        $this->addFinding('F-90', 1, 'nit');
        self::assertSame(0, $this->pow('--resolve', '--id=F-90', '--round=2', '--status=fixed', '--resolution=done')['code']);
        $this->completeCycle('CLEAN');

        $result = $this->pow('--finish');
        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('Archived the previously recorded ' . sprintf('%04d', self::ISSUE) . '-' . self::SLUG, $result['err']);

        $archive = $this->onlyArchive();
        self::assertSame($recordedLedger, (string) file_get_contents($archive . '/findings.md'), 'the previous ledger survives verbatim');
        self::assertFileExists($archive . '/escalation.md');
        self::assertFileExists($archive . '/manifest.json');

        self::assertStringContainsString('| F-90 |', $this->read($target . '/findings.md'));
        self::assertStringNotContainsString('| F-01 |', $this->read($target . '/findings.md'));
        self::assertFileDoesNotExist(
            $this->path($target . '/escalation.md'),
            'no stale escalation.md may sit beside a manifest that says CLEAN',
        );
        self::assertSame('CLEAN', $this->manifest($target . '/manifest.json')['verdict']);
    }

    public function testFinishReplacesTheDirectoryWhenTheLedgerOnlyGrew(): void
    {
        $target = 'docs/proof_of_work/' . sprintf('%04d', self::ISSUE) . '-' . self::SLUG;

        $this->startCycle();
        $this->addFinding('F-01', 1, 'high');
        self::assertSame(0, $this->pow('--resolve', '--id=F-01', '--round=2', '--status=fixed', '--resolution=done')['code']);
        $this->completeCycle('CLEAN');
        self::assertSame(0, $this->pow('--finish')['code']);

        $recordedLedger = $this->read($target . '/findings.md');

        $this->startCycle();
        $this->write('docs/proof_of_work/current/findings.md', $recordedLedger);
        $this->addFinding('F-02', 2, 'nit');
        self::assertSame(0, $this->pow('--resolve', '--id=F-02', '--round=2', '--status=fixed', '--resolution=done')['code']);
        $this->completeCycle('CLEAN');

        $result = $this->pow('--finish');
        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('the new ledger extends the recorded one', $result['err']);
        self::assertSame([], glob($this->path('docs/proof_of_work/.abandoned/*'), \GLOB_ONLYDIR), 'nothing to archive');

        $ledger = $this->read($target . '/findings.md');
        self::assertTrue(str_starts_with($ledger, $recordedLedger), 'the recorded ledger stays a prefix of the new one');
        self::assertStringContainsString('| F-02 |', $ledger);
    }

    public function testFinishReSlugifiesTheManifestSlug(): void
    {
        $this->startCycle();
        $this->completeCycle();

        $manifest = $this->manifest('docs/proof_of_work/current/manifest.json');
        $manifest['slug'] = '../../../escaped';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);
        $this->write('docs/proof_of_work/current/manifest.json', $json . "\n");

        $result = $this->pow('--finish');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertDirectoryExists($this->path('docs/proof_of_work/' . sprintf('%04d', self::ISSUE) . '-escaped'));
        self::assertDirectoryDoesNotExist($this->path('docs/escaped'), 'the slug must never escape docs/proof_of_work/');
        self::assertDirectoryDoesNotExist($this->sandbox . '/../escaped');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function rounds(): array
    {
        $rounds = $this->manifest('docs/proof_of_work/current/manifest.json')['rounds'];
        self::assertIsArray($rounds);

        /** @var list<array<string, mixed>> $rounds */
        $rounds = array_values($rounds);

        return $rounds;
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function startCycle(string $profile = 'full'): array
    {
        $args = ['--start', '--issue=' . self::ISSUE, '--slug=' . self::SLUG];

        if ($profile !== 'full') {
            // A light cycle needs a branch whose prefix allows it.
            $args[] = '--profile=' . $profile;
            $args[] = '--branch=' . self::LIGHT_BRANCH;
        }

        $result = $this->pow(...$args);
        self::assertSame(0, $result['code'], $result['err']);

        return $result;
    }

    /**
     * Brings the cycle to the state `--finish` requires: two rounds, both
     * machine exit codes and a verdict.
     */
    private function completeCycle(string $verdict = 'CLEAN'): void
    {
        $this->seedRounds();
        self::assertSame(0, $this->pow('--set', 'lint_exit=0', '--set', 'test_exit=0')['code']);
        self::assertSame(0, $this->pow('--verdict=' . $verdict)['code']);
    }

    private function addFinding(string $id, int $round, string $severity): void
    {
        $result = $this->pow(
            '--finding',
            '--id=' . $id,
            '--round=' . $round,
            '--loc=src/Foo.php:' . $round,
            '--desc=finding ' . $id . ' | with a pipe',
            '--severity=' . $severity,
        );

        self::assertSame(0, $result['code'], $result['err']);
    }

    /**
     * Seeds the two rounds a `full` cycle needs straight into the manifest.
     *
     * Only for tests about something else than `--round` itself (`--finish`,
     * `--verdict`): the publishing path has its own coverage through the stub
     * `gh` installed by {@see fakeGh()}.
     */
    private function seedRounds(): void
    {
        $manifest = $this->manifest('docs/proof_of_work/current/manifest.json');
        $manifest['rounds'] = [
            [
                'n' => 1,
                'role' => 'coder',
                'agent' => 'coder',
                'run_id' => 'bbbb2222',
                'comment_id' => 111,
                'comment_sha256' => str_repeat('a', 64),
                'prev' => null,
                'created_at' => '2026-08-11T10:00:00Z',
            ],
            [
                'n' => 1,
                'role' => 'review',
                'agent' => 'review',
                'run_id' => 'aaaa1111',
                'comment_id' => 222,
                'comment_sha256' => str_repeat('b', 64),
                'prev' => str_repeat('a', 64),
                'created_at' => '2026-08-11T10:05:00Z',
            ],
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::assertIsString($json);

        $this->write('docs/proof_of_work/current/manifest.json', $json . "\n");
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function pow(string ...$args): array
    {
        return $this->exec(array_merge([PHP_BINARY, $this->script], array_values($args)));
    }

    /**
     * Installs a stub `gh` in the sandbox and puts it first on the child PATH,
     * so the non-dry-run `--round` path — comment sha chaining, the
     * server-assigned timestamp, the API response handling — runs for real
     * without any production hook.
     *
     * Modes: ok | no-pr | post-fails | normalises-newlines | same-comment-id |
     * chatty-stderr.
     */
    private function fakeGh(string $mode = 'ok'): void
    {
        $dir = $this->sandbox . '/fakebin';

        if (!is_dir($dir)) {
            self::assertTrue(mkdir($dir, 0o775, true));
        }

        $stub = '#!' . PHP_BINARY . "\n" . <<<'PHP'
            <?php

            $args = array_slice($argv, 1);
            $mode = (string) getenv('POW_FAKE_GH_MODE');
            $stateFile = (string) getenv('POW_FAKE_GH_STATE');
            file_put_contents((string) getenv('POW_FAKE_GH_LOG'), implode(' ', $args) . "\n", FILE_APPEND);

            if (($args[0] ?? '') === 'pr' && ($args[1] ?? '') === 'view') {
                if ($mode === 'no-pr') {
                    fwrite(STDERR, "no pull requests found for branch\n");
                    exit(1);
                }

                fwrite(STDOUT, "7\n");
                exit(0);
            }

            if (($args[0] ?? '') === 'api') {
                if ($mode === 'post-fails') {
                    fwrite(STDERR, "HTTP 502: Bad gateway\n");
                    exit(1);
                }

                $input = null;

                foreach ($args as $i => $arg) {
                    if ($arg === '--input') {
                        $input = $args[$i + 1] ?? null;
                    }
                }

                if (!is_string($input) || !is_file($input)) {
                    fwrite(STDERR, "fake gh: --input file missing\n");
                    exit(1);
                }

                if ($mode === 'chatty-stderr') {
                    // Far beyond the 64 KB pipe buffer: a caller that reads
                    // stdout to EOF before touching stderr deadlocks here.
                    fwrite(STDERR, str_repeat("noise\n", 60000));
                }

                $payload = json_decode((string) file_get_contents($input), true);
                $body = is_array($payload) && isset($payload['body']) ? (string) $payload['body'] : '';

                if ($mode === 'normalises-newlines') {
                    $body = str_replace("\n", "\r\n", $body);
                }

                $n = is_file($stateFile) ? (int) file_get_contents($stateFile) : 0;
                $n++;
                file_put_contents($stateFile, (string) $n);
                $id = $mode === 'same-comment-id' ? 999 : 100 + $n;

                echo (string) json_encode([
                    'id' => $id,
                    'created_at' => sprintf('2019-05-%02dT%02d:00:00Z', $n, $n),
                    'html_url' => 'https://github.test/o/r/pull/7#issuecomment-' . $id,
                    'body' => $body,
                ]);
                exit(0);
            }

            fwrite(STDERR, 'fake gh: unsupported command: ' . implode(' ', $args) . "\n");
            exit(1);
            PHP;

        self::assertNotFalse(file_put_contents($dir . '/gh', $stub));
        self::assertTrue(chmod($dir . '/gh', 0o755));

        $this->ghMode = $mode;
    }

    private function ghLog(): string
    {
        $log = $this->sandbox . '/fakegh.log';

        return is_file($log) ? (string) file_get_contents($log) : '';
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        $env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'POW_ROOT' => $this->sandbox,
            // A developer's global commit.gpgsign or core.hooksPath must not
            // reach the sandbox repository.
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
        ];

        if ($this->ghMode === null) {
            $env['POW_NO_GH'] = '1';

            return $env;
        }

        $env['PATH'] = $this->sandbox . '/fakebin:' . $env['PATH'];
        $env['POW_FAKE_GH_MODE'] = $this->ghMode;
        $env['POW_FAKE_GH_STATE'] = $this->sandbox . '/fakegh-state';
        $env['POW_FAKE_GH_LOG'] = $this->sandbox . '/fakegh.log';

        return $env;
    }

    /**
     * Both pipes are drained together and the wait is bounded, so a child that
     * deadlocks on a full pipe buffer fails the test instead of hanging CI.
     *
     * @param list<string> $cmd
     *
     * @return array{code: int, out: string, err: string}
     */
    private function exec(array $cmd, float $timeout = 30.0): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, $this->sandbox, $this->env());

        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffers = [1 => '', 2 => ''];
        $open = $pipes;
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while ($open !== []) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($open as $fd => $stream) {
                if (!\in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = fread($stream, 65536);

                if ($chunk !== false && $chunk !== '') {
                    $buffers[$fd] .= $chunk;

                    continue;
                }

                if (feof($stream)) {
                    unset($open[$fd]);
                }
            }
        }

        if ($timedOut) {
            proc_terminate($process, \SIGKILL);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        self::assertFalse($timedOut, 'the command did not finish within ' . $timeout . 's: ' . implode(' ', $cmd));

        return ['code' => $code, 'out' => $buffers[1], 'err' => $buffers[2]];
    }

    private function initGitRepository(): void
    {
        self::assertSame(0, $this->git('init', '-q')['code'], 'git is required for these tests');
        $this->git('symbolic-ref', 'HEAD', 'refs/heads/master');

        $this->write('README.md', "sandbox\n");
        $this->git('add', '-A');
        self::assertSame(0, $this->git('commit', '-qm', 'base')['code']);

        self::assertSame(0, $this->git('switch', '-qc', self::BRANCH)['code']);
        $this->write('worked-on.txt', "change\n");
        $this->git('add', '-A');
        self::assertSame(0, $this->git('commit', '-qm', 'work')['code']);
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

    /**
     * Asserts that exactly one cycle was archived and returns its directory.
     */
    private function onlyArchive(): string
    {
        $archives = glob($this->path('docs/proof_of_work/.abandoned/*'), \GLOB_ONLYDIR);
        self::assertIsArray($archives);
        self::assertCount(1, $archives, 'an abandoned cycle must be archived, not deleted');

        return (string) reset($archives);
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

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $relative): array
    {
        $decoded = json_decode($this->read($relative), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
