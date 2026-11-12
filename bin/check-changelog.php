<?php

declare(strict_types=1);

const CHANGELOG_ROOT_ENV = 'CHANGELOG_CHECK_ROOT';

const CHANGELOG_FILE = 'CHANGELOG.md';

const KEEP_A_CHANGELOG_SUBHEADINGS = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

const LEGACY_ENTRIES_WITHOUT_A_REFERENCE = [
    '- New developer helper `bin/gh-branch`: creates or switches to the',
    '- Extract `configureHandler()` from `ServerWorker::onWorkerStart()` — reduces closure complexity',
    '- Add `ext-zip` and `ext-inotify` to CI, fix test assertions for missing extensions',
    '- Fix PHPStan type annotations in test helpers',
    '- New `e2e/README.md` explaining e2e directory purpose and contributor guidance',
    '- Add `e2e/README.md` explaining e2e directory purpose and contributor guidance',
    '- **Breaking**: `ResponseConverterStrategyInterface::convert()` now requires a `TcpConnection` parameter',
    '- `BinaryFileResponseStrategy` now deletes temp files immediately after connection closes',
    '- `RequestConverter::toSymfonyRequest()` now returns empty content for multipart/form-data requests',
    '- **Critical**: Priority-based strategy ordering is now enforced in compiler pass',
    '- **Breaking**: Replaced generic PHP exceptions with typed exceptions throughout the codebase',
    '- **Breaking**: Removed root-level exception classes (moved to `Exception` namespace)',
];

/**
 * @param list<string> $lines
 * @return array{lines: list<string>, unterminated_at: int|null}
 */
function outsideFences(array $lines): array
{
    /** @var array{char: string, length: int}|null $fence */
    $fence = null;
    $unterminatedAt = null;

    foreach ($lines as $index => $line) {
        $trimmed = trim($line);

        if ($fence !== null) {
            if (
                str_starts_with($trimmed, $fence['char'])
                && preg_match(
                    '/^' . preg_quote($fence['char'], '/') . '{' . $fence['length'] . ',}\s*$/',
                    $trimmed,
                ) === 1
            ) {
                $fence = null;
            }

            $lines[$index] = '';

            continue;
        }

        if (preg_match('/^(`{3,}|~{3,})/', $trimmed, $matches) === 1) {
            $fence = ['char' => $matches[1][0], 'length' => \strlen($matches[1])];
            $unterminatedAt = $index + 1;
            $lines[$index] = '';
        }
    }

    if ($fence === null) {
        $unterminatedAt = null;
    }

    return ['lines' => $lines, 'unterminated_at' => $unterminatedAt];
}

