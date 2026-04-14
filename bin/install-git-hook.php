#!/usr/bin/env php
<?php

$hookContent = <<<'HOOK'
#!/bin/bash
echo "Running pre-push lint checks..."
composer lint
exit $?
HOOK;

$gitHookDir = __DIR__ . '/../.git/hooks';
$prePushPath = $gitHookDir . '/pre-push';

if (!is_dir($gitHookDir)) {
    echo "Error: .git/hooks directory not found\n";
    exit(1);
}

if (file_put_contents($prePushPath, $hookContent) === false) {
    echo "Error: Failed to write pre-push hook\n";
    exit(1);
}

chmod($prePushPath, 0755);
echo "Git pre-push hook installed successfully\n";
