<?php

declare(strict_types=1);

/**
 * Validates the structure of CHANGELOG.md and exits non-zero on any violation.
 *
 * The rules were mined from `.pi-subagents/artifacts/` review history (issue
 * #686 phase 4) and wired into `composer lint` by issue #654: duplicate Keep a
 * Changelog subheadings (#641), out-of-order version headings (#255) and a
 * stale or missing `[Unreleased]` section (#356) all used to pass CI silently,
 * because every linter in the repository covered only PHP. This script is the
 * single source of truth for the rules — `tests/ChangelogStructureTest.php`
 * drives it as a subprocess, and `composer lint` runs it directly (so the
 * pre-push hook and the CI Lint job run it too).
 *
 * Rules enforced:
 *   - exactly one `## [Unreleased]` heading, and it is the first version
 *     heading in the file;
 *   - released headings match `## [x.y.z] - YYYY-MM-DD` (with a real calendar
 *     date) and are strictly descending;
 *   - a Keep a Changelog subheading (`### Added`, `### Changed`, `### Fixed`,
 *     `### Removed`, `### Deprecated`, `### Security`) appears at most once
 *     per version block;
 *   - every top-level `- ` entry carries an issue reference (`[#123]` link or
 *     a bare `(#123)`), except entries frozen in
 *     LEGACY_ENTRIES_WITHOUT_A_REFERENCE.
 *
 * Lines inside fenced code blocks (``` … ```) are ignored: a fenced example
 * showing a changelog heading is documentation, not structure. Issue
 * references are matched against prose only — inline-code spans are stripped
 * first, and an anchor link (`[x](#123)`) does not count as a reference.
 *
 * Usage: php bin/check-changelog.php [options]
 *
 * Options:
 *   --root=DIR            repository root whose CHANGELOG.md is checked
 *                         (default: the parent of bin/)
 *   --help                show this help
 *
 * Environment:
 *   CHANGELOG_CHECK_ROOT  same as --root=, overridden by it. Using this
 *                         variable is reported as a warning — a structural
 *                         gate whose target can be switched invisibly is not
 *                         a gate.
 *
 * Exit codes:
 *   0  the changelog is structurally valid
 *   1  violations found
 *   2  usage error (unknown option, missing root, missing/unreadable file)
 */

const CHANGELOG_ROOT_ENV = 'CHANGELOG_CHECK_ROOT';

/** The file this script validates, relative to the resolved root. */
const CHANGELOG_FILE = 'CHANGELOG.md';

/** Subheadings that must appear at most once per version block. */
const KEEP_A_CHANGELOG_SUBHEADINGS = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

/**
 * Exact first line (including the leading "- ") of every changelog entry that
 * predates the "every entry carries an issue reference" convention. Frozen on
 * purpose: adding to this list should be rare and deliberate, never the fix
 * for a newly written entry that forgot its reference.
 */
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
 * Blank every line inside a fenced code block (and the fence markers
 * themselves), keeping positions 1:1 so line numbers survive. A fenced
 * example showing what a changelog heading looks like is documentation, not
 * structure — without this, `## [x.y.z] - date` inside ``` ``` ``` counts as
 * a released heading and splits version blocks.
 *
 * @param list<string> $lines
 *
 * @return list<string>
 */
function outsideFences(array $lines): array
{
    $inFence = false;

    foreach ($lines as $index => $line) {
        if (str_starts_with(trim($line), '```')) {
            $inFence = !$inFence;
            $lines[$index] = '';

            continue;
        }

        if ($inFence) {
            $lines[$index] = '';
        }
    }

    return $lines;
}

/**
 * The prose of an entry: its text with inline-code spans removed (a backticked
 * `` `(#123)` `` quotes a reference shape without being one).
 */
function entryProse(string $text): string
{
    return (string) preg_replace('/`[^`]*`/', '', $text);
}

/**
 * Every structural violation in the given changelog lines, each a
 * human-readable message ("line N: …" wherever a line applies). An empty list
 * means the changelog is structurally valid.
 *
 * @param list<string> $lines
 *
 * @return list<string>
 */
