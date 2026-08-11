<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Process;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/pow-metrics.php` as a subprocess against a throwaway sandbox of
 * hand-written `docs/proof_of_work/<NNNN>-<slug>/{manifest.json,findings.md}`
 * fixtures — the same pattern as
 * {@see \CrazyGoat\WorkermanBundle\Test\ProofOfWork\PowScriptTest}. The script
 * never calls `gh`, so no fake `gh` stub is needed here.
 *
 * @coversNothing
 */
final class PowMetricsScriptTest extends TestCase
{
    private string $script = '';

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->script = dirname(__DIR__, 2) . '/bin/pow-metrics.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/pow-metrics-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox . '/docs/proof_of_work', 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }
    }

    public function testNoCyclesFailsTheDefaultMinCyclesGate(): void
    {
        $result = $this->metrics();

        self::assertSame(1, $result['code'], $result['err']);
        self::assertStringContainsString('0 cycle(s) available, 0 in scope', $result['out']);
        self::assertStringContainsString('not enough evidence yet', $result['err']);
    }

    public function testOneCycleFailsTheDefaultMinCyclesGate(): void
    {
        $this->createCycle(1, 'first', []);

        $result = $this->metrics();

        self::assertSame(1, $result['code'], $result['err']);
        self::assertStringContainsString('1 cycle(s) available, 1 in scope', $result['out']);
    }

    public function testOneCyclePassesWithAnExplicitMinCyclesOverride(): void
    {
        $this->createCycle(1, 'first', []);

        $result = $this->metrics('--min-cycles=1');

        self::assertSame(0, $result['code'], $result['err']);
    }

    public function testThreeCyclesSatisfyTheDefaultMinCyclesGate(): void
    {
        $this->createCycle(1, 'first', []);
        $this->createCycle(2, 'second', []);
        $this->createCycle(3, 'third', []);

        $result = $this->metrics();

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('3 cycle(s) available, 3 in scope', $result['out']);
    }

    public function testEscapeRateArithmeticMatchesFindingsFirstSeenInRoundTwoOrLater(): void
    {
        // 4 findings: 1 first seen in round 1, 3 first seen in round >= 2 -> 75%.
        $this->createCycle(1, 'first', [
            ['id' => 'F-01', 'firstRound' => 1, 'status' => 'fixed'],
            ['id' => 'F-02', 'firstRound' => 2, 'status' => 'fixed'],
            ['id' => 'F-03', 'firstRound' => 2, 'status' => 'gated'],
            ['id' => 'F-04', 'firstRound' => 3, 'status' => 'wontfix'],
        ]);

        $result = $this->metrics('--min-cycles=1', '--json');
        self::assertSame(0, $result['code'], $result['err']);

        $payload = json_decode($result['out'], true);
        self::assertIsArray($payload);
        $cycle = $payload['cycles'][0];

        self::assertSame(4, $cycle['findings']['total']);
        self::assertSame(1, $cycle['findings']['round1']);
        self::assertSame(3, $cycle['findings']['escaped']);
        self::assertSame(2, $cycle['findings']['fixed']);
        self::assertSame(1, $cycle['findings']['gated']);
        self::assertSame(1, $cycle['findings']['wontfix']);
        self::assertSame(0, $cycle['findings']['open']);
        self::assertEqualsWithDelta(0.75, $cycle['escape_rate'], 0.0001);
        self::assertEqualsWithDelta(0.75, $payload['aggregate']['escape_rate'], 0.0001);
    }

    public function testSinceLimitsToTheMostRecentCyclesByCreatedAt(): void
    {
        $this->createCycle(1, 'first', [], '2026-01-01T00:00:00Z');
        $this->createCycle(2, 'second', [], '2026-02-01T00:00:00Z');
        $this->createCycle(3, 'third', [], '2026-03-01T00:00:00Z');

        $result = $this->metrics('--min-cycles=1', '--since=2', '--json');
        self::assertSame(0, $result['code'], $result['err']);

        $payload = json_decode($result['out'], true);
        self::assertIsArray($payload);
        self::assertSame(3, $payload['cycles_available']);
        self::assertSame(2, $payload['since']);
        self::assertCount(2, $payload['cycles']);
        self::assertSame(2, $payload['cycles'][0]['issue']);
        self::assertSame(3, $payload['cycles'][1]['issue']);
    }

    public function testJsonOutputIsWellFormedAndReportsEnoughForRetro(): void
    {
        $this->createCycle(1, 'first', []);
        $this->createCycle(2, 'second', []);
        $this->createCycle(3, 'third', []);

        $result = $this->metrics('--json');
        self::assertSame(0, $result['code'], $result['err']);

        $payload = json_decode($result['out'], true);
        self::assertIsArray($payload);
        self::assertSame(3, $payload['cycles_available']);
        self::assertNull($payload['since']);
        self::assertSame(3, $payload['min_cycles']);
        self::assertTrue($payload['enough_for_retro']);
        self::assertCount(3, $payload['cycles']);
        self::assertArrayHasKey('aggregate', $payload);
    }

    public function testMalformedManifestIsSkippedWithANoticeInsteadOfAbortingTheReport(): void
    {
        $goodDir = $this->sandbox . '/docs/proof_of_work/0001-first';
        self::assertTrue(mkdir($goodDir, 0o775, true));
        $this->writeCycleFiles($goodDir, 1, 'first', [], '2026-01-01T00:00:00Z');

        $badDir = $this->sandbox . '/docs/proof_of_work/0002-broken';
        self::assertTrue(mkdir($badDir, 0o775, true));
        self::assertNotFalse(file_put_contents($badDir . '/manifest.json', '{not valid json'));

        $result = $this->metrics('--min-cycles=1');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 cycle(s) available, 1 in scope', $result['out']);
        self::assertStringContainsString('0002-broken', $result['err']);
    }

    public function testMismatchedPowVersionIsSkippedWithANoticeInsteadOfCountedAsAnOrdinaryCycle(): void
    {
        $goodDir = $this->sandbox . '/docs/proof_of_work/0001-first';
        self::assertTrue(mkdir($goodDir, 0o775, true));
        $this->writeCycleFiles($goodDir, 1, 'first', [], '2026-01-01T00:00:00Z');

        $futureDir = $this->sandbox . '/docs/proof_of_work/0002-future';
        self::assertTrue(mkdir($futureDir, 0o775, true));
        $this->writeCycleFiles($futureDir, 2, 'future', [], '2026-01-02T00:00:00Z', powVersion: 99);

        $result = $this->metrics('--min-cycles=1');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 cycle(s) available, 1 in scope', $result['out']);
        self::assertStringContainsString('0002-future', $result['err']);
        self::assertStringContainsString('pow_version', $result['err']);
    }

    public function testMissingPowVersionIsSkippedWithANoticeInsteadOfCountedAsAnOrdinaryCycle(): void
    {
        $goodDir = $this->sandbox . '/docs/proof_of_work/0001-first';
        self::assertTrue(mkdir($goodDir, 0o775, true));
        $this->writeCycleFiles($goodDir, 1, 'first', [], '2026-01-01T00:00:00Z');

        $unversionedDir = $this->sandbox . '/docs/proof_of_work/0002-unversioned';
        self::assertTrue(mkdir($unversionedDir, 0o775, true));
        $this->writeCycleFiles($unversionedDir, 2, 'unversioned', [], '2026-01-02T00:00:00Z', powVersion: null);

        $result = $this->metrics('--min-cycles=1');

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 cycle(s) available, 1 in scope', $result['out']);
        self::assertStringContainsString('0002-unversioned', $result['err']);
        self::assertStringContainsString('pow_version', $result['err']);
    }

    public function testJsonAggregateVerdictsIsAlwaysAJsonObjectNeverAnArray(): void
    {
        $emptyResult = $this->metrics('--min-cycles=0', '--json');
        self::assertSame(0, $emptyResult['code'], $emptyResult['err']);
        $this->assertJsonKeyIsAlwaysAnObject($emptyResult['out'], 'verdicts');
        $this->assertJsonKeyIsAlwaysAnObject($emptyResult['out'], 'escape_rate_by_profile');

        $this->createCycle(1, 'first', []);
        $populatedResult = $this->metrics('--min-cycles=1', '--json');
        self::assertSame(0, $populatedResult['code'], $populatedResult['err']);
        $this->assertJsonKeyIsAlwaysAnObject($populatedResult['out'], 'verdicts');
        $this->assertJsonKeyIsAlwaysAnObject($populatedResult['out'], 'escape_rate_by_profile');

        $payload = json_decode($populatedResult['out'], true);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['aggregate']['verdicts']['CLEAN'] ?? null);
    }

    /**
     * `json_decode(..., true)` turns both a JSON object and a JSON array
     * into a PHP array, which would hide the exact regression this test
     * guards against (an empty PHP map serializing as `[]` instead of
     * `{}`), so this asserts against the raw JSON text instead of the
     * decoded value.
     */
    private function assertJsonKeyIsAlwaysAnObject(string $json, string $key): void
    {
        self::assertMatchesRegularExpression(
            '/"' . preg_quote($key, '/') . '":\s*\{/',
            $json,
            sprintf('"%s" must serialize as a JSON object ({...})', $key),
        );
        self::assertDoesNotMatchRegularExpression(
            '/"' . preg_quote($key, '/') . '":\s*\[/',
            $json,
            sprintf('"%s" must never serialize as a JSON array ([...])', $key),
        );
    }

    public function testAggregateSegmentsEscapeRateByProfile(): void
    {
        // full: 1 finding first seen round 1 -> 0% escape.
        $this->createCycle(1, 'first', [
            ['id' => 'F-01', 'firstRound' => 1, 'status' => 'fixed'],
        ], profile: 'full');

        // light: 1 finding first seen round 2 -> 100% escape.
        $this->createCycle(2, 'second', [
            ['id' => 'F-01', 'firstRound' => 2, 'status' => 'fixed'],
        ], profile: 'light');

        $result = $this->metrics('--min-cycles=1', '--json');
        self::assertSame(0, $result['code'], $result['err']);

        $payload = json_decode($result['out'], true);
        self::assertIsArray($payload);

        self::assertEqualsWithDelta(0.0, $payload['aggregate']['escape_rate_by_profile']['full'], 0.0001);
        self::assertEqualsWithDelta(1.0, $payload['aggregate']['escape_rate_by_profile']['light'], 0.0001);
    }

    public function testUnknownOptionIsAUsageError(): void
    {
        $result = $this->metrics('--not-a-real-option');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('unknown option', $result['err']);
    }

    public function testHelpFlagPrintsUsageAndExitsZero(): void
    {
        $result = $this->metrics('--help');

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('pow-metrics.php', $result['out']);
        self::assertStringContainsString('--min-cycles', $result['out']);
    }

    /**
     * @param list<array{id: string, firstRound: int, status: string}> $findings
     */
    private function createCycle(int $issue, string $slug, array $findings, ?string $createdAt = null, string $profile = 'full'): void
    {
        $dir = sprintf('%s/docs/proof_of_work/%04d-%s', $this->sandbox, $issue, $slug);
        self::assertTrue(mkdir($dir, 0o775, true));
        $this->writeCycleFiles($dir, $issue, $slug, $findings, $createdAt, $profile);
    }

    /**
     * @param list<array{id: string, firstRound: int, status: string}> $findings
     */
    private function writeCycleFiles(string $dir, int $issue, string $slug, array $findings, ?string $createdAt, string $profile = 'full', int|string|null $powVersion = 1): void
    {
        $rounds = [];
        $maxRound = 1;

        foreach ($findings as $finding) {
            $maxRound = max($maxRound, $finding['firstRound']);
        }

        for ($n = 1; $n <= $maxRound; $n++) {
            $rounds[] = ['n' => $n, 'role' => 'review', 'agent' => 'review', 'run_id' => 'r' . $n];
        }

        $manifest = [
            'pow_version' => $powVersion,
            'issue' => $issue,
            'slug' => $slug,
            'branch' => 'fix/issue-' . $issue . '-' . $slug,
            'profile' => $profile,
            'round_cap' => 4,
            'created_at' => $createdAt ?? '2026-01-01T00:00:00Z',
            'rounds' => $rounds,
            'commits' => [],
            'files_changed' => [],
            'lint_exit' => 0,
            'test_exit' => 0,
            'coverage' => 90.0,
            'findings' => ['total' => count($findings), 'round1' => 0, 'escaped' => 0, 'open' => 0],
            'gates_added' => [],
            'aborted' => [],
            'verdict' => 'CLEAN',
        ];

        if ($powVersion === null) {
            unset($manifest['pow_version']);
        }

        self::assertNotFalse(file_put_contents(
            $dir . '/manifest.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ));

        $ledger = "# Findings ledger — issue #{$issue}\n\n"
            . "| ID | round | file:line | description | severity | status | resolution |\n"
            . "| --- | --- | --- | --- | --- | --- | --- |\n";

        foreach ($findings as $finding) {
            $ledger .= sprintf(
                "| %s | %d | a.php:1 | some finding | medium | open |  |\n",
                $finding['id'],
                $finding['firstRound'],
            );

            if ($finding['status'] !== 'open') {
                $ledger .= sprintf(
                    "| %s | %d | a.php:1 | some finding | medium | %s | resolved |\n",
                    $finding['id'],
                    $finding['firstRound'],
                    $finding['status'],
                );
            }
        }

        self::assertNotFalse(file_put_contents($dir . '/findings.md', $ledger));
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function metrics(string ...$args): array
    {
        $cmd = array_merge([PHP_BINARY, $this->script], array_values($args));
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $env = ['PATH' => (string) getenv('PATH'), 'POW_ROOT' => $this->sandbox];
        $process = proc_open($cmd, $descriptors, $pipes, $this->sandbox, $env);

        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + 15.0;

        while ($open !== [] && microtime(true) < $deadline) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($open as $fd => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = fread($stream, 65536);

                if ($chunk !== false && $chunk !== '') {
                    if ($fd === 1) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }

                    continue;
                }

                if (feof($stream)) {
                    unset($open[$fd]);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach ((array) scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursively($path . '/' . $entry);
                }
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }
}
