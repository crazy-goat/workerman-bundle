<?php

declare(strict_types=1);

const ROOT_ENV = 'KB_LINT_ROOT';

const KB_FILES = [
    'docs/helpers/faq.md' => 'FAQ',
    'docs/helpers/decisions.md' => 'DEC',
];

const REQUIRED_KEYS = ['id', 'date', 'tags', 'trigger', 'hits', 'status'];
const OPTIONAL_KEYS = ['gate'];
const KNOWN_STATUSES = ['active', 'promoted', 'stale'];

const LINE_BUDGET = 300;

const PROMOTED_MAX_BODY_LINES = 2;

const STALE_AFTER_CYCLES = 20;

const DUPLICATE_THRESHOLD = 0.75;

const DUPLICATE_MIN_TOKENS = 15;

const INDEX_START = '<!-- kb-index:start -->';
const INDEX_END = '<!-- kb-index:end -->';

const STOP_WORDS = [
    'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from', 'has', 'have', 'in', 'into',
    'is', 'it', 'its', 'no', 'not', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'were', 'when',
    'which', 'with', 'you', 'your',
];

/**
 * @phpstan-type Entry array{
 *     file: string,
 *     line: int,
 *     title: string,
 *     meta: array<string, string>,
 *     body: list<string>,
 * }
 */

/** @return list<string> */
function readLines(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "kb-lint: cannot read $path\n");
        exit(1);
    }

    return explode("\n", str_replace("\r\n", "\n", $contents));
}

/**
 * @return array{pairs: array<string, string>, error: ?string}
 */
function parseFrontMatter(string $line): array
{
    $trimmed = trim($line);

    if (preg_match('/^<!--\s*kb:\s*(.*?)\s*-->$/', $trimmed, $matches) !== 1) {
        return ['pairs' => [], 'error' => 'front matter must be a single-line "<!-- kb: key=value … -->" comment'];
    }

    $spec = $matches[1];
    $length = \strlen($spec);
    $pairs = [];
    $i = 0;

    while ($i < $length) {
        if (ctype_space($spec[$i])) {
            $i++;

            continue;
        }

        $pair = readFrontMatterPair($spec, $i);
        $error = $pair['error'] ?? frontMatterPairError($pairs, $pair['key'], $pair['value']);

        if ($error !== null) {
            return ['pairs' => [], 'error' => $error];
        }

        $pairs[$pair['key']] = $pair['value'];
        $i = $pair['end'];
    }

    return ['pairs' => $pairs, 'error' => null];
}

/**
 * @return array{key: string, value: string, end: int, error: ?string}
 */
function readFrontMatterPair(string $spec, int $keyStart): array
{
    $length = \strlen($spec);
    $i = $keyStart;
    while ($i < $length && (ctype_alpha($spec[$i]) || $spec[$i] === '_')) {
        $i++;
    }
    $key = substr($spec, $keyStart, $i - $keyStart);

    if ($key === '' || $i >= $length || $spec[$i] !== '=') {
        return ['key' => $key, 'value' => '', 'end' => $i, 'error' => 'malformed front matter: expected key=value at offset ' . $keyStart];
    }

    $i++;

    if ($i < $length && $spec[$i] === '"') {
        return readQuotedFrontMatterValue($spec, $key, $i + 1);
    }

    $valueStart = $i;
    while ($i < $length && !ctype_space($spec[$i])) {
        $i++;
    }

    return ['key' => $key, 'value' => substr($spec, $valueStart, $i - $valueStart), 'end' => $i, 'error' => null];
}

/**
 * @return array{key: string, value: string, end: int, error: ?string}
 */
function readQuotedFrontMatterValue(string $spec, string $key, int $valueStart): array
{
    $closingQuote = strpos($spec, '"', $valueStart);

    if ($closingQuote === false) {
        return ['key' => $key, 'value' => '', 'end' => \strlen($spec), 'error' => sprintf('unterminated quoted value for "%s"', $key)];
    }

    return ['key' => $key, 'value' => substr($spec, $valueStart, $closingQuote - $valueStart), 'end' => $closingQuote + 1, 'error' => null];
}

