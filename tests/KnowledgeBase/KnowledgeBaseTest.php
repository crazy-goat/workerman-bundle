<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\KnowledgeBase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Convention test for the subagent knowledge base and the project-scoped agents.
 *
 * `bin/kb-lint.php` is the enforcement half; this test re-checks the same
 * invariants against the committed knowledge base and pins the `.pi/agents/`
 * contract, so a broken agent prompt fails the suite rather than the next cycle.
 *
 * It is deliberately *not* an independent implementation: `renderIndex()` here
 * repeats the script's rendering rule, and the front-matter parser below is a
 * laxer regex than the script's tokenizer. A rendering bug would therefore be
 * agreed on by both. What this test does add is that the invariants hold on the
 * real files without running the script — the script's own behaviour is pinned
 * by `KbLintScriptTest`, which drives it as a subprocess over fixtures.
 *
 * @coversNothing
 */
final class KnowledgeBaseTest extends TestCase
{
    private const KB_FILES = [
        'docs/helpers/faq.md' => 'FAQ',
        'docs/helpers/decisions.md' => 'DEC',
    ];

    /** The agents that must live in the repository, not in a per-machine ~/.agents. */
    private const PROJECT_AGENTS = [
        'scout',
        'coder',
        'coder-high',
        'review',
        'review-critical',
        'reviewer',
    ];

    private const REQUIRED_KEYS = ['id', 'date', 'tags', 'trigger', 'hits', 'status'];

    private const KNOWN_STATUSES = ['active', 'promoted', 'stale'];

    private const INDEX_START = '<!-- kb-index:start -->';

    private const INDEX_END = '<!-- kb-index:end -->';

    private string $projectDir;

