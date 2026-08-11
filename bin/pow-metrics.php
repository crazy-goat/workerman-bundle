#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reports metrics for the self-improving workflow (docs/workflow.md, step 15
 * "retro"), derived entirely from committed proof-of-work manifests.
 *
 * There is deliberately no `cycles.jsonl` or any other derived file: every
 * number here is recomputed from `docs/proof_of_work/<NNNN>-<slug>/` on every
 * run (see docs/process-notices.md, N-04). `manifest.json` and `findings.md`
 * are the only inputs, and the ledger is read through bin/pow-common.php's
 * parser — the one bin/pow.php and bin/check-pow.php already share — instead
 * of a third copy. Phase 2 drifted twice from exactly this kind of
 * duplication.
 *
 * Usage: php bin/pow-metrics.php [options]
 *
 * Options:
 *   --json              machine-readable output (JSON on stdout)
 *   --since=<n>          only consider the last <n> finished cycles
 *                        (ordered by manifest.created_at, oldest first)
 *   --min-cycles=<n>     fail (exit 1) when fewer than <n> cycles are in
 *                        scope — default 3, matching the retro's own
 *                        "diagnose over >=3 manifests" requirement, so step 15
 *                        cannot run on thin evidence
 *   -h, --help           show this help
 *
 * Exit codes:
 *   0  ok, enough history for --min-cycles
 *   1  runtime error, or fewer than --min-cycles cycles in scope
 *   2  usage error
 *
 * Environment:
 *   POW_ROOT   repository root to operate on (default: the parent of bin/),
 *              same variable bin/pow.php uses — the test suite points both at
 *              the same throwaway sandbox
 */

require_once __DIR__ . '/pow-common.php';

const POWM_DEFAULT_MIN_CYCLES = 3;

const POWM_FLAGS = ['json', 'help'];

const POWM_VALUE_OPTIONS = ['since', 'min-cycles'];

function powmFail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'pow-metrics: ' . $message . "\n");
    exit($code);
}

function powmNotice(string $message): void
{
    fwrite(STDERR, 'pow-metrics: ' . $message . "\n");
}

function powmRoot(): string
{
    $configured = (string) getenv('POW_ROOT');
    $root = $configured !== '' ? $configured : dirname(__DIR__);
    $real = realpath($root);

    if ($real === false || !is_dir($real)) {
        powmFail('POW_ROOT is not a directory: ' . $root, 2);
    }

    return $real;
}

function powmDir(string $root): string
{
    return $root . '/docs/proof_of_work';
}

/**
 * @return array<string, mixed>|null
 */
function powmDecode(string $json): ?array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

/**
 * One finished cycle, read defensively: a manifest this script cannot parse
 * is reported to stderr and skipped rather than aborting every other cycle's
 * report — this is a reporting tool, not a second enforcement path (that is
 * bin/check-pow.php's job).
 *
 * @return array<string, mixed>|null
 */