/**
 * @param array<string, string> $pairs
 */
function frontMatterPairError(array $pairs, string $key, string $value): ?string
{
    if (isset($pairs[$key])) {
        return sprintf('duplicate front-matter key "%s"', $key);
    }

    if (str_contains($value, '-->')) {
        return sprintf('front-matter value for "%s" must not contain "-->"', $key);
    }

    return null;
}

/**
 * @param list<string> $lines
 * @param array{start: int, end: int, body: list<string>} $index
 */
function indexFootprint(array $lines, array $index): int
{
    $footprint = $index['end'] - $index['start'] + 1;

    if (trim($lines[$index['end']] ?? 'x') === '') {
        $footprint++;
    }

    if (trim($lines[$index['start'] - 2] ?? 'x') !== '') {
        return $footprint;
    }

    $footprint++;

    if (preg_match('/^#{1,3}\s+Tag index$/', trim($lines[$index['start'] - 3] ?? '')) === 1) {
        $footprint++;
    }

    return $footprint;
}

/**
 * @return array{
 *     entries: list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}>,
 *     errors: list<string>,
 *     lines: int,
 *     index: ?array{start: int, end: int, body: list<string>, footprint: int},
 *     first_section: ?int,
 *     index_heading: ?int
 * }
 */
function parseFile(string $relative, string $absolute): array
{
    return (new KbFileParser($relative, readLines($absolute)))->parse();
}

final class KbFileParser
{
    /** @var list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> */
    private array $entries = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var array{start: int, end: int, body: list<string>, footprint: int}|null */
    private ?array $index = null;

    private ?int $firstSection = null;

    private ?int $staleHeading = null;

    private bool $inFence = false;

    private ?int $indexStart = null;

    private ?int $currentEntry = null;

    /**
     * @param list<string> $lines
     */
    public function __construct(
        private readonly string $relative,
        private readonly array $lines,
    ) {
    }

    /**
     * @return array{
     *     entries: list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}>,
     *     errors: list<string>,
     *     lines: int,
     *     index: ?array{start: int, end: int, body: list<string>, footprint: int},
     *     first_section: ?int,
     *     index_heading: ?int
     * }
     */
    public function parse(): array
    {
        foreach ($this->lines as $offset => $line) {
            $this->parseLine($offset, $line);
        }

        if ($this->indexStart !== null) {
            $this->errors[] = sprintf('%s:%d: tag index start marker is never closed', $this->relative, $this->indexStart);
        }

        if ($this->index !== null) {
            $this->index['footprint'] = indexFootprint($this->lines, $this->index);
        }

        return [
            'entries' => array_map($this->withoutFrontMatterLine(...), $this->entries),
            'errors' => $this->errors,
            'lines' => $this->countLines(),
            'index' => $this->index,
            'first_section' => $this->firstSection,
            'index_heading' => $this->staleHeading,
        ];
    }

