<?php

declare(strict_types=1);

/**
 * Installs the pre-push hook (see bin/README.md and docs/workflow.md).
 *
 * The hook runs `composer lint`, the canonical entry point (DEC-008), and
 * nothing else. The proof of work is four plain Markdown files a human reads
 * during review — there is nothing here for a script to verify, so there is
 * no gate to run.
 */
$hookContent = <<<HOOK
    #!/bin/bash
    echo "Running pre-push lint checks..."
    composer lint || exit 1

    exit 0
    HOOK;

$gitHookDir = __DIR__ . '/../.git/hooks';
$prePushPath = $gitHookDir . '/pre-push';

if (!is_dir($gitHookDir)) {
    echo "Error: .git/hooks directory not found\n";
    exit(1);
}

if (file_put_contents($prePushPath, $hookContent . "\n") === false) {
    echo "Error: Failed to write pre-push hook\n";
    exit(1);
}

chmod($prePushPath, 0o755);
echo "Git pre-push hook installed successfully\n";
