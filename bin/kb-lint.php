<?php

declare(strict_types=1);

/**
 * Lints the subagent knowledge base under docs/helpers/.
 *
 * The knowledge base is read by every implementation and review subagent, so
 * it has to stay small, tagged and searchable. This script enforces that:
 * every entry carries machine-readable front matter, ids are unique, the tag
 * index is in sync with the entries, near-duplicates are reported, and the
 * per-file line budget is watched.
 *
 * Usage: php bin/kb-lint.php [options]
 *
 * Options:
 *   --fix               regenerate the tag index of every knowledge-base file
 *   --json              machine-readable output (JSON on stdout)
 *   --root=DIR          repository root (default: the parent of bin/)
 *   --help              show this help
 *
 * Environment:
 *   KB_LINT_ROOT        same as --root=, overridden by it. The resolved root is
 *                       always printed, and using this variable is reported as a
 *                       warning — a lint whose target can be switched invisibly
 *                       is not a gate.
 *
 * Exit codes:
 *   0  clean (warnings may still be printed)
 *   1  lint failure (an error was found, or --fix was needed and not given)
 *   2  usage error
 *
 * Entry front matter — markdown has no per-section front matter, so an entry
 * carries a single-line HTML comment **immediately after** its `###` heading.
 * It renders as nothing and parses with one regex:
 *
 *     ### Some entry title
 *     <!-- kb: id=FAQ-012 date=2026-08-11 tags=http,head trigger="HEAD headers" hits=0 status=active -->
 *
 * Grammar: `<!-- kb:` + space-separated `key=value` pairs + `-->`, all on one
 * line. A value is either bare (no whitespace) or double-quoted. Required
 * keys: id, date, tags, trigger, hits, status. Optional: gate (mandatory for
 * `status=promoted`).
 *
 * Decay (the retro step applies it, this script reports it):
 *   - `promoted` — already encoded as a test or a rule: the body collapses to
 *     a one-liner plus `gate=` pointing at the check that replaced it.
 *   - `stale` — 0 hits in STALE_AFTER_CYCLES cycles: listed here, removed by
 *     the retro.
 *
 * Only the retro step writes to docs/helpers/; coder and review subagents
 * propose candidate entries in their reports. See docs/helpers/README.md.
 */

const ROOT_ENV = 'KB_LINT_ROOT';

/** Knowledge-base file (relative to the root) => id prefix of its entries. */
const KB_FILES = [
    'docs/helpers/faq.md' => 'FAQ',
    'docs/helpers/decisions.md' => 'DEC',
];

const REQUIRED_KEYS = ['id', 'date', 'tags', 'trigger', 'hits', 'status'];
const OPTIONAL_KEYS = ['gate'];
const KNOWN_STATUSES = ['active', 'promoted', 'stale'];

/** Advisory line budget per file; the generated tag index does not count. */
const LINE_BUDGET = 300;

/** A `promoted` entry is a pointer, not knowledge: at most this many body lines. */
const PROMOTED_MAX_BODY_LINES = 2;

/** An entry with 0 hits over this many cycles is `stale` and gets removed. */
const STALE_AFTER_CYCLES = 20;

/**
 * Near-duplicate detection: overlap coefficient |A ∩ B| / min(|A|, |B|) over
 * the normalised token sets of title + body. The overlap coefficient (not
 * Jaccard) is used on purpose — a short entry fully contained in a long one is
 * a duplicate even though Jaccard would score it low.
 */
const DUPLICATE_THRESHOLD = 0.75;

/**
 * A pair is compared only when the *larger* of the two entries reaches this
 * token count. Applying the minimum to both entries would skip exactly the
 * short-entry-inside-a-long-one case the overlap coefficient exists for.
 */
const DUPLICATE_MIN_TOKENS = 15;

const INDEX_START = '<!-- kb-index:start -->';
const INDEX_END = '<!-- kb-index:end -->';

/** Words carrying no topical signal, dropped before comparing two entries. */
const STOP_WORDS = [
    'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'for', 'from', 'has', 'have', 'in', 'into',
    'is', 'it', 'its', 'no', 'not', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'were', 'when',
    'which', 'with', 'you', 'your',
];