    private function parseLine(int $offset, string $line): void
    {
        $number = $offset + 1;
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '```')) {
            $this->inFence = !$this->inFence;
        }

        if (!$this->inFence && $trimmed === INDEX_START) {
            $this->indexStart = $number;

            return;
        }

        if (!$this->inFence && $trimmed === INDEX_END) {
            $this->closeIndex($number);

            return;
        }

        if ($this->inFence || !str_starts_with($trimmed, '#')) {
            $this->appendToCurrentEntry($line);

            return;
        }

        if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $matches) === 1) {
            $this->openHeading($offset, $matches[1], trim($matches[2]));
        }
    }

    private function closeIndex(int $number): void
    {
        if ($this->indexStart === null) {
            $this->errors[] = sprintf('%s:%d: tag index end marker without a start marker', $this->relative, $number);

            return;
        }

        if ($this->index !== null) {
            $this->errors[] = sprintf('%s:%d: more than one tag index block — keep exactly one', $this->relative, $number);
            $this->indexStart = null;

            return;
        }

        $this->index = [
            'start' => $this->indexStart,
            'end' => $number,
            'body' => \array_slice($this->lines, $this->indexStart, $number - $this->indexStart - 1),
            'footprint' => 0,
        ];
        $this->indexStart = null;
    }

    private function appendToCurrentEntry(string $line): void
    {
        if ($this->currentEntry !== null) {
            $this->entries[$this->currentEntry]['body'][] = $line;
        }
    }

    private function openHeading(int $offset, string $level, string $title): void
    {
        $number = $offset + 1;

        if ($level === '##' && $this->firstSection === null) {
            $this->firstSection = $number;
        }

        if ($this->staleHeading === null && $title === 'Tag index') {
            $this->staleHeading = $number;
        }

        $this->currentEntry = null;

        if ($level !== '###') {
            return;
        }

        $parsed = parseFrontMatter($this->lines[$offset + 1] ?? '');

        if ($parsed['error'] !== null) {
            $this->errors[] = sprintf('%s:%d: "%s": %s', $this->relative, $number + 1, $title, $parsed['error']);
        }

        $this->entries[] = [
            'file' => $this->relative,
            'line' => $number,
            'title' => $title,
            'meta' => $parsed['pairs'],
            'body' => [],
        ];
        $this->currentEntry = \count($this->entries) - 1;
    }

    /**
     * @param array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>} $entry
     * @return array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}
     */
    private function withoutFrontMatterLine(array $entry): array
    {
        if ($entry['body'] !== [] && str_starts_with(trim($entry['body'][0]), '<!--')) {
            $entry['body'] = \array_slice($entry['body'], 1);
        }

        return $entry;
    }

    private function countLines(): int
    {
        $lineCount = \count($this->lines);
        if ($lineCount > 0 && $this->lines[$lineCount - 1] === '') {
            $lineCount--;
        }

        return $lineCount;
    }
}

/**
 * @param array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>} $entry
 * @return list<string>
 */
function validateEntry(array $entry, string $prefix): array
{
    $meta = $entry['meta'];
    $where = sprintf('%s:%d: "%s"', $entry['file'], $entry['line'], $entry['title']);

    if ($meta === []) {
        return [];
    }

    return [
        ...frontMatterKeyErrors($meta, $where),
        ...identityErrors($meta, $where, $prefix),
        ...tagErrors($meta['tags'] ?? '', $where),
        ...lifecycleErrors($meta, $where),
        ...promotedEntryErrors($entry, $where),
    ];
}

/**
 * @param array<string, string> $meta
 * @return list<string>
 */
function frontMatterKeyErrors(array $meta, string $where): array
{
    $errors = [];

    foreach (REQUIRED_KEYS as $key) {
        if (!isset($meta[$key]) || trim($meta[$key]) === '') {
            $errors[] = sprintf('%s: missing front-matter key "%s"', $where, $key);
        }
    }

    foreach (array_keys($meta) as $key) {
        if (!\in_array($key, REQUIRED_KEYS, true) && !\in_array($key, OPTIONAL_KEYS, true)) {
            $errors[] = sprintf('%s: unknown front-matter key "%s"', $where, $key);
        }
    }

    return $errors;
}

/**
 * @param array<string, string> $meta
 * @return list<string>
 */
function identityErrors(array $meta, string $where, string $prefix): array
{
    $errors = [];

    $id = $meta['id'] ?? '';
    if ($id !== '' && preg_match('/^' . $prefix . '-\d{3}$/', $id) !== 1) {
        $errors[] = sprintf('%s: id "%s" must match %s-NNN', $where, $id, $prefix);
    }

    $date = $meta['date'] ?? '';
    if ($date !== '' && !isIsoDate($date)) {
        $errors[] = sprintf('%s: date "%s" is not an ISO-8601 calendar date (YYYY-MM-DD)', $where, $date);
    }

    return $errors;
}

/**
 * @return list<string>
 */
