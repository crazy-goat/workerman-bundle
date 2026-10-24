<?php

declare(strict_types=1);

/**
 * Verifies that the exception-hierarchy tree in UPGRADE.md matches the types
 * declared in src/Exception/.
 *
 * PR #814 fixed the tree by hand (four classes were missing — pre-existing
 * drift), but nothing prevented the next drift: the doc tree is a static
 * copy of a hierarchy the code owns. This gate parses the `text` fenced
 * block under "**Exception hierarchy:**" in UPGRADE.md and compares it
 * against the tokenizer-discovered declarations in src/Exception/:
 *
 *   1. the SET of type short names must be identical (missing/extra fail);
 *   2. every nesting edge in the tree (child indented under a parent) must
 *      match the child's real parent class or one of its directly
 *      implemented/extended interfaces (short names);
 *   3. every explicit "(extends X)" annotation in the tree must match the
 *      type's real parent class / extended interface (short name);
 *   4. every exception type named in UPGRADE.md outside the tree block —
 *      the `| Before | After |` table, per-version prose and the `use` /
 *      `catch` / `new` references in the PHP examples — must be declared
 *      in src/Exception/ (issue #1045). A name whose namespace is not
 *      `CrazyGoat\WorkermanBundle\...` marks an external type (PHP
 *      built-ins like `\InvalidArgumentException`, third-party types like
 *      `Symfony\...\NotFoundHttpException`) and is skipped, as is the bare
 *      word `Exception` (the namespace name, not a type). A leading
 *      backslash alone no longer marks a type external: a fully qualified
 *      bundle name is checked like a short one (issue #1105).
 *
 * Usage: php bin/check-upgrade-exceptions.php [options]
 *
 * Options:
 *   --root=DIR            repository root holding UPGRADE.md and src/Exception/
 *                         (default: the parent of bin/)
 *   --help                show this help
 *
 * Exit codes:
 *   0  the doc tree matches src/Exception/
 *   1  the doc tree drifts from src/Exception/ (missing/extra types, wrong
 *      parent links, missing tree block)
 *   2  usage error (unknown option, missing root, missing/unreadable files)
 */

const UPGRADE_EXCEPTIONS_DIR_REL = 'src/Exception';
const UPGRADE_EXCEPTIONS_FILE = 'UPGRADE.md';

/**
 * @param list<string> $argv
 *
 * @return array{root: string}
 */