    protected function setUp(): void
    {
        $projectDir = realpath(__DIR__ . '/../..');

        if ($projectDir === false) {
            self::fail('Cannot determine project root directory.');
        }

        $this->projectDir = $projectDir;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideKnowledgeBaseFiles(): iterable
    {
        foreach (self::KB_FILES as $relative => $prefix) {
            yield $relative => [$relative, $prefix];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideProjectAgents(): iterable
    {
        foreach (self::PROJECT_AGENTS as $agent) {
            yield $agent => [$agent];
        }
    }

    #[DataProvider('provideKnowledgeBaseFiles')]
    public function testEveryEntryCarriesValidFrontMatter(string $relative, string $prefix): void
    {
        $entries = $this->entries($relative);

        self::assertNotSame([], $entries, $relative . ' has no entries');

        foreach ($entries as $entry) {
            $where = sprintf('%s:%d "%s"', $relative, $entry['line'], $entry['title']);
            $meta = $entry['meta'];

            foreach (self::REQUIRED_KEYS as $key) {
                self::assertArrayHasKey($key, $meta, $where . ' is missing front-matter key ' . $key);
            }

            self::assertMatchesRegularExpression(
                '/^' . $prefix . '-\d{3}$/',
                $meta['id'] ?? '',
                $where . ' has an id that does not match ' . $prefix . '-NNN',
            );
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $meta['date'] ?? '', $where . ' has no ISO date');
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9,-]*$/', $meta['tags'] ?? '', $where . ' has malformed tags');
            self::assertNotSame('', $meta['trigger'] ?? '', $where . ' has an empty trigger');
            self::assertMatchesRegularExpression('/^\d+$/', $meta['hits'] ?? '', $where . ' has a non-numeric hits');
            self::assertContains($meta['status'] ?? '', self::KNOWN_STATUSES, $where . ' has an unknown status');

            if (($meta['status'] ?? '') === 'promoted') {
                self::assertNotSame(
                    '',
                    $meta['gate'] ?? '',
                    $where . ' is promoted but does not name the gate that replaced it',
                );
            }
        }
    }

    public function testEntryIdsAreUniqueAcrossBothFiles(): void
    {
        $seen = [];

        foreach (array_keys(self::KB_FILES) as $relative) {
            foreach ($this->entries($relative) as $entry) {
                $id = $entry['meta']['id'] ?? '';
                self::assertArrayNotHasKey($id, $seen, sprintf('id %s is used twice (%s)', $id, $seen[$id] ?? ''));
                $seen[$id] = sprintf('%s:%d', $relative, $entry['line']);
            }
        }

        self::assertNotSame([], $seen);
    }

    #[DataProvider('provideKnowledgeBaseFiles')]
    public function testTagIndexIsInSyncWithTheEntries(string $relative): void
    {
        $lines = $this->lines($relative);
        $start = array_search(self::INDEX_START, array_map(trim(...), $lines), true);
        $end = array_search(self::INDEX_END, array_map(trim(...), $lines), true);

        self::assertIsInt($start, $relative . ' has no tag index start marker');
        self::assertIsInt($end, $relative . ' has no tag index end marker');
        self::assertGreaterThan($start, $end, $relative . ' has its tag index markers in the wrong order');

        $actual = array_values(\array_slice($lines, $start + 1, $end - $start - 1));

        self::assertSame(
            $this->renderIndex($this->entries($relative)),
            $actual,
            $relative . ': the tag index is out of sync with the entries — run `php bin/kb-lint.php --fix`',
        );
    }

    #[DataProvider('provideProjectAgents')]
    public function testProjectAgentIsVersionedInTheRepository(string $agent): void
    {
        $path = $this->projectDir . '/.pi/agents/' . $agent . '.md';

        self::assertFileExists($path, $agent . ' must be project-scoped in .pi/agents/, not user-scoped');

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringStartsWith("---\n", $contents, $agent . ' has no YAML front matter');

        $end = strpos($contents, "\n---\n", 4);
        self::assertIsInt($end, $agent . ' has an unterminated YAML front matter block');

        $frontMatter = substr($contents, 4, $end - 4);

        self::assertSame(
            1,
            preg_match('/^name:\s*(\S+)\s*$/m', $frontMatter, $matches),
            $agent . ' has no parseable "name:" key',
        );
        self::assertSame($agent, $matches[1] ?? '', $agent . '.md declares a different name than its filename');

        foreach (['description', 'tools', 'systemPromptMode'] as $key) {
            self::assertMatchesRegularExpression(
                '/^' . $key . ':\s*\S/m',
                $frontMatter,
                $agent . ' is missing the "' . $key . '" front-matter key',
            );
        }

        $body = substr($contents, $end + 5);
        self::assertStringContainsString(
            'docs/helpers/',
            $body,
            $agent . ' must tell the agent to read the knowledge base',
        );
    }

    public function testProjectAgentsAreExcludedFromTheDistributedPackage(): void
    {
        $attributes = file_get_contents($this->projectDir . '/.gitattributes');

        self::assertIsString($attributes);
        self::assertStringContainsString('/.pi export-ignore', $attributes);
    }

    public function testComposerLintRunsTheKnowledgeBaseLinter(): void
    {
        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertIsArray($composer['scripts'] ?? null);

        $scripts = $composer['scripts'];
        self::assertIsArray($scripts['lint'] ?? null);
        self::assertContains('php bin/kb-lint.php', $scripts['lint'], 'composer lint must run the knowledge-base linter');

        self::assertIsArray($scripts['lint-fix'] ?? null);
        self::assertContains('php bin/kb-lint.php --fix', $scripts['lint-fix']);

        self::assertIsArray($scripts['kb-lint'] ?? null);
        self::assertContains('php bin/kb-lint.php', $scripts['kb-lint']);
    }

    public function testBinReadmeDocumentsKbLint(): void
    {
        $content = file_get_contents($this->projectDir . '/bin/README.md');

        self::assertIsString($content);
        self::assertStringContainsString('### `kb-lint.php`', $content);
        self::assertStringContainsString(
            '0 = clean (warnings may still be printed),',
            $content,
            'the exit codes must be documented',
        );
    }

    public function testWorkflowDocumentsTheAgentMapAndTheSingleWriterRule(): void
    {
        $content = file_get_contents($this->projectDir . '/docs/workflow.md');

        self::assertIsString($content);
        self::assertStringContainsString('## Agent Map', $content);

        foreach (self::PROJECT_AGENTS as $agent) {
            self::assertStringContainsString('`' . $agent . '`', $content, $agent . ' is missing from the agent map');
        }

        self::assertStringContainsString('.pi/agents/', $content, 'the map must say which agents are project-scoped');
        self::assertStringContainsString('review-critical` is mandatory', $content);
        self::assertStringNotContainsString(
            'append learnings after finishing',
            $content,
            'the multi-writer knowledge-base rule was replaced by the single-writer rule',
        );
    }

    public function testHelpersReadmeDocumentsTheSingleWriterFrontMatterAndDecay(): void
    {
        $content = file_get_contents($this->projectDir . '/docs/helpers/README.md');

        self::assertIsString($content);
        self::assertStringContainsString('Only the retro step writes here', $content);
        self::assertStringContainsString('<!-- kb: id=', $content, 'the front-matter grammar must be documented');
        self::assertStringContainsString('## Decay', $content);
        self::assertStringContainsString('promoted', $content);
        self::assertStringContainsString('stale', $content);
        self::assertStringNotContainsString(
            'Append after you finish',
            $content,
            'the old append-after-you-finish rule must be gone',
        );
        self::assertStringNotContainsString('Commit entries as part of the change', $content);
    }

    public function testKbLintExitsZeroOnTheWorkingTree(): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];