function tagErrors(string $tags, string $where): array
{
    if ($tags === '') {
        return [];
    }

    $errors = [];
    $seen = [];
    foreach (explode(',', $tags) as $tag) {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $tag) !== 1) {
            $errors[] = sprintf('%s: tag "%s" must be lowercase [a-z0-9-]', $where, $tag);

            continue;
        }
        if (isset($seen[$tag])) {
            $errors[] = sprintf('%s: tag "%s" is repeated', $where, $tag);
        }
        $seen[$tag] = true;
    }

    return $errors;
}

/**
 * @param array<string, string> $meta
 * @return list<string>
 */
function lifecycleErrors(array $meta, string $where): array
{
    $errors = [];

    $hits = $meta['hits'] ?? '';
    if ($hits !== '' && preg_match('/^\d+$/', $hits) !== 1) {
        $errors[] = sprintf('%s: hits "%s" must be a non-negative integer', $where, $hits);
    }

    $status = $meta['status'] ?? '';
    if ($status !== '' && !\in_array($status, KNOWN_STATUSES, true)) {
        $errors[] = sprintf(
            '%s: unknown status "%s" (expected %s)',
            $where,
            $status,
            implode('|', KNOWN_STATUSES),
        );
    }

    return $errors;
}

/**
 * @param array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>} $entry
 * @return list<string>
 */
function promotedEntryErrors(array $entry, string $where): array
{
    if (($entry['meta']['status'] ?? '') !== 'promoted') {
        return [];
    }

    $errors = [];

    if (($entry['meta']['gate'] ?? '') === '') {
        $errors[] = sprintf('%s: a promoted entry must name the gate that replaced it (gate="…")', $where);
    }

    $bodyLines = \count(array_filter($entry['body'], static fn(string $line): bool => trim($line) !== ''));
    if ($bodyLines > PROMOTED_MAX_BODY_LINES) {
        $errors[] = sprintf(
            '%s: a promoted entry collapses to at most %d line(s) plus its gate link, found %d',
            $where,
            PROMOTED_MAX_BODY_LINES,
            $bodyLines,
        );
    }

    return $errors;
}

function isIsoDate(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

/**
 * @param array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>} $entry
 * @return array<string, true>
 */
function tokenSet(array $entry): array
{
    $text = strtolower($entry['title'] . ' ' . implode(' ', $entry['body']));
    $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
    $tokens = [];

    foreach (explode(' ', $text) as $token) {
        if (\strlen($token) < 3 || \in_array($token, STOP_WORDS, true)) {
            continue;
        }
        $tokens[$token] = true;
    }

    return $tokens;
}

/**
 * @param array<string, true> $a
 * @param array<string, true> $b
 */
function overlapCoefficient(array $a, array $b): float
{
    $smaller = min(\count($a), \count($b));

    if ($smaller === 0) {
        return 0.0;
    }

    return \count(array_intersect_key($a, $b)) / $smaller;
}

/**
 * @param list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> $entries
 * @return list<string>
 */
function renderIndex(array $entries): array
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

    return $rendered === [] ? ['- _(no entries)_'] : $rendered;
}

/**
 * @param list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> $entries
 * @param array{start: int, end: int, body: list<string>, footprint: int}|null $index
 */
function writeIndex(string $absolute, array $entries, ?array $index, ?int $firstSection): void
{
    $lines = readLines($absolute);
    $rendered = renderIndex($entries);

    if ($index !== null) {
        $head = \array_slice($lines, 0, $index['start']);
        $tail = \array_slice($lines, $index['end'] - 1);
        $lines = [...$head, ...$rendered, ...$tail];
    } else {
        $at = $firstSection !== null ? $firstSection - 1 : \count($lines);

        $headEnd = $at;
        while ($headEnd > 0 && trim($lines[$headEnd - 1] ?? '') === '') {
            --$headEnd;
        }

        $section = ['## Tag index', '', INDEX_START, ...$rendered, INDEX_END, ''];

        if ($headEnd > 0) {
            $section = ['', ...$section];
        }

        $lines = [...\array_slice($lines, 0, $headEnd), ...$section, ...\array_slice($lines, $at)];
    }

    file_put_contents($absolute, rtrim(implode("\n", $lines), "\n") . "\n");
}

