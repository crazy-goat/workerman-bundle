<?php

declare(strict_types=1);

const UPGRADE_EXCEPTIONS_DIR_REL = 'src/Exception';
const UPGRADE_EXCEPTIONS_FILE = 'UPGRADE.md';

/**
 * @param list<string> $argv
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

function checkUpgradeExceptionsShortName(string $name): string
{
    $name = ltrim(trim($name), '\\');
    $pos = strrpos($name, '\\');

    return $pos === false ? $name : substr($name, $pos + 1);
}

/**
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
 * @return array<string, true>
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
            $blockPos = strpos($after, $blocks[0][0]);

            if ($blockPos !== false) {
                $start = $markerPos + $blockPos;
                $scope = substr($source, 0, $start) . substr($source, $start + strlen($blocks[0][0]));
            }
        }
    }

    $names = [];

    if (preg_match_all('/(?<!`)`([^`\n]+)`(?!`)/', $scope, $spans)) {
        foreach ($spans[1] as $span) {
            $span = trim($span);

            if (!preg_match('/^(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)$/', $span)) {
                continue;
            }

            checkUpgradeExceptionsAddNamedType($names, $span);
        }
    }

    if (preg_match_all('/^\h*use\s+(?!function\s|const\s)((?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*Exception)\s*;/m', $scope, $uses)) {
        foreach ($uses[1] as $name) {
            checkUpgradeExceptionsAddNamedType($names, $name);
        }
    }

    if (preg_match_all('/\bcatch\s*\(\s*(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*Exception)\b/', $scope, $catches)) {
        foreach ($catches[1] as $name) {
            checkUpgradeExceptionsAddNamedType($names, $name);
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
 * @param array<string, true> $names
 */
function checkUpgradeExceptionsAddNamedType(array &$names, string $name): void
{
    if (str_starts_with(ltrim($name), '\\')) {
        return;
    }

    $short = checkUpgradeExceptionsShortName($name);

    if ($short === 'Exception' || !str_ends_with($short, 'Exception')) {
        return;
    }

    $names[$short] = true;
}

/**
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

        foreach (checkUpgradeExceptionsDeclaredTypesInTokens(\token_get_all($source)) as $name => $declaration) {
            $declared[$name] = $declaration;
        }
    }

    return $declared;
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @return array<string, array{parent: string|null, interfaces: list<string>}>
 */
function checkUpgradeExceptionsDeclaredTypesInTokens(array $tokens): array
{
    $declared = [];
    $count = \count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        if (!\is_array($tokens[$i]) || !checkUpgradeExceptionsIsTypeKeyword($tokens[$i][0])) {
            continue;
        }

        if (checkUpgradeExceptionsFollowsDoubleColon($tokens, $i)) {
            continue;
        }

        [$name, $i] = checkUpgradeExceptionsReadDeclaredName($tokens, $i, $count);

        if ($name === null) {
            continue;
        }

        [$declared[$name], $i] = checkUpgradeExceptionsReadHeritage($tokens, $i, $count);
    }

    return $declared;
}

function checkUpgradeExceptionsIsTypeKeyword(mixed $id): bool
{
    return \in_array($id, [\T_CLASS, \T_INTERFACE, \T_TRAIT], true) || (\defined('T_ENUM') && $id === \T_ENUM);
}

function checkUpgradeExceptionsIsTrivia(mixed $token): bool
{
    return \is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true);
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function checkUpgradeExceptionsFollowsDoubleColon(array $tokens, int $keyword): bool
{
    $k = $keyword - 1;

    while ($k >= 0 && checkUpgradeExceptionsIsTrivia($tokens[$k])) {
        --$k;
    }

    return $k >= 0 && \is_array($tokens[$k]) && $tokens[$k][0] === \T_DOUBLE_COLON;
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @return array{0: string|null, 1: int}
 */
function checkUpgradeExceptionsReadDeclaredName(array $tokens, int $keyword, int $count): array
{
    for ($j = $keyword + 1; $j < $count && \is_array($tokens[$j]); ++$j) {
        if (checkUpgradeExceptionsIsTrivia($tokens[$j])) {
            continue;
        }

        return [$tokens[$j][0] === \T_STRING ? $tokens[$j][1] : null, $j];
    }

    return [null, $keyword];
}

/**
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @return array{0: array{parent: string|null, interfaces: list<string>}, 1: int}
 */
function checkUpgradeExceptionsReadHeritage(array $tokens, int $nameAt, int $count): array
{
    $parent = null;
    $interfaces = [];

    for ($j = $nameAt + 1; $j < $count; ++$j) {
        $token = $tokens[$j];

        if (!\is_array($token)) {
            if ($token === '{' || $token === ';') {
                return [['parent' => $parent, 'interfaces' => $interfaces], $j];
            }

            continue;
        }

        if ($token[0] === \T_EXTENDS) {
            $parent = checkUpgradeExceptionsShortName(checkUpgradeExceptionsReadName($tokens, $j + 1, $count));
        } elseif ($token[0] === \T_IMPLEMENTS) {
            $interfaces = array_map(
                checkUpgradeExceptionsShortName(...),
                checkUpgradeExceptionsReadNameList($tokens, $j + 1, $count),
            );
        } elseif (checkUpgradeExceptionsIsTypeKeyword($token[0])) {
            return [['parent' => $parent, 'interfaces' => $interfaces], $j - 1];
        }
    }

    return [['parent' => $parent, 'interfaces' => $interfaces], $nameAt];
}

/**
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
 * @param list<mixed> $tokens
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