function checkUpgradeExceptionsParseArgs(array $argv): array
{
    $options = ['root' => \dirname(__DIR__)];

    foreach (\array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            checkUpgradeExceptionsPrintUsage($argv);

            exit(0);
        }

        if (str_starts_with($arg, '--root=')) {
            $value = substr($arg, 7);

            // An empty value would resolve via realpath('') to the working
            // directory (issue #1044), silently checking the wrong tree.
            if ($value === '') {
                fwrite(STDERR, "--root= requires a directory path (see --help)\n");
                exit(2);
            }

            $options['root'] = $value;

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

    return ['root' => $root];
}

/** @param list<string> $argv */
function checkUpgradeExceptionsPrintUsage(array $argv): void
{
    fwrite(STDOUT, ($argv[0] ?? 'bin/check-upgrade-exceptions.php') . " — verify the UPGRADE.md exception tree and the exception types it names match src/Exception/\n\n");
    fwrite(STDOUT, "Usage: php bin/check-upgrade-exceptions.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --root=DIR            repository root holding UPGRADE.md and src/Exception/\n");
    fwrite(STDOUT, "                        (default: the parent of bin/)\n");
    fwrite(STDOUT, "  --help                show this help\n");
}

/**
 * Short name of a (possibly qualified) type reference: strip the leading
 * backslash and keep the segment after the last namespace separator.
 */
function checkUpgradeExceptionsShortName(string $name): string
{
    $name = ltrim(trim($name), '\\');
    $pos = strrpos($name, '\\');

    return $pos === false ? $name : substr($name, $pos + 1);
}

/**
 * Parse the exception-hierarchy tree out of UPGRADE.md.
 *
 * @return array{names: array<string, true>, edges: list<array{child: string, parent: string}>, explicit: list<array{child: string, parent: string}>}
 */
function checkUpgradeExceptionsParseTree(string $path): array
{
    $source = @file_get_contents($path);

    if ($source === false) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: error: cannot read %s\n", $path));
        exit(2);
    }

    $markerPos = strpos($source, '**Exception hierarchy:**');

    if ($markerPos === false) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: error: no exception-hierarchy tree (no **Exception hierarchy:** section found in %s)\n", UPGRADE_EXCEPTIONS_FILE));
        exit(1);
    }

    $scope = substr($source, $markerPos);

    if (!preg_match_all('/```text\n(.*?)\n```/s', $scope, $blocks)) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: error: no ```text block found in %s\n", UPGRADE_EXCEPTIONS_FILE));
        exit(1);
    }

    $tree = $blocks[1][0];

    if (!str_contains($tree, '├──') && !str_contains($tree, '└──')) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: error: no exception-hierarchy tree (the first ```text block after **Exception hierarchy:** holds no hierarchy listing) found in %s\n", UPGRADE_EXCEPTIONS_FILE));
        exit(1);
    }

    $names = [];
    $edges = [];
    $explicit = [];
    $stack = [];

    foreach (explode("\n", $tree) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $ascii = str_replace(['│', '├──', '└──', '──'], ['|', '+--', '+--', '--'], $line);
        $marker = strpos($ascii, '+--');

        if ($marker === false) {
            $depth = 0;
            $rest = trim($ascii);
        } else {
            $depth = 1 + intdiv($marker, 4);
            $rest = trim(substr($ascii, $marker + 3));
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s*\(extends\s*([^)]+)\))?/', $rest, $m)) {
            fwrite(STDERR, sprintf("check-upgrade-exceptions: error: cannot parse tree line: %s\n", $line));
            exit(1);
        }

        $name = $m[1];
        $names[$name] = true;

        if (isset($m[2]) && trim($m[2]) !== '') {
            $explicit[] = ['child' => $name, 'parent' => checkUpgradeExceptionsShortName($m[2])];
        }

        $stack[$depth] = $name;

        if ($depth > 0) {
            if (!isset($stack[$depth - 1])) {
                fwrite(STDERR, sprintf("check-upgrade-exceptions: error: cannot determine parent of tree line: %s\n", $line));
                exit(1);
            }

            $edges[] = ['child' => $name, 'parent' => $stack[$depth - 1]];
        }
    }

    if ($names === []) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: error: exception-hierarchy tree in %s is empty\n", UPGRADE_EXCEPTIONS_FILE));
        exit(1);
    }

    return ['names' => $names, 'edges' => $edges, 'explicit' => $explicit];
}

/**
 * Collect every exception-type name referenced in UPGRADE.md outside the
 * exception-hierarchy tree: inline code spans (the `| Before | After |`
 * table cells, per-version prose) and `use` / `catch` / `new` references in
 * the PHP examples (issue #1045).
 *
 * The tree block itself (the first ```text block at or after
 * "**Exception hierarchy:**") is removed before scanning so its names are
 * not double-reported.
 *
 * @return array<string, true> referenced type short names
 */
