<?php

declare(strict_types=1);

const EXCEPTION_DIR_REL = 'src/Exception';

const SEARCH_ROOTS = ['src', 'tests', 'e2e', 'benchmarks'];

/**
 * @param list<string> $argv
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

        for ($j = $i + 1; $j < $count; ++$j) {
            if (!\is_array($tokens[$j])) {
                break;
            }

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

    $haystack = [];
    foreach (SEARCH_ROOTS as $relativeRoot) {
        foreach (checkExceptionUsagePhpFiles($options['root'] . '/' . $relativeRoot) as $file) {
            $haystack[] = $file;
        }
    }

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
