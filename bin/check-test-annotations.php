<?php

declare(strict_types=1);

use CrazyGoat\WorkermanBundle\Tools\PhpCsFixer\TestAnnotationTags;

require_once __DIR__ . '/../tools/php-cs-fixer/TestAnnotationTags.php';

const TEST_ANNOTATION_ROOTS = ['src', 'tests', 'benchmarks', 'bin', 'e2e', 'tools'];

const TEST_ANNOTATION_SKIP_DIRS = ['vendor', 'var', 'build', 'node_modules'];

/**
 * @param list<string> $argv
 * @return array{root: string}
 */
function checkTestAnnotationsParseArgs(array $argv): array
{
    $options = ['root' => \dirname(__DIR__)];

    foreach (\array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            checkTestAnnotationsPrintUsage($argv);

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

    if ($root === false || !is_dir($root)) {
        fwrite(STDERR, sprintf("Root directory does not exist: %s\n", $options['root']));
        exit(2);
    }

    return ['root' => $root];
}

/** @param list<string> $argv */
function checkTestAnnotationsPrintUsage(array $argv): void
{
    fwrite(STDOUT, ($argv[0] ?? 'bin/check-test-annotations.php') . " — fail on PHPUnit/PHPBench doc annotations; use attributes instead\n\n");
    fwrite(STDOUT, "Usage: php bin/check-test-annotations.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --root=DIR            repository root to scan\n");
    fwrite(STDOUT, "                        (default: the parent of bin/)\n");
    fwrite(STDOUT, "  --help                show this help\n");
}

/**
 * @return list<string>
 */
function checkTestAnnotationsFiles(string $root): array
{
    $files = [];
    foreach (TEST_ANNOTATION_ROOTS as $relative) {
        $dir = $root . '/' . $relative;
        if (!is_dir($dir)) {
            continue;
        }

        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $file): bool => !$file->isDir() || !\in_array($file->getFilename(), TEST_ANNOTATION_SKIP_DIRS, true),
        );

        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    if (is_file($root . '/e2e/console')) {
        $files[] = $root . '/e2e/console';
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function checkTestAnnotationsFindings(string $root): array
{
    $found = [];
    foreach (checkTestAnnotationsFiles($root) as $path) {
        $source = file_get_contents($path);
        if ($source === false) {
            $found[] = sprintf('%s: unreadable', substr($path, \strlen($root) + 1));

            continue;
        }

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                continue;
            }

            $tag = TestAnnotationTags::find($token[1]);
            if ($tag !== null) {
                $found[] = sprintf('%s:%d @%s', substr($path, \strlen($root) + 1), $token[2], $tag);
            }
        }
    }

    return $found;
}

function checkTestAnnotationsMain(): void
{
    /** @var list<string> $argv */
    $argv = $_SERVER['argv'] ?? [];
    $options = checkTestAnnotationsParseArgs($argv);
    fwrite(STDOUT, sprintf("check-test-annotations: root %s\n", $options['root']));

    $found = checkTestAnnotationsFindings($options['root']);
    if ($found !== []) {
        foreach ($found as $line) {
            fwrite(STDERR, sprintf("check-test-annotations: error: %s is a doc annotation; use the PHPUnit/PHPBench attribute instead\n", $line));
        }
        fwrite(STDERR, sprintf("check-test-annotations: FAILED — %d doc annotation(s); CrazyGoat/no_narrative_comments leaves these docblocks alone until they are converted\n", \count($found)));

        exit(1);
    }

    fwrite(STDOUT, "check-test-annotations: OK — no PHPUnit or PHPBench doc annotations\n");
}

checkTestAnnotationsMain();