function checkUpgradeExceptionsParseNamedTypes(string $path): array
{
    $source = @file_get_contents($path);

    if ($source === false) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: error: cannot read %s\n", $path));
        exit(2);
    }

    $scope = $source;
    $markerPos = strpos($source, '**Exception hierarchy:**');

    if ($markerPos !== false) {
        $after = substr($source, $markerPos);

        if (preg_match('/```text\n(.*?)\n```/s', $after, $blocks)) {
            // The regex match is leftmost, so the first occurrence of the
            // matched block is the tree block itself.
            $blockPos = strpos($after, $blocks[0][0]);

            if ($blockPos !== false) {
                $start = $markerPos + $blockPos;
                $scope = substr($source, 0, $start) . substr($source, $start + strlen($blocks[0][0]));
            }
        }
    }

    $names = [];

    // Inline code spans: the Before/After table cells and prose mentions.
    // The backtick must not touch another backtick, otherwise a fenced
    // block's closing ``` pairs with the next inline span and shifts every
    // following pair (the table sits right after a ```php block).
    if (preg_match_all('/(?<!`)`([^`\n]+)`(?!`)/', $scope, $spans)) {
        foreach ($spans[1] as $span) {
            $span = trim($span);

            if (!preg_match('/^(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)$/', $span)) {
                continue;
            }

            checkUpgradeExceptionsAddNamedType($names, $span);
        }
    }

    // `use` imports, `catch` clauses and `new` instantiations in the PHP
    // examples. A `use X as Y;` alias (issue #1105) names X, not the
    // local alias Y, so the alias is allowed after the type.
    if (preg_match_all('/^\h*use\s+(?!function\s|const\s)((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*Exception)(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;/m', $scope, $uses)) {
        foreach ($uses[1] as $name) {
            checkUpgradeExceptionsAddNamedType($names, $name);
        }
    }

    // Every type of a multi-catch clause (`catch (A | B $e)`, issue #1105)
    // is checked, not just the first: the whole clause is captured and
    // each type inside it is recorded. A single-type clause re-records
    // the same name, which is harmless (set semantics).
    if (preg_match_all('/\bcatch\s*\(([^)]*)\)/', $scope, $catchGroups)) {
        foreach ($catchGroups[1] as $group) {
            if (preg_match_all('/(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*Exception)\b/', $group, $catches)) {
                foreach ($catches[1] as $name) {
                    checkUpgradeExceptionsAddNamedType($names, $name);
                }
            }
        }
    }

    if (preg_match_all('/\bnew\s+(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*Exception)\s*\(/', $scope, $news)) {
        foreach ($news[1] as $name) {
            checkUpgradeExceptionsAddNamedType($names, $name);
        }
    }

    return $names;
}

/**
 * Record a referenced type unless it is external: a name whose namespace
 * is not `CrazyGoat\WorkermanBundle\...` is a third-party or global type
 * (PHP built-ins like `\InvalidArgumentException`, third-party types like
 * `Symfony\...\NotFoundHttpException` with or without a leading backslash)
 * and is skipped, as is the bare word `Exception` (the namespace name,
 * not a type). A leading backslash alone does not mark a bundle type
 * external: a fully qualified bundle name is checked like a short one.
 *
 * @param array<string, true> $names
 */
function checkUpgradeExceptionsAddNamedType(array &$names, string $name): void
{
    $qualified = ltrim(trim($name), '\\');
    $separator = strrpos($qualified, '\\');

    if ($separator !== false) {
        $namespace = substr($qualified, 0, $separator);

        if ($namespace !== 'CrazyGoat\\WorkermanBundle' && !str_starts_with($namespace, 'CrazyGoat\\WorkermanBundle\\')) {
            return;
        }
    } elseif (str_starts_with(ltrim($name), '\\')) {
        // A leading-backslash name with no namespace is a global type
        // (PHP built-ins like `\InvalidArgumentException`).
        return;
    }

    $short = checkUpgradeExceptionsShortName($name);

    if ($short === 'Exception' || !str_ends_with($short, 'Exception')) {
        return;
    }

    $names[$short] = true;
}

/**
 * Discover every type declared in src/Exception/ with its parent class and
 * directly implemented/extended interfaces (short names), using the
 * tokenizer so that "class"/"interface" inside comments or strings is never
 * mistaken for a declaration.
 *
 * @return array<string, array{parent: string|null, interfaces: list<string>}>
 */