        // An explicit environment, so an ambient KB_LINT_ROOT cannot point this
        // run at another tree and turn it green.
        $process = proc_open(
            [\PHP_BINARY, $this->projectDir . '/bin/kb-lint.php'],
            $descriptors,
            $pipes,
            $this->projectDir,
            ['PATH' => (string) getenv('PATH')],
        );

        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), "bin/kb-lint.php failed:\n" . $stdout . $stderr);
        self::assertStringContainsString('kb-lint: OK', $stdout);
        self::assertStringContainsString(
            'kb-lint: root ' . $this->projectDir . "\n",
            $stdout,
            'the output must name the tree that was linted, so a redirected run is visible',
        );
    }

    /**
     * @return list<string>
     */
    private function lines(string $relative): array
    {
        $contents = file_get_contents($this->projectDir . '/' . $relative);

        self::assertIsString($contents, 'Cannot read ' . $relative);

        return explode("\n", str_replace("\r\n", "\n", $contents));
    }

    /**
     * Entries of one knowledge-base file: a `###` heading plus the single-line
     * `<!-- kb: … -->` comment that must follow it.
     *
     * @return list<array{line: int, title: string, meta: array<string, string>}>
     */
    private function entries(string $relative): array
    {
        $lines = $this->lines($relative);
        $entries = [];
        $inFence = false;

        foreach ($lines as $offset => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                $inFence = !$inFence;

                continue;
            }

            if ($inFence || preg_match('/^###\s+(.*)$/', $trimmed, $matches) !== 1) {
                continue;
            }

            $entries[] = [
                'line' => $offset + 1,
                'title' => trim($matches[1]),
                'meta' => $this->parseFrontMatter($lines[$offset + 1] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private function parseFrontMatter(string $line): array
    {
        if (preg_match('/^<!--\s*kb:\s*(.*?)\s*-->$/', trim($line), $matches) !== 1) {
            return [];
        }

        if (preg_match_all('/([a-z_]+)=(?:"([^"]*)"|(\S+))/', $matches[1], $pairs, \PREG_SET_ORDER) === false) {
            return [];
        }

        $meta = [];

        foreach ($pairs as $pair) {
            $meta[$pair[1]] = ($pair[2] ?? '') !== '' ? $pair[2] : ($pair[3] ?? '');
        }

        return $meta;
    }

    /**
     * @param list<array{line: int, title: string, meta: array<string, string>}> $entries
     *
     * @return list<string>
     */
    private function renderIndex(array $entries): array
    {
        $byTag = [];

        foreach ($entries as $entry) {
            $id = $entry['meta']['id'] ?? '';
            $tags = $entry['meta']['tags'] ?? '';

            if ($id === '' || $tags === '') {
                continue;
            }

            foreach (explode(',', $tags) as $tag) {
                $byTag[$tag][$id] = true;
            }
        }

        ksort($byTag);
        $rendered = [];

        foreach ($byTag as $tag => $ids) {
            $list = array_keys($ids);
            sort($list);
            $rendered[] = sprintf('- `%s` — %s', $tag, implode(', ', $list));
        }

        return $rendered;
    }
}