/**
 * One knowledge-base entry.
 *
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
 * Parses the single-line front-matter comment of an entry.
 *
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

        $keyStart = $i;
        while ($i < $length && (ctype_alpha($spec[$i]) || $spec[$i] === '_')) {
            $i++;
        }
        $key = substr($spec, $keyStart, $i - $keyStart);

        if ($key === '' || $i >= $length || $spec[$i] !== '=') {
            return ['pairs' => [], 'error' => 'malformed front matter: expected key=value at offset ' . $keyStart];
        }

        $i++; // consume '='

        if ($i < $length && $spec[$i] === '"') {
            $i++;
            $valueStart = $i;
            while ($i < $length && $spec[$i] !== '"') {
                $i++;
            }

            if ($i >= $length) {
                return ['pairs' => [], 'error' => sprintf('unterminated quoted value for "%s"', $key)];
            }

            $value = substr($spec, $valueStart, $i - $valueStart);
            $i++; // consume the closing quote
        } else {
            $valueStart = $i;
            while ($i < $length && !ctype_space($spec[$i])) {
                $i++;
            }
            $value = substr($spec, $valueStart, $i - $valueStart);
        }

        if (isset($pairs[$key])) {
            return ['pairs' => [], 'error' => sprintf('duplicate front-matter key "%s"', $key)];
        }

        // An HTML comment ends at its first "-->", whatever the quoting says, so
        // a value containing one parses here but renders as visible text.
        if (str_contains($value, '-->')) {
            return ['pairs' => [], 'error' => sprintf('front-matter value for "%s" must not contain "-->"', $key)];
        }

        $pairs[$key] = $value;
    }

    return ['pairs' => $pairs, 'error' => null];
}

/**
 * How many lines the generated index occupies, its scaffolding included.
 *
 * `writeIndex()` emits `## Tag index`, a blank line, the two markers and a
 * trailing blank line. All of that is generated, so none of it is charged to
 * the line budget.
 *
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
 * Splits one knowledge-base file into entries and locates its tag index.
 *
 * @return array{
 *     entries: list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}>,
 *     errors: list<string>,
 *     lines: int,
 *     index: ?array{start: int, end: int, body: list<string>, footprint: int},
 *     first_section: ?int
 * }
 */
function parseFile(string $relative, string $absolute): array
{
    $lines = readLines($absolute);
    /** @var list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> $entries */
    $entries = [];
    $errors = [];
    $index = null;
    $firstSection = null;
    $inFence = false;
    $indexStart = null;
    $current = null;
    /** @var list<string>|null $currentBody reference to the body of the entry being collected */
    $currentBody = null;

    foreach ($lines as $offset => $line) {
        $number = $offset + 1;
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '```')) {
            $inFence = !$inFence;
        }

        if (!$inFence && $trimmed === INDEX_START) {
            $indexStart = $number;

            continue;
        }

        if (!$inFence && $trimmed === INDEX_END) {
            if ($indexStart === null) {
                $errors[] = sprintf('%s:%d: tag index end marker without a start marker', $relative, $number);

                continue;
            }

            if ($index !== null) {
                // --fix rewrites one block only, so a leftover would survive the
                // next lint and silently contradict the regenerated index.
                $errors[] = sprintf('%s:%d: more than one tag index block — keep exactly one', $relative, $number);
                $indexStart = null;

                continue;
            }

            $index = [
                'start' => $indexStart,
                'end' => $number,
                'body' => \array_slice($lines, $indexStart, $number - $indexStart - 1),
                'footprint' => 0,
            ];
            $indexStart = null;

            continue;
        }

        if ($inFence || !str_starts_with($trimmed, '#')) {
            if ($currentBody !== null) {
                $currentBody[] = $line;
            }

            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $matches) !== 1) {
            continue;
        }

        if ($matches[1] === '##' && $firstSection === null) {
            $firstSection = $number;
        }

        $current = null;
        unset($currentBody);
        $currentBody = null;

        if ($matches[1] !== '###') {
            continue;
        }

        $title = trim($matches[2]);
        $metaLine = $lines[$offset + 1] ?? '';
        $parsed = parseFrontMatter($metaLine);

        if ($parsed['error'] !== null) {
            $errors[] = sprintf('%s:%d: "%s": %s', $relative, $number + 1, $title, $parsed['error']);
        }

        $entries[] = [
            'file' => $relative,
            'line' => $number,
            'title' => $title,
            'meta' => $parsed['pairs'],
            'body' => [],
        ];
        $current = $title;
        $currentBody = &$entries[\count($entries) - 1]['body'];
    }

    if ($indexStart !== null) {
        $errors[] = sprintf('%s:%d: tag index start marker is never closed', $relative, $indexStart);
    }

    if ($index !== null) {
        $index['footprint'] = indexFootprint($lines, $index);
    }

    // The front-matter line is part of the entry, not of its body.
    foreach ($entries as $key => $entry) {
        $body = $entry['body'];
        if ($body !== [] && str_starts_with(trim($body[0]), '<!--')) {
            $body = array_slice($body, 1);
        }
        $entries[$key]['body'] = $body;
    }

    // A file ending in a newline yields a trailing empty element; it is not a line.
    $lineCount = \count($lines);
    if ($lineCount > 0 && end($lines) === '') {
        $lineCount--;
    }

    return [
        'entries' => $entries,
        'errors' => $errors,
        'lines' => $lineCount,
        'index' => $index,
        'first_section' => $firstSection,
    ];
}