function normalizeTrailingNewline(string $absolute): void
{
    $contents = file_get_contents($absolute);

    if ($contents === false) {
        return;
    }

    if (str_contains($contents, "\r")) {
        return;
    }

    $normalized = rtrim($contents, "\n") . "\n";

    if ($normalized === $contents) {
        return;
    }

    file_put_contents($absolute, $normalized);
}

/** @param list<string> $args */
function printUsage(array $args): void
{
    fwrite(STDOUT, ($args[0] ?? 'bin/kb-lint.php') . " — lint the docs/helpers/ knowledge base\n\n");
    fwrite(STDOUT, "Usage: php bin/kb-lint.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --fix               regenerate the tag index of every knowledge-base file and\n");
    fwrite(STDOUT, "                      normalise its trailing newline\n");
    fwrite(STDOUT, "  --json              machine-readable output (JSON on stdout)\n");
    fwrite(STDOUT, "  --root=DIR          repository root (default: the parent of bin/)\n");
    fwrite(STDOUT, "  --help              show this help\n\n");
    fwrite(STDOUT, "Environment:\n");
    fwrite(STDOUT, '  ' . ROOT_ENV . "        same as --root=, overridden by it; reported as a warning\n");
}

/**
 * @param list<string> $argv
 * @return array{fix: bool, json: bool, root: string, root_from_env: bool}
 */
function parseArgs(array $argv): array
{
    $envRoot = getenv(ROOT_ENV);
    $fromEnv = \is_string($envRoot) && $envRoot !== '';

    $options = [
        'fix' => false,
        'json' => false,
        'root' => $fromEnv ? (string) $envRoot : \dirname(__DIR__),
        'root_from_env' => $fromEnv,
    ];

    foreach (\array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            printUsage($argv);
            exit(0);
        }

        if ($arg === '--fix') {
            $options['fix'] = true;

            continue;
        }

        if ($arg === '--json') {
            $options['json'] = true;

            continue;
        }

        if (str_starts_with($arg, '--root=')) {
            $options['root'] = substr($arg, 7);
            $options['root_from_env'] = false;

            continue;
        }

        fwrite(STDERR, "Unknown argument: $arg (see --help)\n");
        exit(2);
    }

    $root = realpath($options['root']);

    if ($root === false) {
        fwrite(STDERR, sprintf("Root directory does not exist: %s\n", $options['root']));
        exit(2);
    }

    $options['root'] = $root;

    return $options;
}

/**
 * @param list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> $entries
 * @param array<string, string> $seenIds
 * @param-out array<string, string> $seenIds
 * @return array{errors: list<string>, stale: list<string>}
 */
function checkEntries(array $entries, string $prefix, array &$seenIds): array
{
    $errors = [];
    $stale = [];

    foreach ($entries as $entry) {
        $errors = [...$errors, ...validateEntry($entry, $prefix)];

        $id = $entry['meta']['id'] ?? '';
        $location = sprintf('%s:%d', $entry['file'], $entry['line']);

        if ($id !== '' && isset($seenIds[$id])) {
            $errors[] = sprintf('%s: duplicate id %s (already used by %s)', $location, $id, $seenIds[$id]);
        } elseif ($id !== '') {
            $seenIds[$id] = $location;
        }

        if (($entry['meta']['status'] ?? '') === 'stale') {
            $stale[] = sprintf('%s: %s "%s"', $location, $id, $entry['title']);
        }
    }

    return ['errors' => $errors, 'stale' => $stale];
}

/**
 * @param array{
 *     entries: list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}>,
 *     errors: list<string>,
 *     lines: int,
 *     index: ?array{start: int, end: int, body: list<string>, footprint: int},
 *     first_section: ?int,
 *     index_heading: ?int
 * } $parsed
 * @return array{errors: list<string>, warnings: list<string>}
 */
