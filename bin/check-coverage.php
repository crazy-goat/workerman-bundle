<?php

declare(strict_types=1);

/**
 * Parses a PHPUnit Clover coverage file and exits non-zero if total line
 * coverage is below the requested threshold.
 *
 * Usage: php bin/check-coverage.php <clover.xml> [threshold-percent]
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/check-coverage.php <clover.xml> [threshold-percent]\n");
    exit(2);
}

$cloverFile = $argv[1];
$thresholdPercent = isset($argv[2]) ? (float) $argv[2] : 0.0;

if (!is_readable($cloverFile)) {
    fwrite(STDERR, sprintf("Coverage file not readable: %s\n", $cloverFile));
    exit(2);
}

$xml = simplexml_load_file($cloverFile);
if ($xml === false) {
    fwrite(STDERR, sprintf("Unable to parse coverage file: %s\n", $cloverFile));
    exit(2);
}

$metrics = $xml->xpath('//metrics');
if ($metrics === false || $metrics === []) {
    fwrite(STDERR, "No <metrics> elements found in coverage file.\n");
    exit(2);
}

$totalStatements = 0;
$coveredStatements = 0;

foreach ($metrics as $metric) {
    $statements = (int) ((string) ($metric['statements'] ?? '0'));
    $covered = (int) ((string) ($metric['coveredstatements'] ?? '0'));

    $totalStatements += $statements;
    $coveredStatements += $covered;
}

if ($totalStatements === 0) {
    fwrite(STDERR, "No executable statements found in coverage file.\n");
    exit(2);
}

$coveragePercent = ($coveredStatements / $totalStatements) * 100.0;
$status = $coveragePercent >= $thresholdPercent ? 0 : 1;

printf(
    "Coverage: %.2f%% (%d/%d statements). Threshold: %.2f%%. %s\n",
    $coveragePercent,
    $coveredStatements,
    $totalStatements,
    $thresholdPercent,
    $status === 0 ? 'OK' : 'FAILED'
);

exit($status);