/**
 * @param array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>} $entry
 *
 * @return list<string>
 */
function validateEntry(array $entry, string $prefix): array
{
    $errors = [];
    $meta = $entry['meta'];
    $where = sprintf('%s:%d: "%s"', $entry['file'], $entry['line'], $entry['title']);

    if ($meta === []) {
        return $errors; // the parse error was already reported
    }

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

    $id = $meta['id'] ?? '';
    if ($id !== '' && preg_match('/^' . $prefix . '-\d{3}$/', $id) !== 1) {
        $errors[] = sprintf('%s: id "%s" must match %s-NNN', $where, $id, $prefix);
    }

    $date = $meta['date'] ?? '';
    if ($date !== '' && !isIsoDate($date)) {
        $errors[] = sprintf('%s: date "%s" is not an ISO-8601 calendar date (YYYY-MM-DD)', $where, $date);
    }

    $tags = $meta['tags'] ?? '';
    if ($tags !== '') {
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
    }

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

    if ($status === 'promoted') {
        if (($meta['gate'] ?? '') === '') {
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
 * Normalised token set of an entry, used for near-duplicate detection.
 *
 * @param array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>} $entry
 *
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
 * Renders the tag index body (the lines between the two markers).
 *
 * @param list<array{file: string, line: int, title: string, meta: array<string, string>, body: list<string>}> $entries
 *
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
 * Writes a freshly rendered tag index into a file, creating the section when
 * the markers are missing.
 *
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
        $section = ['## Tag index', '', INDEX_START, ...$rendered, INDEX_END, ''];
        $lines = [...\array_slice($lines, 0, $at), ...$section, ...\array_slice($lines, $at)];
    }

    file_put_contents($absolute, implode("\n", $lines));
}

/** @param list<string> $args */
function printUsage(array $args): void
{
    fwrite(STDOUT, ($args[0] ?? 'bin/kb-lint.php') . " — lint the docs/helpers/ knowledge base\n\n");
    fwrite(STDOUT, "Usage: php bin/kb-lint.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --fix               regenerate the tag index of every knowledge-base file\n");
    fwrite(STDOUT, "  --json              machine-readable output (JSON on stdout)\n");
    fwrite(STDOUT, "  --root=DIR          repository root (default: the parent of bin/)\n");
    fwrite(STDOUT, "  --help              show this help\n\n");
    fwrite(STDOUT, "Environment:\n");
    fwrite(STDOUT, '  ' . ROOT_ENV . "        same as --root=, overridden by it; reported as a warning\n");
}

/**
 * @param list<string> $argv
 *
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
 * @param array{fix: bool, json: bool, root: string, root_from_env: bool} $options
 */