function powmReadCycle(string $dir): ?array
{
    $manifestFile = $dir . '/manifest.json';

    if (!is_file($manifestFile)) {
        powmNotice('skipping ' . basename($dir) . ' — no manifest.json');

        return null;
    }

    $contents = @file_get_contents($manifestFile);
    $manifest = $contents === false ? null : powmDecode($contents);

    if ($manifest === null) {
        powmNotice('skipping ' . basename($dir) . ' — manifest.json is not valid JSON');

        return null;
    }

    $issue = $manifest['issue'] ?? null;
    $profile = $manifest['profile'] ?? null;
    $roundCap = $manifest['round_cap'] ?? null;
    $rounds = is_array($manifest['rounds'] ?? null) ? $manifest['rounds'] : [];
    $createdAt = is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : '';
    $verdict = is_string($manifest['verdict'] ?? null) ? $manifest['verdict'] : null;
    $lintExit = is_int($manifest['lint_exit'] ?? null) ? $manifest['lint_exit'] : null;
    $testExit = is_int($manifest['test_exit'] ?? null) ? $manifest['test_exit'] : null;
    $coverage = is_int($manifest['coverage'] ?? null) || is_float($manifest['coverage'] ?? null)
        ? (float) $manifest['coverage']
        : null;
    $gatesAdded = is_array($manifest['gates_added'] ?? null) ? array_values($manifest['gates_added']) : [];
    $aborted = is_array($manifest['aborted'] ?? null) ? array_values($manifest['aborted']) : [];

    if (!is_int($issue) || !is_string($profile) || !is_int($roundCap)) {
        powmNotice('skipping ' . basename($dir) . ' — manifest.json is missing issue/profile/round_cap');

        return null;
    }

    $ledgerFile = $dir . '/findings.md';
    $ledgerText = is_file($ledgerFile) ? (string) file_get_contents($ledgerFile) : '';
    $parsed = powcParseLedger($ledgerText);

    if ($parsed['errors'] !== []) {
        powmNotice(sprintf(
            '%s — findings.md has %d malformed row(s), counted as zero findings for this cycle',
            basename($dir),
            count($parsed['errors']),
        ));
    }

    $counters = powcFindingCounters(powcLedgerState($parsed['rows']));

    return [
        'dir' => basename($dir),
        'issue' => $issue,
        'slug' => is_string($manifest['slug'] ?? null) ? $manifest['slug'] : '',
        'profile' => $profile,
        'created_at' => $createdAt,
        'round_cap' => $roundCap,
        'rounds_used' => count($rounds),
        'at_cap' => count($rounds) >= $roundCap,
        'verdict' => $verdict,
        'escalated' => $verdict !== null && $verdict !== 'CLEAN',
        'lint_exit' => $lintExit,
        'test_exit' => $testExit,
        'coverage' => $coverage,
        'gates_added' => $gatesAdded,
        'aborted' => count($aborted),
        'findings' => $counters,
        'escape_rate' => $counters['total'] > 0 ? $counters['escaped'] / $counters['total'] : null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function powmDiscoverCycles(string $root): array
{
    $matches = glob(powmDir($root) . '/[0-9]*-*', GLOB_ONLYDIR);

    if ($matches === false) {
        return [];
    }

    $cycles = [];

    foreach ($matches as $dir) {
        $cycle = powmReadCycle($dir);

        if ($cycle !== null) {
            $cycles[] = $cycle;
        }
    }

    // Oldest first, by the server-assigned start time; issue number breaks a
    // tie (and is the only ordering possible when created_at is unreadable).
    usort($cycles, static function (array $a, array $b): int {
        $timeA = is_string($a['created_at']) && $a['created_at'] !== '' ? strtotime($a['created_at']) : false;
        $timeB = is_string($b['created_at']) && $b['created_at'] !== '' ? strtotime($b['created_at']) : false;

        if ($timeA !== false && $timeB !== false && $timeA !== $timeB) {
            return $timeA <=> $timeB;
        }

        return (int) $a['issue'] <=> (int) $b['issue'];
    });

    return $cycles;
}

/**
 * @param list<array<string, mixed>> $cycles
 *
 * @return array<string, mixed>
 */
function powmAggregate(array $cycles): array
{
    $totalRounds = 0;
    $atCap = 0;
    $findingTotals = ['total' => 0, 'round1' => 0, 'escaped' => 0, 'open' => 0, 'fixed' => 0, 'gated' => 0, 'wontfix' => 0];
    $gatesAdded = 0;
    $verdictCounts = [];
    $escalated = 0;
    $lintClean = 0;
    $testClean = 0;
    $coverageValues = [];

    foreach ($cycles as $cycle) {
        $totalRounds += (int) $cycle['rounds_used'];
        $atCap += $cycle['at_cap'] === true ? 1 : 0;

        /** @var array{total:int,round1:int,escaped:int,open:int,fixed:int,gated:int,wontfix:int} $findings */
        $findings = $cycle['findings'];

        foreach ($findingTotals as $key => $value) {
            $findingTotals[$key] = $value + $findings[$key];
        }

        $gatesAdded += count((array) $cycle['gates_added']);
        $verdict = $cycle['verdict'] ?? 'UNSET';
        $verdictKey = is_string($verdict) ? $verdict : 'UNSET';
        $verdictCounts[$verdictKey] = ($verdictCounts[$verdictKey] ?? 0) + 1;
        $escalated += $cycle['escalated'] === true ? 1 : 0;
        $lintClean += $cycle['lint_exit'] === 0 ? 1 : 0;
        $testClean += $cycle['test_exit'] === 0 ? 1 : 0;

        if (is_float($cycle['coverage'])) {
            $coverageValues[] = $cycle['coverage'];
        }
    }

    $count = count($cycles);

    return [
        'cycles' => $count,
        'rounds_used_avg' => $count > 0 ? $totalRounds / $count : null,
        'cycles_at_cap' => $atCap,
        'findings' => $findingTotals,
        'escape_rate' => $findingTotals['total'] > 0 ? $findingTotals['escaped'] / $findingTotals['total'] : null,
        'gates_added_total' => $gatesAdded,
        'verdicts' => $verdictCounts,
        'escalated_cycles' => $escalated,
        'lint_clean_cycles' => $lintClean,
        'test_clean_cycles' => $testClean,
        'coverage_avg' => $coverageValues === [] ? null : array_sum($coverageValues) / count($coverageValues),
        'coverage_min' => $coverageValues === [] ? null : min($coverageValues),
        'coverage_max' => $coverageValues === [] ? null : max($coverageValues),
    ];
}

function powmUsage(): void
{
    fwrite(STDOUT, <<<TXT
        pow-metrics.php — retro metrics derived from docs/proof_of_work/ manifests

        Usage: php bin/pow-metrics.php [options]

        Options:
          --json               machine-readable output (JSON on stdout)
          --since=<n>           only the last <n> finished cycles (oldest first)
          --min-cycles=<n>      fail (exit 1) below <n> cycles in scope (default 3)
          -h, --help            show this help

        No cycles.jsonl exists or is written: everything here is recomputed from
        docs/proof_of_work/<NNNN>-<slug>/manifest.json and findings.md on every run.

        Exit codes: 0 ok, 1 runtime error or not enough cycles, 2 usage error.

        TXT);
}

/**
 * @param list<string> $argv
 *
 * @return array{json: bool, since: int|null, min-cycles: int}
 */
function powmParseArgs(array $argv): array
{
    $options = ['json' => false, 'since' => null, 'min-cycles' => POWM_DEFAULT_MIN_CYCLES];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            powmUsage();
            exit(0);
        }

        if (!str_starts_with($arg, '--')) {
            powmFail('unexpected argument "' . $arg . '" (see --help)', 2);
        }

        $eq = strpos($arg, '=');
        $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
        $value = $eq === false ? null : substr($arg, $eq + 1);

        if (in_array($name, POWM_FLAGS, true)) {
            if ($value !== null) {
                powmFail('--' . $name . ' does not take a value', 2);
            }

            $options[$name] = true;

            continue;
        }

        if (!in_array($name, POWM_VALUE_OPTIONS, true)) {
            powmFail('unknown option --' . $name . ' (see --help)', 2);
        }

        if ($value === null || $value === '' || !ctype_digit($value)) {
            powmFail('--' . $name . ' expects a non-negative integer, got ' . var_export($value, true), 2);
        }

        $options[$name] = (int) $value;
    }

    /** @var array{json: bool, since: int|null, min-cycles: int} $options */
    return $options;
}

