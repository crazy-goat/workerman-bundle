<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\ProofOfWork;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `bin/pow-common.php` holds every rule the recorder (`bin/pow.php`) and the
 * gate (`bin/check-pow.php`) have to agree on. Each copy that used to live in
 * both files had drifted, and every drift was a hole, so the point of these
 * tests is that there is exactly one implementation and that both entry points
 * really load it.
 *
 * `bin/` is outside the PSR-4 autoloader and outside the static-analysis scope
 * on purpose (these scripts run before `composer install`), so the module is
 * exercised the same way its siblings are: in a subprocess, which reports back
 * as JSON on stdout.
 *
 * @coversNothing
 */
final class PowCommonTest extends TestCase
{
    private string $common = '';

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->common = \dirname(__DIR__, 2) . '/bin/pow-common.php';
        self::assertFileExists($this->common);

        $sandbox = sys_get_temp_dir() . '/pow-common-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox, 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->sandbox . '/*') as $file) {
            unlink((string) $file);
        }

        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            rmdir($this->sandbox);
        }
    }

    // ---------------------------------------------------------------------
    // One source for the branch pattern
    // ---------------------------------------------------------------------

    public function testTheIssueBranchPatternHasOneSourceInThreeRenderings(): void
    {
        self::assertSame('#^(fix|feat|process)/issue-(\d+)#', $this->evaluate('powcIssueBranchPattern()'));
        self::assertSame('^(fix|feat|process)/issue-[0-9]+', $this->evaluate('powcIssueBranchEre()'));

        self::assertSame(
            ['process', '686'],
            $this->evaluate('(static function (): array { preg_match(powcIssueBranchPattern(), "process/issue-686-x", $m); return [$m[1], $m[2]]; })()'),
        );
        self::assertSame(1, $this->evaluate('preg_match("#" . powcIssueBranchEre() . "#", "fix/issue-1-a")'));
        self::assertSame(0, $this->evaluate('preg_match("#" . powcIssueBranchEre() . "#", "chore/issue-1-a")'));
    }

    public function testEveryEntryPointLoadsTheSharedRulesInsteadOfRestatingThem(): void
    {
        $bin = \dirname(__DIR__, 2) . '/bin';

        foreach (['check-pow.php', 'pow.php', 'install-git-hook.php'] as $script) {
            $source = file_get_contents($bin . '/' . $script);
            self::assertIsString($source);
            self::assertStringContainsString(
                "require_once __DIR__ . '/pow-common.php'",
                $source,
                $script . ' must load the shared rules rather than restate them',
            );
        }

        $installer = file_get_contents($bin . '/install-git-hook.php');
        self::assertIsString($installer);
        self::assertStringContainsString('powcIssueBranchEre()', $installer);
        self::assertStringNotContainsString(
            '^(fix|feat|process)/issue-[0-9]+',
            $installer,
            'the installer must derive the pattern, not carry a fourth copy of it',
        );
    }

    public function testTheRecorderAndTheGateReadTheSameRoundNumbers(): void
    {
        self::assertSame(['full' => 2, 'light' => 1], $this->evaluate('POWC_MIN_ROUNDS'));
        self::assertSame(['full' => 4, 'light' => 2], $this->evaluate('POWC_PROFILE_CAPS'));
        self::assertSame(
            [],
            $this->evaluate('array_diff_key(POWC_PROFILE_CAPS, POWC_MIN_ROUNDS)'),
            'every profile needs a minimum as well as a cap',
        );
    }

    // ---------------------------------------------------------------------
    // Ledger parsing — the same parser on both sides
    // ---------------------------------------------------------------------

    public function testAWellFormedLedgerParsesIntoEffectiveStatuses(): void
    {
        $ledger = self::HEADER
            . "| F-01 | 1 | src/A.php:1 | first | high | open |  |\n"
            . "| F-01 | 2 | src/A.php:1 | first | high | fixed | patched |\n"
            . "| F-02 | 2 | src/B.php:2 | second | low | open |  |\n";

        self::assertSame([], $this->evaluate('powcParseLedger($ledger)["errors"]', $ledger));
        self::assertSame(3, $this->evaluate('count(powcParseLedger($ledger)["rows"])', $ledger));

        $state = $this->evaluate('powcLedgerState(powcParseLedger($ledger)["rows"])', $ledger);
        self::assertIsArray($state);
        self::assertSame('fixed', $state['F-01']['status'], 'the effective status is that of the last row');
        self::assertSame(1, $state['F-01']['first_round'], 'the first round is the one it was found in');

        self::assertSame(
            ['F-02'],
            $this->evaluate('powcOpenIds(powcLedgerState(powcParseLedger($ledger)["rows"]))', $ledger),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedRowProvider(): iterable
    {
        yield 'too few cells' => ['| F-03 | 2 | a:1 | three | high | open |', 'has 6 cells, not 7'];
        yield 'too many cells' => ['| F-03 | 2 | a:1 | three | high | open | done | extra |', 'has 8 cells, not 7'];
        yield 'empty id' => ['|  | 2 | a:1 | three | high | open | done |', 'empty ID cell'];
    }

    #[DataProvider('malformedRowProvider')]
    public function testAMalformedRowIsReportedRatherThanSkipped(string $row, string $expected): void
    {
        $ledger = self::HEADER . $row . "\n";

        self::assertSame(
            [],
            $this->evaluate('powcParseLedger($ledger)["rows"]', $ledger),
            'a row that cannot be read must not be half-read either',
        );

        $errors = $this->evaluate('powcParseLedger($ledger)["errors"]', $ledger);
        self::assertIsArray($errors);
        self::assertCount(1, $errors);
        self::assertIsString($errors[0]);
        self::assertStringContainsString($expected, $errors[0]);
    }

    public function testAnEscapedPipeStaysInsideOneCell(): void
    {
        $ledger = self::HEADER . '| F-01 | 1 | a:1 | a \\| b | high | open |  |' . "\n";

        self::assertSame([], $this->evaluate('powcParseLedger($ledger)["errors"]', $ledger));
        self::assertSame('a | b', $this->evaluate('powcParseLedger($ledger)["rows"][0]["desc"]', $ledger));
    }

    public function testCellEscapingSurvivesTheRoundTrip(): void
    {
        $text = "a | b\nsecond line";

        self::assertSame($text, $this->evaluate('powcUncell(powcCell($ledger))', $text));
        self::assertStringNotContainsString("\n", (string) $this->evaluate('powcCell($ledger)', $text));
        // The ESC that turns "[31m" into an escape sequence never reaches a
        // committed file; the harmless bytes around it are kept.
        self::assertSame('cl[31mean', $this->evaluate('powcCell($ledger)', "cl\x1b[31mean"));
        self::assertSame('ab', $this->evaluate('powcCell($ledger)', "a\x00\x07b"));
    }

    // ---------------------------------------------------------------------
    // Completeness — the rule --finish and POW-03 share
    // ---------------------------------------------------------------------

    public function testACompleteCycleHasNoProblems(): void
    {
        self::assertSame([], $this->evaluate('powcCompletenessProblems("full", 2, true, 0, 0, "CLEAN", "", [])'));
    }

    public function testTheMachineExitCodesArePartOfCompleteness(): void
    {
        // The gate used to check neither, so a manifest with no recorded
        // lint/test exit code passed POW-03 while --finish refused to publish it.
        $problems = $this->evaluate('powcCompletenessProblems("full", 2, true, null, null, "CLEAN", "", [])');

        self::assertIsArray($problems);
        self::assertContains('lint_exit is not set (pow.php --set lint_exit=<code>)', $problems);
        self::assertContains('test_exit is not set (pow.php --set test_exit=<code>)', $problems);
    }

    public function testAcceptNeedsEveryOpenFindingNamedInTheEscalation(): void
    {
        // A three-byte escalation.md used to satisfy the gate.
        $problems = $this->evaluate('powcCompletenessProblems("full", 2, true, 0, 0, "ACCEPT", "ok", ["F-01", "F-10"])');
        self::assertIsArray($problems);
        self::assertContains('ACCEPT with unjustified findings: F-01, F-10', $problems);

        self::assertSame(
            [],
            $this->evaluate('powcCompletenessProblems("full", 2, true, 0, 0, "ACCEPT", "F-01 and F-10 are accepted.", ["F-01", "F-10"])'),
        );
    }

    public function testNamingF10DoesNotJustifyF1(): void
    {
        self::assertTrue($this->evaluate('powcMentionsId("accepted: F-10", "F-10")'));
        self::assertFalse($this->evaluate('powcMentionsId("accepted: F-10", "F-1")'));
    }

    public function testTheLightProfileNeedsOneRoundAndTheFullProfileTwo(): void
    {
        self::assertSame([], $this->evaluate('powcCompletenessProblems("light", 1, true, 0, 0, "CLEAN", "", [])'));

        $problems = $this->evaluate('powcCompletenessProblems("full", 1, true, 0, 0, "CLEAN", "", [])');
        self::assertIsArray($problems);
        self::assertContains('only 1 round(s) recorded, the full profile needs at least 2', $problems);
    }

    public function testAnUnknownProfileGetsTheStrictMinimum(): void
    {
        $problems = $this->evaluate('powcCompletenessProblems("invented", 1, true, 0, 0, "CLEAN", "", [])');

        self::assertIsArray($problems);
        self::assertContains('only 1 round(s) recorded, the invented profile needs at least 2', $problems);
    }

    // ---------------------------------------------------------------------
    // Profiles and subprocesses
    // ---------------------------------------------------------------------

    public function testOnlyALightPrefixCanEverReachTheLightProfile(): void
    {
        self::assertSame('light', $this->evaluate('powcProfileFromPrefix("docs/issue-1-x")'));
        self::assertSame('full', $this->evaluate('powcProfileFromPrefix("fix/issue-1-x")'));
        self::assertSame(
            'full',
            $this->evaluate('powcProfileFromPrefix("something-invented")'),
            'an unknown prefix gets the strict choice',
        );
        self::assertFalse($this->evaluate('powcIsKnownPrefix("something-invented")'));
    }

    /**
     * The recorder drained both pipes concurrently and the gate drained one to
     * EOF first, so the gate deadlocked on a child that filled the other pipe's
     * 64 KB buffer. There is one drain now, and this test hangs if it regresses
     * — hence the hard timeout in {@see execute()}.
     */
    public function testAChildFloodingOnePipeDoesNotDeadlockTheOther(): void
    {
        $child = '$c = str_repeat("x", 1024);'
            . ' for ($i = 0; $i < 400; $i++) { fwrite(STDERR, $c); }'
            . ' fwrite(STDOUT, "done");'
            . ' for ($i = 0; $i < 400; $i++) { fwrite(STDERR, $c); }';

        $result = $this->evaluate(
            'powcRun([PHP_BINARY, "-r", $ledger])',
            $child,
        );

        self::assertIsArray($result);
        self::assertSame(0, $result['code']);
        self::assertSame('done', $result['out']);
        self::assertSame(400 * 2 * 1024, \strlen((string) $result['err']));
    }