function checkIndex(string $relative, string $absolute, array $parsed, bool $fix): array
{
    if ($parsed['index'] === null && $parsed['index_heading'] !== null) {
        return ['errors' => [sprintf(
            '%s:%d: a "Tag index" heading but no index markers (add %s / %s below it, or remove the heading, then run --fix)',
            $relative,
            $parsed['index_heading'],
            INDEX_START,
            INDEX_END,
        )], 'warnings' => []];
    }

    if ($parsed['index'] === null) {
        if (!$fix) {
            return ['errors' => [sprintf('%s: no tag index (add %s / %s, or run --fix)', $relative, INDEX_START, INDEX_END)], 'warnings' => []];
        }

        writeIndex($absolute, $parsed['entries'], null, $parsed['first_section']);

        return ['errors' => [], 'warnings' => [sprintf('%s: tag index created', $relative)]];
    }

    if (renderIndex($parsed['entries']) === $parsed['index']['body']) {
        return ['errors' => [], 'warnings' => []];
    }

    if (!$fix) {
        return ['errors' => [sprintf('%s: tag index is out of sync with the entries (run --fix)', $relative)], 'warnings' => []];
    }

    writeIndex($absolute, $parsed['entries'], $parsed['index'], $parsed['first_section']);

    return ['errors' => [], 'warnings' => [sprintf('%s: tag index regenerated', $relative)]];
}

/**
 * @param array<string, string> $seenIds
 * @param-out array<string, string> $seenIds
 * @return array{errors: list<string>, warnings: list<string>, stale: list<string>, entries: list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}>, file: array{path: string, entries: int, lines: int, budgeted_lines: int, over_budget: bool}}
 */
function lintFile(string $relative, string $absolute, string $prefix, bool $fix, array &$seenIds): array
{
    $parsed = parseFile($relative, $absolute);
    $checked = checkEntries($parsed['entries'], $prefix, $seenIds);
    $warnings = [];

    $indexLines = $parsed['index'] !== null ? $parsed['index']['footprint'] : 0;
    $budgeted = $parsed['lines'] - $indexLines;

    if ($budgeted > LINE_BUDGET) {
        $warnings[] = sprintf(
            '%s: %d lines (index excluded) is over the %d-line budget — promote or drop entries',
            $relative,
            $budgeted,
            LINE_BUDGET,
        );
    }

    $index = checkIndex($relative, $absolute, $parsed, $fix);

    if ($fix) {
        normalizeTrailingNewline($absolute);
    }

    return [
        'errors' => [...$parsed['errors'], ...$checked['errors'], ...$index['errors']],
        'warnings' => [...$warnings, ...$index['warnings']],
        'stale' => $checked['stale'],
        'entries' => $parsed['entries'],
        'file' => [
            'path' => $relative,
            'entries' => \count($parsed['entries']),
            'lines' => $parsed['lines'],
            'budgeted_lines' => $budgeted,
            'over_budget' => $budgeted > LINE_BUDGET,
        ],
    ];
}

/**
 * @param list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> $allEntries
 * @return list<string>
 */
function findDuplicates(array $allEntries): array
{
    $duplicates = [];
    $comparable = array_values(array_filter(
        $allEntries,
        static fn(array $entry): bool => ($entry['meta']['status'] ?? '') !== 'promoted',
    ));
    $tokens = array_map(tokenSet(...), $comparable);

    for ($i = 0, $count = \count($comparable); $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            if (max(\count($tokens[$i]), \count($tokens[$j])) < DUPLICATE_MIN_TOKENS) {
                continue;
            }

            $score = overlapCoefficient($tokens[$i], $tokens[$j]);

            if ($score < DUPLICATE_THRESHOLD) {
                continue;
            }

            $duplicates[] = sprintf(
                '%s (%s:%d) and %s (%s:%d) overlap %.0f%% — merge them or make the difference explicit',
                $comparable[$i]['meta']['id'] ?? '?',
                $comparable[$i]['file'],
                $comparable[$i]['line'],
                $comparable[$j]['meta']['id'] ?? '?',
                $comparable[$j]['file'],
                $comparable[$j]['line'],
                $score * 100,
            );
        }
    }

    return $duplicates;
}