function validateChangelogLines(array $lines): array
{
    $violations = [];
    $lines = outsideFences($lines);
    $headings = versionHeadings($lines);

    if ($headings === []) {
        $violations[] = 'CHANGELOG.md has no "## [...]" version headings at all';
    }

    // 1. `[Unreleased]` present exactly once, and first.
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

    // 2. Released headings well-formed and strictly descending.
    $releasedCount = 0;
    $previousVersion = null;

    foreach ($headings as $heading) {
        if ($heading['raw'] === '## [Unreleased]') {
            continue;
        }

        $releasedCount++;

        if (preg_match('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})$/', $heading['raw'], $matches) !== 1) {
            $violations[] = sprintf('line %d: "%s" does not match "## [x.y.z] - YYYY-MM-DD"', $heading['line'], $heading['raw']);

            continue;
        }

        $version = $matches[1];

        // Shape alone is not enough: 2026-13-45 matches the pattern but is
        // not a date.
        if (!checkChangelogIsIsoDate($matches[2])) {
            $violations[] = sprintf(
                'line %d: "%s" is not an ISO-8601 calendar date (YYYY-MM-DD)',
                $heading['line'],
                $matches[2],
            );

            continue;
        }

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

    // 3. Keep a Changelog subheadings unique per version block.
    foreach (versionBlocks($lines) as $block) {
        $counts = [];

        foreach ($block['subheadings'] as $subheading) {
            if (!\in_array($subheading, KEEP_A_CHANGELOG_SUBHEADINGS, true)) {
                continue;
            }

            $counts[$subheading] = ($counts[$subheading] ?? 0) + 1;
        }

        foreach ($counts as $subheading => $count) {
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

    // 4. Every top-level entry carries an issue reference, frozen legacy
    // entries excepted.
    $missingReferences = [];

    foreach (topLevelEntries($lines) as $entry) {
        // An anchor link (`[x](#123)`) points inside the page, not at an
        // issue — remove it before looking for a reference.
        $prose = (string) preg_replace('/\]\(#\d+\)/', '', entryProse($entry['text']));

        if (preg_match('/\[#\d+\]|\(#\d+\)/', $prose) === 1) {
            continue;
        }

        if (\in_array($entry['firstLine'], LEGACY_ENTRIES_WITHOUT_A_REFERENCE, true)) {
            continue;
        }

        $missingReferences[] = sprintf('line %d: %s', $entry['line'], $entry['firstLine']);
    }

    if ($missingReferences !== []) {
        $violations[] = sprintf(
            'changelog entries with no issue/PR reference and not on the frozen legacy list: %d',
            \count($missingReferences),
        );

        foreach ($missingReferences as $missingReference) {
            $violations[] = $missingReference;
        }
    }

    return $violations;
}

/**
 * All `## [` headings with their 1-based line numbers. The raw heading is
 * rtrimmed so a trailing space still compares equal to `## [Unreleased]` and
 * never produces a misleading "does not match" pair instead.
 *
 * @param list<string> $lines
 *
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

/**
 * True when the value is a real ISO-8601 calendar date — shape (YYYY-MM-DD)
 * plus a valid month/day, so `2026-13-45` is rejected.
 */
function checkChangelogIsIsoDate(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

/**
 * Splits the file into blocks, one per `## [` heading, each carrying the
 * `### …` subheadings that follow the heading up to the next one.
 *
 * @param list<string> $lines
 *
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
            $current['subheadings'][] = $matches[1];
        }
    }

    if ($current !== null) {
        $blocks[] = $current;
    }

    return $blocks;
}

/**
 * Top-level `- ` bullets (no leading indentation — a nested `  - ` bullet is
 * a continuation detail of its parent, not its own entry), each gathered
 * together with its wrapped continuation lines up to the next bullet, heading
 * or blank line.
 *
 * @param list<string> $lines
 *
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
 *
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

    // Which tree was checked is part of the result: an environment variable
    // that silently redirects the check must never be invisible in its output.
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