function main(array $options): int
{
    $errors = [];
    $warnings = [];

    // Which tree was linted is part of the result: an environment variable that
    // silently redirects the lint must never be invisible in its output.
    if ($options['root_from_env']) {
        $warnings[] = sprintf('root overridden via %s', ROOT_ENV);
    }

    $stale = [];
    $duplicates = [];
    $files = [];
    $allEntries = [];
    $seenIds = [];

    foreach (KB_FILES as $relative => $prefix) {
        $absolute = $options['root'] . '/' . $relative;

        if (!is_file($absolute)) {
            $errors[] = sprintf('%s: knowledge-base file is missing', $relative);

            continue;
        }

        $parsed = parseFile($relative, $absolute);
        $errors = [...$errors, ...$parsed['errors']];

        foreach ($parsed['entries'] as $entry) {
            $errors = [...$errors, ...validateEntry($entry, $prefix)];

            $id = $entry['meta']['id'] ?? '';
            if ($id !== '') {
                if (isset($seenIds[$id])) {
                    $errors[] = sprintf(
                        '%s:%d: duplicate id %s (already used by %s)',
                        $entry['file'],
                        $entry['line'],
                        $id,
                        $seenIds[$id],
                    );
                } else {
                    $seenIds[$id] = sprintf('%s:%d', $entry['file'], $entry['line']);
                }
            }

            if (($entry['meta']['status'] ?? '') === 'stale') {
                $stale[] = sprintf('%s:%d: %s "%s"', $entry['file'], $entry['line'], $id, $entry['title']);
            }

            $allEntries[] = $entry;
        }

        // The generated index is not knowledge, so neither it nor the heading and
        // blank lines generated around it use up the budget.
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

        if ($parsed['index'] === null) {
            if ($options['fix']) {
                writeIndex($absolute, $parsed['entries'], null, $parsed['first_section']);
                $warnings[] = sprintf('%s: tag index created', $relative);
            } else {
                $errors[] = sprintf('%s: no tag index (add %s / %s, or run --fix)', $relative, INDEX_START, INDEX_END);
            }
        } else {
            $expected = renderIndex($parsed['entries']);

            if ($expected !== $parsed['index']['body']) {
                if ($options['fix']) {
                    writeIndex($absolute, $parsed['entries'], $parsed['index'], $parsed['first_section']);
                    $warnings[] = sprintf('%s: tag index regenerated', $relative);
                } else {
                    $errors[] = sprintf('%s: tag index is out of sync with the entries (run --fix)', $relative);
                }
            }
        }

        $files[] = [
            'path' => $relative,
            'entries' => \count($parsed['entries']),
            'lines' => $parsed['lines'],
            'budgeted_lines' => $budgeted,
            'over_budget' => $budgeted > LINE_BUDGET,
        ];
    }

    // Near-duplicate detection across both files. Promoted entries are
    // one-line pointers by design, so they never take part.
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

    $warnings = [...$warnings, ...$duplicates];
    $ok = $errors === [];

    if ($options['json']) {
        echo json_encode([
            'ok' => $ok,
            'root' => $options['root'],
            'root_from_env' => $options['root_from_env'],
            'files' => $files,
            'entries' => \count($allEntries),
            'errors' => $errors,
            'warnings' => $warnings,
            'stale' => $stale,
            'duplicates' => $duplicates,
            'line_budget' => LINE_BUDGET,
            'stale_after_cycles' => STALE_AFTER_CYCLES,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return $ok ? 0 : 1;
    }

    fwrite(STDOUT, sprintf("kb-lint: root %s\n", $options['root']));

    foreach ($files as $file) {
        fwrite(STDOUT, sprintf(
            "kb-lint: %s — %d entries, %d lines (%d budgeted, limit %d)\n",
            $file['path'],
            $file['entries'],
            $file['lines'],
            $file['budgeted_lines'],
            LINE_BUDGET,
        ));
    }

    foreach ($stale as $entry) {
        fwrite(STDOUT, sprintf("kb-lint: stale (0 hits in %d cycles, remove at the next retro): %s\n", STALE_AFTER_CYCLES, $entry));
    }

    foreach ($warnings as $warning) {
        fwrite(STDOUT, 'kb-lint: warning: ' . $warning . "\n");
    }

    foreach ($errors as $error) {
        fwrite(STDERR, 'kb-lint: error: ' . $error . "\n");
    }

    if (!$ok) {
        fwrite(STDERR, sprintf("kb-lint: FAILED — %d error(s)\n", \count($errors)));

        return 1;
    }

    fwrite(STDOUT, sprintf(
        "kb-lint: OK — %d entries, %d warning(s), %d stale\n",
        \count($allEntries),
        \count($warnings),
        \count($stale),
    ));

    return 0;
}

try {
    exit(main(parseArgs($_SERVER['argv'] ?? [])));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