/**
 * @param array{json: bool, since: int|null, min-cycles: int} $options
 */
function powmMain(array $options): int
{
    $root = powmRoot();
    $all = powmDiscoverCycles($root);
    $available = count($all);

    $since = $options['since'];
    $cycles = $since !== null && $since < $available ? array_slice($all, $available - $since, $since) : $all;

    $aggregate = powmAggregate($cycles);
    $minCycles = $options['min-cycles'];
    $enough = count($cycles) >= $minCycles;

    if ($options['json']) {
        echo json_encode([
            'root' => $root,
            'cycles_available' => $available,
            'since' => $since,
            'min_cycles' => $minCycles,
            'enough_for_retro' => $enough,
            'cycles' => $cycles,
            'aggregate' => $aggregate,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDOUT, sprintf(
            "pow-metrics: %d cycle(s) available, %d in scope%s\n",
            $available,
            count($cycles),
            $since !== null ? ' (--since=' . $since . ')' : '',
        ));

        foreach ($cycles as $cycle) {
            fwrite(STDOUT, sprintf(
                "  #%-5d %-28s %-6s rounds %d/%d%s  findings %d (round1 %d, escaped %d, open %d, fixed %d, gated %d, wontfix %d)  escape %s  verdict %s%s  lint=%s test=%s coverage=%s  gates+%d\n",
                (int) $cycle['issue'],
                (string) $cycle['slug'],
                (string) $cycle['profile'],
                (int) $cycle['rounds_used'],
                (int) $cycle['round_cap'],
                $cycle['at_cap'] === true ? '*' : ' ',
                $cycle['findings']['total'],
                $cycle['findings']['round1'],
                $cycle['findings']['escaped'],
                $cycle['findings']['open'],
                $cycle['findings']['fixed'],
                $cycle['findings']['gated'],
                $cycle['findings']['wontfix'],
                $cycle['escape_rate'] === null ? 'n/a' : sprintf('%.0f%%', $cycle['escape_rate'] * 100),
                (string) ($cycle['verdict'] ?? '-'),
                $cycle['escalated'] === true ? ' (escalated)' : '',
                $cycle['lint_exit'] === null ? '-' : (string) $cycle['lint_exit'],
                $cycle['test_exit'] === null ? '-' : (string) $cycle['test_exit'],
                $cycle['coverage'] === null ? '-' : sprintf('%.1f%%', $cycle['coverage']),
                count((array) $cycle['gates_added']),
            ));
        }

        fwrite(STDOUT, sprintf(
            "\naggregate: rounds_used_avg=%s cycles_at_cap=%d escape_rate=%s gates_added_total=%d escalated_cycles=%d lint_clean=%d/%d test_clean=%d/%d coverage_avg=%s\n",
            $aggregate['rounds_used_avg'] === null ? 'n/a' : sprintf('%.2f', $aggregate['rounds_used_avg']),
            $aggregate['cycles_at_cap'],
            $aggregate['escape_rate'] === null ? 'n/a' : sprintf('%.1f%%', $aggregate['escape_rate'] * 100),
            $aggregate['gates_added_total'],
            $aggregate['escalated_cycles'],
            $aggregate['lint_clean_cycles'],
            count($cycles),
            $aggregate['test_clean_cycles'],
            count($cycles),
            $aggregate['coverage_avg'] === null ? 'n/a' : sprintf('%.1f%%', $aggregate['coverage_avg']),
        ));

        $verdicts = $aggregate['verdicts'];

        if (is_array($verdicts) && $verdicts !== []) {
            $parts = [];

            foreach ($verdicts as $verdict => $count) {
                $parts[] = $verdict . '=' . $count;
            }

            fwrite(STDOUT, 'verdicts: ' . implode(' ', $parts) . "\n");
        }
    }

    if (!$enough) {
        fwrite(STDERR, sprintf(
            "pow-metrics: only %d cycle(s) in scope, step 15 (retro) needs at least %d — not enough evidence yet\n",
            count($cycles),
            $minCycles,
        ));

        return 1;
    }

    return 0;
}

try {
    exit(powmMain(powmParseArgs($argv)));
} catch (Throwable $e) {
    powmFail($e->getMessage());
}
