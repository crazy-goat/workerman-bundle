<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\ProofOfWork;

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

    private string $sandbox = '';

    private string $script = '';

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
    // Helpers
    // ---------------------------------------------------------------------

    private function startCycle(string $profile = 'full'): void
    {
        $args = ['--start', '--issue=' . self::ISSUE, '--slug=' . self::SLUG];

        if ($profile !== 'full') {
            $args[] = '--profile=' . $profile;
        }

        $result = $this->pow(...$args);
        self::assertSame(0, $result['code'], $result['err']);
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
     * `--round` cannot record anything without `gh`, so the two rounds every
     * `full` cycle needs are seeded straight into the manifest.
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
     * @param list<string> $cmd
     *
     * @return array{code: int, out: string, err: string}
     */
    private function exec(array $cmd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, $this->sandbox, [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'POW_ROOT' => $this->sandbox,
            'POW_NO_GH' => '1',
        ]);

        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
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
