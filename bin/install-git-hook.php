<?php

declare(strict_types=1);

/**
 * Installs the pre-push hook (see bin/README.md and docs/workflow.md).
 *
 * The hook runs `composer lint` and then `php bin/check-pow.php`. The proof-of-
 * work gate WARNS on every push but BLOCKS only on an issue branch: a hook that
 * blocks every push is a hook people bypass with `--no-verify`, which would make
 * the whole gate fiction. The hard gate is CI.
 */
$hookContent = <<<'HOOK'
    #!/bin/bash
    echo "Running pre-push lint checks..."
    composer lint || exit 1

    # Proof-of-work gate (docs/workflow.md). Advisory by default: it warns on
    # every push, but only an issue branch — where a proof of work is actually
    # expected — can block the push. Blocking every push would just teach
    # everyone to use `git push --no-verify`; the hard gate is CI.
    branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
    php bin/check-pow.php
    pow_status=$?

    if [ "$pow_status" -ne 0 ]; then
      if echo "$branch" | grep -Eq '^(fix|feat|process)/issue-[0-9]+'; then
        echo "pre-push: proof-of-work gate failed on $branch — push blocked." >&2
        echo "pre-push: fix it, or push with --no-verify and expect CI to say no." >&2
        exit 1
      fi

      echo "pre-push: proof-of-work gate reported problems (advisory on $branch)." >&2
    fi

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
