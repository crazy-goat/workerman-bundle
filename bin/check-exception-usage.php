<?php

declare(strict_types=1);

/**
 * Verifies that every type declared in src/Exception/ is referenced by at
 * least one PHP file outside its own definition, across src/, tests/, e2e/
 * and benchmarks/.
 *
 * The exception hierarchy is advertised in the README as a feature with a
 * counted size, so an unused `final class` in src/Exception/ is worse than
 * ordinary dead code: it inflates a number the project uses as a selling
 * point, and it signals a missing throw (a real error path escaping the
 * hierarchy the whole design exists to cover). Issue #593 found two such
 * classes that had shipped green for many releases because nothing checked.
 *
 * The check is deliberately conservative: a bare `use` import with no other
 * mention still counts as a reference (it is a reference), and the grep is
 * word-boundary based so `KernelException` does not match
 * `InvalidCacheDirectoryException`. A type that is only referenced inside its
 * own file is reported as unused. Abstract bases and interfaces are checked
 * too — an unused base is a dead branch of the hierarchy.
 *
 * Usage: php bin/check-exception-usage.php [options]
 *
 * Options:
 *   --root=DIR            repository root whose src/Exception/ is checked
 *                         (default: the parent of bin/)
 *   --help                show this help
 *
 * Exit codes:
 *   0  every type in src/Exception/ is referenced outside its own file
 *   1  one or more types are unused
 *   2  usage error (unknown option, missing root, missing/unreadable dir)
 */

const EXCEPTION_DIR_REL = 'src/Exception';

/** Search roots, relative to the repository root. */
const SEARCH_ROOTS = ['src', 'tests', 'e2e', 'benchmarks'];

/**
 * @param list<string> $argv
 *
 * @return array{root: string}
 */
function checkExceptionUsageParseArgs(array $argv): array
{
    $options = ['root' => \dirname(__DIR__)];

    foreach (\array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            checkExceptionUsagePrintUsage($argv);

            exit(0);
        }

        if (str_starts_with($arg, '--root=')) {
            $options['root'] = substr($arg, 7);

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
function checkExceptionUsagePrintUsage(array $argv): void
{
    fwrite(STDOUT, ($argv[0] ?? 'bin/check-exception-usage.php') . " — verify every type in src/Exception/ is referenced outside its own file\n\n");
    fwrite(STDOUT, "Usage: php bin/check-exception-usage.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --root=DIR            repository root whose src/Exception/ is checked\n");
    fwrite(STDOUT, "                        (default: the parent of bin/)\n");
    fwrite(STDOUT, "  --help                show this help\n");
}

/**
 * Recursively collect every .php file under a directory.
 *
 * @return list<string>
 */
function checkExceptionUsagePhpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getRealPath();
        }
    }

    return $files;
}

/**
 * Return the short names of every interface and class declared in the given
 * PHP source, using the tokenizer so that "interface"/"class" inside comments
 * or strings is never mistaken for a declaration.
 *
 * @return list<string>
 */
function checkExceptionUsageDeclaredTypes(string $source): array
{
    $tokens = \token_get_all($source);

    $names = [];
    $count = \count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        if (!\is_array($tokens[$i])) {
            continue;
        }

        $id = $tokens[$i][0];

        // T_INTERFACE or T_CLASS — the next non-whitespace, non-comment
        // token is the declared name.
        if ($id !== \T_INTERFACE && $id !== \T_CLASS) {
            continue;
        }

        for ($j = $i + 1; $j < $count; ++$j) {
            if (!\is_array($tokens[$j])) {
                continue;
            }

            // Skip whitespace and comments between the keyword and the name.
            if (\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            if ($tokens[$j][0] === \T_STRING) {
                $names[] = $tokens[$j][1];
            }

            break;
        }
    }

    return $names;
}

/**
 * @param array{root: string} $options
 */
function checkExceptionUsageMain(array $options): void
{
    $exceptionDir = $options['root'] . '/' . EXCEPTION_DIR_REL;

    fwrite(STDOUT, sprintf("check-exception-usage: root %s\n", $options['root']));

    if (!is_dir($exceptionDir)) {
        fwrite(STDERR, sprintf("check-exception-usage: %s is missing under %s\n", EXCEPTION_DIR_REL, $options['root']));
        exit(2);
    }

    // Candidate PHP files across every search root, plus the exception dir
    // itself (so a base referenced by a sibling still counts).
    $haystack = [];
    foreach (SEARCH_ROOTS as $relativeRoot) {
        foreach (checkExceptionUsagePhpFiles($options['root'] . '/' . $relativeRoot) as $file) {
            $haystack[] = $file;
        }
    }

    // Discover every type declared in src/Exception/.
    $declared = [];
    $dirIterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($exceptionDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($dirIterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getRealPath();
        $source = (string) file_get_contents($path);

        // Discover declared types via the PHP tokenizer so that the word
        // "interface"/"class" inside docblock comments or strings cannot be
        // mistaken for a real declaration. Handles interface, class (incl.
        // abstract/final/readonly modifiers) and multiple types per file.
        foreach (checkExceptionUsageDeclaredTypes($source) as $name) {
            $declared[] = ['name' => $name, 'path' => $path];
        }
    }

    if ($declared === []) {
        fwrite(STDERR, sprintf("check-exception-usage: no PHP types found in %s\n", $exceptionDir));
        exit(2);
    }

    $unused = [];

    foreach ($declared as $type) {
        $name = $type['name'];
        $ownPath = $type['path'];
        $pattern = '/\b' . preg_quote($name, '/') . '\b/';

        $referenced = false;
        foreach ($haystack as $file) {
            if ($file === $ownPath) {
                continue;
            }

            $contents = @file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            if (preg_match($pattern, $contents) === 1) {
                $referenced = true;
                break;
            }
        }

        if (!$referenced) {
            $unused[] = $name;
        }
    }

    if ($unused !== []) {
        foreach ($unused as $name) {
            fwrite(STDERR, sprintf("check-exception-usage: error: %s is declared in %s but referenced by no other PHP file\n", $name, EXCEPTION_DIR_REL));
        }

        fwrite(STDERR, sprintf("check-exception-usage: FAILED — %d unused type(s) in %s\n", \count($unused), $exceptionDir));
        exit(1);
    }

    fwrite(STDOUT, sprintf("check-exception-usage: OK — %d type(s) in %s all referenced outside their own files\n", \count($declared), $exceptionDir));
    exit(0);
}

try {
    checkExceptionUsageMain(checkExceptionUsageParseArgs($_SERVER['argv'] ?? []));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
