<?php

declare(strict_types=1);

/**
 * Installs the pre-push hook (see bin/README.md and AGENTS.md).
 *
 * The hook runs `composer lint`, which calls `bin/lint.sh` (DEC-008), with
 * LINT_SKIP_AUDIT=1: `composer audit` needs the network and is left to CI. The hard gate is `ci-ok` in CI.
 */
$hookContent = <<<HOOK
    #!/bin/bash
    echo "Running pre-push lint checks..."
    LINT_SKIP_AUDIT=1 composer lint || exit 1

    exit 0
    HOOK;

// `git rev-parse --git-path hooks` also works in a worktree, where `.git` is a file.
$resolved = trim((string) shell_exec('cd ' . escapeshellarg(__DIR__ . '/..') . ' && git rev-parse --git-path hooks 2>/dev/null'));
if ($resolved !== '' && $resolved[0] !== '/') {
    $resolved = __DIR__ . '/../' . $resolved;
}
$gitHookDir = $resolved !== '' ? $resolved : __DIR__ . '/../.git/hooks';
$prePushPath = $gitHookDir . '/pre-push';

if (!is_dir($gitHookDir)) {
    echo "Error: git hooks directory not found: {$gitHookDir}\n";
    exit(1);
}

if (file_put_contents($prePushPath, $hookContent . "\n") === false) {
    echo "Error: Failed to write pre-push hook\n";
    exit(1);
}

chmod($prePushPath, 0o755);
echo "Git pre-push hook installed successfully\n";