/**
 * @param array{fix: bool, json: bool, root: string, root_from_env: bool} $options
 * @param array{errors: list<string>, warnings: list<string>, stale: list<string>, duplicates: list<string>, files: list<array{path: string, entries: int, lines: int, budgeted_lines: int, over_budget: bool}>, entries: int} $report
 */
function printJsonReport(array $options, array $report): int
{
    $ok = $report['errors'] === [];

    echo json_encode([
        'ok' => $ok,
        'root' => $options['root'],
        'root_from_env' => $options['root_from_env'],
        'files' => $report['files'],
        'entries' => $report['entries'],
        'errors' => $report['errors'],
        'warnings' => $report['warnings'],
        'stale' => $report['stale'],
        'duplicates' => $report['duplicates'],
        'line_budget' => LINE_BUDGET,
        'stale_after_cycles' => STALE_AFTER_CYCLES,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    return $ok ? 0 : 1;
}

/**
 * @param array{fix: bool, json: bool, root: string, root_from_env: bool} $options
 * @param array{errors: list<string>, warnings: list<string>, stale: list<string>, duplicates: list<string>, files: list<array{path: string, entries: int, lines: int, budgeted_lines: int, over_budget: bool}>, entries: int} $report
 */
function printTextReport(array $options, array $report): int
{
    fwrite(STDOUT, sprintf("kb-lint: root %s\n", $options['root']));

    foreach ($report['files'] as $file) {
        fwrite(STDOUT, sprintf(
            "kb-lint: %s — %d entries, %d lines (%d budgeted, limit %d)\n",
            $file['path'],
            $file['entries'],
            $file['lines'],
            $file['budgeted_lines'],
            LINE_BUDGET,
        ));
    }

    foreach ($report['stale'] as $entry) {
        fwrite(STDOUT, sprintf("kb-lint: stale (0 hits in %d cycles, remove at the next retro): %s\n", STALE_AFTER_CYCLES, $entry));
    }

    foreach ($report['warnings'] as $warning) {
        fwrite(STDOUT, 'kb-lint: warning: ' . $warning . "\n");
    }

    foreach ($report['errors'] as $error) {
        fwrite(STDERR, 'kb-lint: error: ' . $error . "\n");
    }

    if ($report['errors'] !== []) {
        fwrite(STDERR, sprintf("kb-lint: FAILED — %d error(s)\n", \count($report['errors'])));

        return 1;
    }

    fwrite(STDOUT, sprintf(
        "kb-lint: OK — %d entries, %d warning(s), %d stale\n",
        $report['entries'],
        \count($report['warnings']),
        \count($report['stale']),
    ));

    return 0;
}

/**
 * @param array{fix: bool, json: bool, root: string, root_from_env: bool} $options
 */
function main(array $options): int
{
    $errors = [];
    $warnings = $options['root_from_env'] ? [sprintf('root overridden via %s', ROOT_ENV)] : [];
    $stale = [];
    $files = [];
    $allEntries = [];
    $seenIds = [];

    foreach (KB_FILES as $relative => $prefix) {
        $absolute = $options['root'] . '/' . $relative;

        if (!is_file($absolute)) {
            $errors[] = sprintf('%s: knowledge-base file is missing', $relative);

            continue;
        }

        $linted = lintFile($relative, $absolute, $prefix, $options['fix'], $seenIds);
        $errors = [...$errors, ...$linted['errors']];
        $warnings = [...$warnings, ...$linted['warnings']];
        $stale = [...$stale, ...$linted['stale']];
        $allEntries = [...$allEntries, ...$linted['entries']];
        $files[] = $linted['file'];
    }

    $duplicates = findDuplicates($allEntries);
    $report = [
        'errors' => $errors,
        'warnings' => [...$warnings, ...$duplicates],
        'stale' => $stale,
        'duplicates' => $duplicates,
        'files' => $files,
        'entries' => \count($allEntries),
    ];

    exit($options['json'] ? printJsonReport($options, $report) : printTextReport($options, $report));
}

try {
    main(parseArgs($_SERVER['argv'] ?? []));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