function entryProse(string $text): string
{
    return (string) preg_replace('/`[^`]*`/', '', $text);
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function validateChangelogLines(array $lines): array
{
    $fenced = outsideFences($lines);
    $lines = $fenced['lines'];

    if ($fenced['unterminated_at'] !== null) {
        return [sprintf(
            'line %d: unterminated code fence opened here — nothing after this line was checked',
            $fenced['unterminated_at'],
        )];
    }

    $headings = versionHeadings($lines);

    return [
        ...unreleasedHeadingViolations($headings),
        ...releasedHeadingViolations($headings),
        ...duplicateSubheadingViolations($lines),
        ...missingReferenceViolations($lines),
    ];
}

/**
 * @param list<array{raw: string, line: int}> $headings
 * @return list<string>
 */
function unreleasedHeadingViolations(array $headings): array
{
    $violations = [];

    if ($headings === []) {
        $violations[] = 'CHANGELOG.md has no "## [...]" version headings at all';
    }

    $unreleasedIndexes = array_keys(array_filter(
        $headings,
        static fn(array $heading): bool => $heading['raw'] === '## [Unreleased]',
    ));

    if (\count($unreleasedIndexes) !== 1) {
        $violations[] = sprintf(
            'CHANGELOG.md must have exactly one "## [Unreleased]" heading, found %d',
            \count($unreleasedIndexes),
        );
    } elseif ((int) array_key_first($headings) !== $unreleasedIndexes[0]) {
        $violations[] = '"## [Unreleased]" must be the first version heading in the file';
    }

    return $violations;
}

/**
 * @param list<array{raw: string, line: int}> $headings
 * @return list<string>
 */
function releasedHeadingViolations(array $headings): array
{
    $violations = [];
    $releasedCount = 0;
    $previousVersion = null;

    foreach ($headings as $heading) {
        if ($heading['raw'] === '## [Unreleased]') {
            continue;
        }

        $releasedCount++;
        $parsed = parseReleasedHeading($heading);

        if ($parsed['violation'] !== null) {
            $violations[] = $parsed['violation'];

            continue;
        }

        $version = $parsed['version'];

        if ($previousVersion !== null && version_compare($version, $previousVersion) >= 0) {
            $violations[] = sprintf(
                "line %d: version %s is not strictly older than the preceding heading's %s — released versions must be in descending order",
                $heading['line'],
                $version,
                $previousVersion,
            );
        }

        $previousVersion = $version;
    }

    if ($releasedCount === 0) {
        $violations[] = 'CHANGELOG.md has no released version headings';
    }

    return $violations;
}

/**
 * @param array{raw: string, line: int} $heading
 * @return array{version: string, violation: string|null}
 */
function parseReleasedHeading(array $heading): array
{
    if (preg_match('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})$/', $heading['raw'], $matches) !== 1) {
        return ['version' => '', 'violation' => sprintf('line %d: "%s" does not match "## [x.y.z] - YYYY-MM-DD"', $heading['line'], $heading['raw'])];
    }

    if (!checkChangelogIsIsoDate($matches[2])) {
        return ['version' => '', 'violation' => sprintf(
            'line %d: "%s" is not an ISO-8601 calendar date (YYYY-MM-DD)',
            $heading['line'],
            $matches[2],
        )];
    }

    return ['version' => $matches[1], 'violation' => null];
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function duplicateSubheadingViolations(array $lines): array
{
    $violations = [];

    foreach (versionBlocks($lines) as $block) {
        $known = array_filter(
            $block['subheadings'],
            static fn(string $subheading): bool => \in_array($subheading, KEEP_A_CHANGELOG_SUBHEADINGS, true),
        );

        foreach (array_count_values($known) as $subheading => $count) {
            if ($count > 1) {
                $violations[] = sprintf(
                    '"%s" has %d "### %s" subheadings — a Keep a Changelog subheading must appear at most once per version block',
                    $block['heading'],
                    $count,
                    $subheading,
                );
            }
        }
    }

    return $violations;
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function missingReferenceViolations(array $lines): array
{
    $missingReferences = [];

    foreach (topLevelEntries($lines) as $entry) {
        $prose = (string) preg_replace('/\]\(#\d+\)/', '', entryProse($entry['text']));

        if (preg_match('/\[#\d+\]|\(#\d+\)/', $prose) === 1) {
            continue;
        }

        if (\in_array($entry['firstLine'], LEGACY_ENTRIES_WITHOUT_A_REFERENCE, true)) {
            continue;
        }

        $missingReferences[] = sprintf('line %d: %s', $entry['line'], $entry['firstLine']);
    }

    if ($missingReferences === []) {
        return [];
    }

    return [
        sprintf(
            'changelog entries with no issue/PR reference and not on the frozen legacy list: %d',
            \count($missingReferences),
        ),
        ...$missingReferences,
    ];
}

/**
 * @param list<string> $lines
 * @return list<array{raw: string, line: int}>
 */
function versionHeadings(array $lines): array
{
    $headings = [];

    foreach ($lines as $index => $line) {
        if (preg_match('/^## \[/', $line) === 1) {
            $headings[] = ['raw' => rtrim($line), 'line' => $index + 1];
        }
    }

    return $headings;
}

function checkChangelogIsIsoDate(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

/**
 * @param list<string> $lines
 * @return list<array{heading: string, subheadings: list<string>}>
 */
function versionBlocks(array $lines): array
{
    $blocks = [];
    $current = null;

    foreach ($lines as $line) {
        if (preg_match('/^## \[/', $line) === 1) {
            if ($current !== null) {
                $blocks[] = $current;
            }

            $current = ['heading' => rtrim($line), 'subheadings' => []];

            continue;
        }

        if ($current !== null && preg_match('/^### (.+)$/', $line, $matches) === 1) {
            $current['subheadings'][] = trim($matches[1]);
        }
    }

    if ($current !== null) {
        $blocks[] = $current;
    }

    return $blocks;
}

/**
 * @param list<string> $lines
 * @return list<array{text: string, firstLine: string, line: int}>
 */
function topLevelEntries(array $lines): array
{
    $entries = [];
    $count = \count($lines);
    $i = 0;

    while ($i < $count) {
        $line = $lines[$i];

        if (preg_match('/^-\s/', $line) !== 1) {
            $i++;

            continue;
        }

        $text = $line;
        $j = $i + 1;

        while (
            $j < $count
            && preg_match('/^-\s/', $lines[$j]) !== 1
            && preg_match('/^#{2,3}\s/', $lines[$j]) !== 1
            && trim($lines[$j]) !== ''
        ) {
            $text .= "\n" . $lines[$j];
            $j++;
        }

        $entries[] = ['text' => $text, 'firstLine' => $line, 'line' => $i + 1];
        $i = $j;
    }

    return $entries;
}

/** @param list<string> $args */
function checkChangelogPrintUsage(array $args): void
{
    fwrite(STDOUT, ($args[0] ?? 'bin/check-changelog.php') . " — validate the structure of CHANGELOG.md\n\n");
    fwrite(STDOUT, "Usage: php bin/check-changelog.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --root=DIR            repository root whose CHANGELOG.md is checked\n");
    fwrite(STDOUT, "                        (default: the parent of bin/)\n");
    fwrite(STDOUT, "  --help                show this help\n\n");
    fwrite(STDOUT, "Environment:\n");
    fwrite(STDOUT, '  ' . CHANGELOG_ROOT_ENV . "  same as --root=, overridden by it; reported as a warning\n");
}

/**
 * @param list<string> $argv
 * @return array{root: string, root_from_env: bool}
 */
function checkChangelogParseArgs(array $argv): array
{
    $envRoot = getenv(CHANGELOG_ROOT_ENV);
    $fromEnv = \is_string($envRoot) && $envRoot !== '';

    $options = [
        'root' => $fromEnv ? (string) $envRoot : \dirname(__DIR__),
        'root_from_env' => $fromEnv,
    ];

    foreach (\array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            checkChangelogPrintUsage($argv);
            exit(0);
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
 * @param array{root: string, root_from_env: bool} $options
 */
function checkChangelogMain(array $options): void
{
    $path = $options['root'] . '/' . CHANGELOG_FILE;

    fwrite(STDOUT, sprintf("check-changelog: root %s\n", $options['root']));

    if ($options['root_from_env']) {
        fwrite(STDOUT, sprintf("check-changelog: warning: root overridden via %s\n", CHANGELOG_ROOT_ENV));
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, sprintf("check-changelog: %s is missing or unreadable under %s\n", CHANGELOG_FILE, $options['root']));

        exit(2);
    }

    $lines = explode("\n", str_replace("\r\n", "\n", $contents));
    $violations = validateChangelogLines($lines);

    foreach ($violations as $violation) {
        fwrite(STDERR, 'check-changelog: error: ' . $violation . "\n");
    }

    if ($violations !== []) {
        fwrite(STDERR, sprintf("check-changelog: FAILED — %d violation(s) in %s\n", \count($violations), $path));

        exit(1);
    }

    fwrite(STDOUT, sprintf("check-changelog: OK — %s is structurally valid\n", $path));

    exit(0);
}

try {
    checkChangelogMain(checkChangelogParseArgs($_SERVER['argv'] ?? []));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
