<?php

declare(strict_types=1);

require_once __DIR__ . '/pow-common.php';

/**
 * Installs the pre-push hook (see bin/README.md and docs/workflow.md).
 *
 * The hook runs `composer lint`, which includes the proof-of-work gate in
 * `--advisory` mode: it reports, it never fails. `composer lint` is the
 * canonical entry point (DEC-008) and must stay usable in the middle of a
 * cycle — the proof of work is legitimately incomplete until workflow step
 * 11.5, and Composer aborts an array script on the first non-zero command, so
 * a gate that can fail inside `lint` blocks every push on every branch.
 *
 * The blocking run happens once, and only on an issue branch, where a proof of
 * work is actually expected. It is not `--strict`: it fails on a `violation`
 * (evidence of tampering) and stays quiet about a cycle that is merely
 * unfinished. A hook that blocks every push is a hook people bypass with
 * `--no-verify`, which would make the whole gate fiction. The hard gate is CI.
 *
 * The branch pattern comes from bin/pow-common.php, so the hook, the gate and
 * bin/gh-branch cannot drift apart.
 */
$issueBranch = powcIssueBranchEre();

$hookContent = <<<HOOK
    #!/bin/bash
    echo "Running pre-push lint checks..."
    composer lint || exit 1

    # Proof-of-work gate (docs/workflow.md). `composer lint` above already ran
    # it in --advisory mode, which reports but never fails. Only an issue
    # branch — where a proof of work is expected — gets the blocking run, and
    # only evidence of tampering (a `violation`) blocks; an unfinished cycle
    # does not. Blocking every push would just teach everyone to use
    # `git push --no-verify`; the hard gate is CI.
    branch="\$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"

    if echo "\$branch" | grep -Eq '{$issueBranch}'; then
      if ! php bin/check-pow.php; then
        echo "pre-push: proof-of-work gate failed on \$branch — push blocked." >&2
        echo "pre-push: fix it, or push with --no-verify and expect CI to say no." >&2
        exit 1
      fi
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