function checkUpgradeExceptionsDeclaredTypes(string $exceptionDir): array
{
    $declared = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($exceptionDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents((string) $file->getRealPath());
        $tokens = \token_get_all($source);
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            if (!\is_array($tokens[$i])) {
                continue;
            }

            $id = $tokens[$i][0];

            if (!\in_array($id, [\T_INTERFACE, \T_CLASS, \T_TRAIT], true) && (\defined('T_ENUM') === false || $id !== \T_ENUM)) {
                continue;
            }

            for ($k = $i - 1; $k >= 0; --$k) {
                if (\is_array($tokens[$k]) && \in_array($tokens[$k][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                if (\is_array($tokens[$k]) && $tokens[$k][0] === \T_DOUBLE_COLON) {
                    continue 2;
                }

                break;
            }

            $name = null;

            for ($j = $i + 1; $j < $count; ++$j) {
                if (!\is_array($tokens[$j])) {
                    break;
                }

                if (\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($tokens[$j][0] === \T_STRING) {
                    $name = $tokens[$j][1];
                }

                $i = $j;

                break;
            }

            if ($name === null) {
                continue;
            }

            $parent = null;
            $interfaces = [];

            for ($j = $i + 1; $j < $count; ++$j) {
                if (!\is_array($tokens[$j])) {
                    if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                        $i = $j;

                        break;
                    }

                    continue;
                }

                $tid = $tokens[$j][0];

                if ($tid === \T_EXTENDS) {
                    $parent = checkUpgradeExceptionsShortName(checkUpgradeExceptionsReadName($tokens, $j + 1, $count));
                } elseif ($tid === \T_IMPLEMENTS) {
                    $interfaces = array_map(
                        checkUpgradeExceptionsShortName(...),
                        checkUpgradeExceptionsReadNameList($tokens, $j + 1, $count),
                    );
                } elseif (\in_array($tid, [\T_CLASS, \T_INTERFACE, \T_TRAIT], true) || (\defined('T_ENUM') && $tid === \T_ENUM)) {
                    $i = $j - 1;

                    break;
                }
            }

            $declared[$name] = ['parent' => $parent, 'interfaces' => $interfaces];
        }
    }

    return $declared;
}

/**
 * Read a single (possibly qualified) name starting at token $from.
 *
 * @param list<mixed> $tokens
 */
function checkUpgradeExceptionsReadName(array $tokens, int $from, int $count): string
{
    $name = '';

    for ($j = $from; $j < $count; ++$j) {
        if (!\is_array($tokens[$j])) {
            break;
        }

        $tid = $tokens[$j][0];

        if ($tid === \T_STRING || $tid === \T_NS_SEPARATOR || (\defined('T_NAME_QUALIFIED') && \in_array($tid, [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true))) {
            $name .= $tokens[$j][1];

            continue;
        }

        if (\in_array($tid, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            if ($name !== '') {
                break;
            }

            continue;
        }

        break;
    }

    return $name;
}

/**
 * Read a comma-separated list of (possibly qualified) names starting at
 * token $from.
 *
 * @param list<mixed> $tokens
 *
 * @return list<string>
 */
function checkUpgradeExceptionsReadNameList(array $tokens, int $from, int $count): array
{
    $names = [];
    $current = '';

    for ($j = $from; $j < $count; ++$j) {
        if (!\is_array($tokens[$j])) {
            if ($tokens[$j] === ',') {
                if ($current !== '') {
                    $names[] = $current;
                    $current = '';
                }

                continue;
            }

            break;
        }

        $tid = $tokens[$j][0];

        if ($tid === \T_STRING || $tid === \T_NS_SEPARATOR || (\defined('T_NAME_QUALIFIED') && \in_array($tid, [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true))) {
            $current .= $tokens[$j][1];

            continue;
        }

        if (\in_array($tid, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
            if ($current !== '') {
                $names[] = $current;
                $current = '';
            }

            continue;
        }

        break;
    }

    if ($current !== '') {
        $names[] = $current;
    }

    return $names;
}

/**
 * @param array{root: string} $options
 */
function checkUpgradeExceptionsMain(array $options): void
{
    $exceptionDir = $options['root'] . '/' . UPGRADE_EXCEPTIONS_DIR_REL;
    $upgradeFile = $options['root'] . '/' . UPGRADE_EXCEPTIONS_FILE;

    fwrite(STDOUT, sprintf("check-upgrade-exceptions: root %s\n", $options['root']));

    if (!is_dir($exceptionDir)) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: %s is missing under %s\n", UPGRADE_EXCEPTIONS_DIR_REL, $options['root']));
        exit(2);
    }

    if (!is_file($upgradeFile) || !is_readable($upgradeFile)) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: %s is missing or unreadable under %s\n", UPGRADE_EXCEPTIONS_FILE, $options['root']));
        exit(2);
    }

    $tree = checkUpgradeExceptionsParseTree($upgradeFile);
    $declared = checkUpgradeExceptionsDeclaredTypes($exceptionDir);

    if ($declared === []) {
        fwrite(STDERR, sprintf("check-upgrade-exceptions: no PHP types found in %s\n", $exceptionDir));
        exit(2);
    }

    $docNames = array_keys($tree['names']);
    sort($docNames);
    $srcNames = array_keys($declared);
    sort($srcNames);

    $failures = [];

    foreach (array_diff($srcNames, $docNames) as $missing) {
        $failures[] = sprintf('check-upgrade-exceptions: error: %s is declared in %s but missing from the %s exception-hierarchy tree', $missing, UPGRADE_EXCEPTIONS_DIR_REL, UPGRADE_EXCEPTIONS_FILE);
    }

    foreach (array_diff($docNames, $srcNames) as $extra) {
        $failures[] = sprintf('check-upgrade-exceptions: error: %s is listed in the %s exception-hierarchy tree but declares no type in %s', $extra, UPGRADE_EXCEPTIONS_FILE, UPGRADE_EXCEPTIONS_DIR_REL);
    }

    foreach ($tree['edges'] as $edge) {
        $child = $edge['child'];
        $parent = $edge['parent'];

        if (!isset($declared[$child]) || !isset($declared[$parent])) {
            continue;
        }

        $actual = $declared[$child];
        $candidates = $actual['interfaces'];

        if ($actual['parent'] !== null) {
            $candidates[] = $actual['parent'];
        }

        if (!\in_array($parent, $candidates, true)) {
            $failures[] = sprintf(
                'check-upgrade-exceptions: error: %s is nested under %s in %s but extends %s%s',
                $child,
                $parent,
                UPGRADE_EXCEPTIONS_FILE,
                $actual['parent'] ?? '(nothing)',
                $actual['interfaces'] === [] ? '' : ' implements ' . implode(', ', $actual['interfaces']),
            );
        }
    }

    foreach ($tree['explicit'] as $item) {
        $child = $item['child'];

        if (!isset($declared[$child])) {
            continue;
        }

        if ($declared[$child]['parent'] !== $item['parent'] && !\in_array($item['parent'], $declared[$child]['interfaces'], true)) {
            $failures[] = sprintf(
                'check-upgrade-exceptions: error: %s says "(extends %s)" in %s but the declaration extends %s%s',
                $child,
                $item['parent'],
                UPGRADE_EXCEPTIONS_FILE,
                $declared[$child]['parent'] ?? '(nothing)',
                $declared[$child]['interfaces'] === [] ? '' : ' implements ' . implode(', ', $declared[$child]['interfaces']),
            );
        }
    }

    foreach (array_keys(checkUpgradeExceptionsParseNamedTypes($upgradeFile)) as $named) {
        if (!isset($declared[$named])) {
            $failures[] = sprintf('check-upgrade-exceptions: error: %s is named in %s but declares no type in %s', $named, UPGRADE_EXCEPTIONS_FILE, UPGRADE_EXCEPTIONS_DIR_REL);
        }
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, $failure . "\n");
        }

        fwrite(STDERR, sprintf("check-upgrade-exceptions: FAILED — %d problem(s): %s tree lists %d type(s), %s declares %d type(s)\n", \count($failures), UPGRADE_EXCEPTIONS_FILE, \count($docNames), UPGRADE_EXCEPTIONS_DIR_REL, \count($srcNames)));
        exit(1);
    }

    fwrite(STDOUT, sprintf("check-upgrade-exceptions: OK — %s tree lists all %d type(s) declared in %s with matching parents, and every type named outside the tree is declared\n", UPGRADE_EXCEPTIONS_FILE, \count($srcNames), $exceptionDir));
    exit(0);
}

try {
    checkUpgradeExceptionsMain(checkUpgradeExceptionsParseArgs($_SERVER['argv'] ?? []));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