    /**
     * The two platforms disagree about how an unstartable command surfaces:
     * macOS fails inside `proc_open()`, Linux forks successfully and the child
     * exits 127 with an empty stderr. This asserts the normalised contract, not
     * either platform's raw behaviour — the macOS-only version of this test
     * passed locally and failed on the Linux CI leg.
     */
    public function testRunReportsAnUnstartableCommandInsteadOfThrowing(): void
    {
        $result = $this->evaluate('powcRun(["this-command-does-not-exist-4242"])');

        self::assertIsArray($result);
        self::assertSame(127, $result['code']);
        self::assertStringContainsString('unable to start', (string) $result['err']);
        self::assertStringContainsString('this-command-does-not-exist-4242', (string) $result['err']);
    }

    /**
     * The flip side of the normalisation above, pinned so it stays deliberate:
     * a command that really did start and exited 127 in silence is reported the
     * same way, because 127 is exactly the "command not found" convention.
     */
    public function testASilent127IsReportedAsUnstartableToo(): void
    {
        $result = $this->evaluate('powcRun(["sh", "-c", "exit 127"])');

        self::assertIsArray($result);
        self::assertSame(127, $result['code']);
        self::assertStringContainsString('unable to start', (string) $result['err']);
    }

    public function testRunKeepsAChildsOwnStderrOn127(): void
    {
        $result = $this->evaluate('powcRun(["sh", "-c", "echo boom >&2; exit 127"])');

        self::assertIsArray($result);
        self::assertSame(127, $result['code']);
        self::assertStringContainsString('boom', (string) $result['err']);
        self::assertStringNotContainsString('unable to start', (string) $result['err']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private const HEADER = "| ID | round | file:line | description | severity | status | resolution |\n"
        . "| --- | --- | --- | --- | --- | --- | --- |\n";

    /**
     * Evaluates one expression against `bin/pow-common.php` in a subprocess and
     * decodes the JSON it prints. `$ledger` is the one free variable available
     * to the expression, so table data does not have to be escaped inline.
     */
    private function evaluate(string $expression, string $ledger = ''): mixed
    {
        $script = sprintf(
            "<?php\nrequire %s;\n\$ledger = %s;\necho json_encode(%s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);\n",
            var_export($this->common, true),
            var_export($ledger, true),
            $expression,
        );

        $file = $this->sandbox . '/evaluate-' . bin2hex(random_bytes(4)) . '.php';
        self::assertNotFalse(file_put_contents($file, $script));

        $result = $this->execute([PHP_BINARY, $file]);
        self::assertSame(0, $result['code'], $result['err']);

        return json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, out: string, err: string}
     */
    private function execute(array $cmd): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, $this->sandbox);
        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffers = ['', ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + 30.0;

        while ($open !== [] && microtime(true) < $deadline) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
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

        self::assertSame([], $open, 'the subprocess did not finish within 30s — a pipe deadlock');

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $buffers[0], 'err' => $buffers[1]];
    }
}
