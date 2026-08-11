<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\KnowledgeBase;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/kb-lint.php` as a subprocess inside a throw-away sandbox.
 *
 * The script only ever reads the knowledge base of the root it is pointed at
 * (`--root=`), so these tests never touch the real `docs/helpers/` and make no
 * network call at all.
 *
 * @coversNothing
 */
final class KbLintScriptTest extends TestCase
{
    private const FAQ = 'docs/helpers/faq.md';

    private const DECISIONS = 'docs/helpers/decisions.md';

    private string $sandbox = '';

    private string $script = '';

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__, 2) . '/bin/kb-lint.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/kb-lint-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox . '/docs/helpers', 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }
    }

    public function testAValidKnowledgeBasePasses(): void
    {
        $this->writeValidKnowledgeBase();

        $result = $this->kbLint();

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('kb-lint: OK — 3 entries', $result['out']);
        self::assertSame('', $result['err']);
    }

    public function testMissingFrontMatterFails(): void
    {
        $this->writeValidKnowledgeBase();
        $this->write(self::FAQ, $this->file('FAQ', [
            ['title' => 'Undocumented entry', 'meta' => null, 'body' => 'No front matter at all.'],
        ]));

        $result = $this->kbLint();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('single-line "<!-- kb: key=value … -->" comment', $result['err']);
        self::assertStringContainsString('kb-lint: FAILED', $result['err']);
    }

    public function testMalformedFrontMatterFails(): void
    {
        $this->writeValidKnowledgeBase();
        $this->write(self::FAQ, $this->file('FAQ', [
            [
                'title' => 'Broken keys',
                'meta' => 'id=FAQ-001 date=not-a-date tags=alpha trigger="x" hits=many status=unknown',
                'body' => 'Body.',
            ],
        ]));

        $result = $this->kbLint();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('is not an ISO-8601 calendar date', $result['err']);
        self::assertStringContainsString('hits "many" must be a non-negative integer', $result['err']);
        self::assertStringContainsString('unknown status "unknown"', $result['err']);
    }

    public function testDuplicateIdAcrossBothFilesFails(): void
    {
        $this->writeValidKnowledgeBase();
        $this->write(self::DECISIONS, $this->file('DEC', [
            [
                'title' => 'A decision reusing an id',
                'meta' => 'id=DEC-001 date=2026-08-11 tags=policy trigger="never" hits=0 status=active',
                'body' => 'First.',
            ],
            [
                'title' => 'Another decision reusing the same id',
                'meta' => 'id=DEC-001 date=2026-08-11 tags=policy trigger="never" hits=0 status=active',
                'body' => 'Second.',
            ],
        ]));

        $result = $this->kbLint();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('duplicate id DEC-001', $result['err']);
    }

    public function testAPromotedEntryMustNameItsGateAndStayShort(): void
    {
        $this->writeValidKnowledgeBase();
        $this->write(self::FAQ, $this->file('FAQ', [
            [
                'title' => 'Promoted but still verbose',
                'meta' => 'id=FAQ-001 date=2026-08-11 tags=alpha trigger="x" hits=3 status=promoted',
                'body' => "One.\n\nTwo.\n\nThree.",
            ],
        ]));

        $result = $this->kbLint();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('must name the gate that replaced it', $result['err']);
        self::assertStringContainsString('collapses to at most 2 line(s)', $result['err']);
    }

    public function testAnOverLongFileIsAWarningNotAFailure(): void
    {
        $this->writeValidKnowledgeBase();
        $this->write(self::FAQ, $this->file('FAQ', [
            [
                'title' => 'A very long entry',
                'meta' => 'id=FAQ-001 date=2026-08-11 tags=alpha trigger="x" hits=0 status=active',
                'body' => implode("\n", array_fill(0, 320, 'padding line, deliberately verbose.')),
            ],
        ]));

        $result = $this->kbLint();

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('is over the 300-line budget', $result['out']);
    }

    public function testStaleEntriesAreListedButDoNotFail(): void
    {
        $this->writeValidKnowledgeBase();
        $this->write(self::FAQ, $this->file('FAQ', [
            [
                'title' => 'Nobody has needed this in twenty cycles',
                'meta' => 'id=FAQ-001 date=2026-01-02 tags=alpha trigger="x" hits=0 status=stale',
                'body' => 'Body.',
            ],
        ]));

        $result = $this->kbLint();

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('kb-lint: stale (0 hits in 20 cycles', $result['out']);
        self::assertStringContainsString('FAQ-001', $result['out']);
        self::assertStringContainsString('1 stale', $result['out']);
    }

    public function testFixRegeneratesAnOutOfSyncTagIndex(): void
    {
        $this->writeValidKnowledgeBase();
        $faq = $this->read(self::FAQ);
        $this->write(self::FAQ, str_replace('- `alpha` — FAQ-001', '- `wrong` — FAQ-999', $faq));

        self::assertSame(1, $this->kbLint()['code'], 'an out-of-sync index must fail without --fix');

        $fixed = $this->kbLint('--fix');
        self::assertSame(0, $fixed['code'], $fixed['err']);
        self::assertStringContainsString('tag index regenerated', $fixed['out']);

        self::assertSame(0, $this->kbLint()['code']);
        self::assertStringContainsString('- `alpha` — FAQ-001', $this->read(self::FAQ));
    }

    public function testFixCreatesAMissingTagIndex(): void
    {
        $this->writeValidKnowledgeBase();
        $faq = $this->read(self::FAQ);
        $this->write(self::FAQ, preg_replace('/## Tag index\n\n<!-- kb-index:start -->\n.*?<!-- kb-index:end -->\n\n/s', '', $faq) ?? '');

        $missing = $this->kbLint();
        self::assertSame(1, $missing['code']);
        self::assertStringContainsString('no tag index', $missing['err']);

        $fixed = $this->kbLint('--fix');
        self::assertSame(0, $fixed['code'], $fixed['err']);
        self::assertStringContainsString('tag index created', $fixed['out']);
        self::assertSame(0, $this->kbLint()['code']);
    }

    public function testJsonOutputIsMachineReadable(): void
    {
        $this->writeValidKnowledgeBase();

        $result = $this->kbLint('--json');

        self::assertSame(0, $result['code'], $result['err']);

        $payload = json_decode($result['out'], true);
        self::assertIsArray($payload);
        self::assertTrue($payload['ok'] ?? null);
        self::assertSame(3, $payload['entries'] ?? null);
        self::assertSame(300, $payload['line_budget'] ?? null);
        self::assertSame([], $payload['errors'] ?? null);
    }

    public function testAMissingKnowledgeBaseFileFails(): void
    {
        $this->writeValidKnowledgeBase();
        unlink($this->path(self::DECISIONS));

        $result = $this->kbLint();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('knowledge-base file is missing', $result['err']);
    }

    public function testAnUnknownOptionIsAUsageError(): void
    {
        $this->writeValidKnowledgeBase();

        $result = $this->kbLint('--nope');

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('Unknown argument: --nope', $result['err']);
    }

    /**
     * Two entries, one per file, plus a second FAQ entry so the index has more
     * than one row. The index is already correct, so the base fixture passes.
     */
    private function writeValidKnowledgeBase(): void
    {
        $this->write(self::FAQ, $this->file('FAQ', [
            [
                'title' => 'The daemon binds ports 8888 and 9999',
                'meta' => 'id=FAQ-001 date=2026-08-08 tags=alpha trigger="composer test fails to connect" hits=2 status=active',
                'body' => 'A stale daemon holds the ports; stop it before running the suite again.',
            ],
            [
                'title' => 'Coverage floor lives in composer.json',
                'meta' => 'id=FAQ-002 date=2026-08-09 tags=beta trigger="coverage gate" hits=0 status=promoted gate="tests/CoverageCiGateTest.php"',
                'body' => 'Promoted — asserted by tests/CoverageCiGateTest.php.',
            ],
        ]));

        $this->write(self::DECISIONS, $this->file('DEC', [
            [
                'title' => 'Only the retro step writes to the knowledge base',
                'meta' => 'id=DEC-001 date=2026-08-11 tags=policy trigger="learning something durable" hits=1 status=active',
                'body' => 'Coder and review propose candidate entries; the retro decides what lands.',
            ],
        ]));
    }

    /**
     * Renders a whole knowledge-base file, tag index included, from entry specs.
     *
     * @param list<array{title: string, meta: string|null, body: string}> $entries
     */
    private function file(string $prefix, array $entries): string
    {
        $byTag = [];

        foreach ($entries as $entry) {
            if ($entry['meta'] === null) {
                continue;
            }

            if (preg_match('/id=(\S+)/', $entry['meta'], $id) !== 1) {
                continue;
            }

            if (preg_match('/tags=(\S+)/', $entry['meta'], $tags) !== 1) {
                continue;
            }

            foreach (explode(',', $tags[1]) as $tag) {
                $byTag[$tag][$id[1]] = true;
            }
        }

        ksort($byTag);
        $index = [];

        foreach ($byTag as $tag => $ids) {
            $list = array_keys($ids);
            sort($list);
            $index[] = sprintf('- `%s` — %s', $tag, implode(', ', $list));
        }

        $rendered = '# ' . $prefix . " fixture\n\n## Tag index\n\n<!-- kb-index:start -->\n"
            . implode("\n", $index === [] ? ['- _(no entries)_'] : $index)
            . "\n<!-- kb-index:end -->\n\n## Section\n";

        foreach ($entries as $entry) {
            $rendered .= "\n### " . $entry['title'] . "\n";

            if ($entry['meta'] !== null) {
                $rendered .= '<!-- kb: ' . $entry['meta'] . " -->\n";
            }

            $rendered .= "\n" . $entry['body'] . "\n";
        }

        return $rendered;
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function kbLint(string ...$args): array
    {
        $command = array_merge(
            [\PHP_BINARY, $this->script, '--root=' . $this->sandbox],
            array_values($args),
        );

        $outFile = $this->sandbox . '/stdout.log';
        $errFile = $this->sandbox . '/stderr.log';

        $descriptors = [
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        $pipes = [];

        $process = proc_open($command, $descriptors, $pipes, $this->sandbox, ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);

        $code = proc_close($process);

        return [
            'code' => $code,
            'out' => (string) file_get_contents($outFile),
            'err' => (string) file_get_contents($errFile),
        ];
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
