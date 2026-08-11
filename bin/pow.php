#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Proof-of-work recorder for the issue cycle described in docs/workflow.md.
 *
 * The cycle narrative (context, plan, coder, review, ci, audit) lives in PR
 * comments; only the durable, decision-carrying part is committed:
 * `manifest.json`, `findings.md` and `escalation.md` when it exists.
 *
 * Round comments are NEVER authored here: `--round` reads the harness
 * artifact `.pi-subagents/artifacts/<runId>_<agent>_0_output.md`, injects
 * front matter derived from the neighbouring `_meta.json`, and publishes the
 * result verbatim. A round with no matching `run_id` on disk is invalid.
 *
 * Usage: php bin/pow.php <command> [options]
 *
 * Commands:
 *   --start --issue=N [--slug=<kebab>] [--branch=<name>] [--profile=full|light]
 *   --round=N --role=<coder|review|oracle|auditor|...> --run=<runId> [--dry-run]
 *   --finding --id=<F-NN> --round=N --loc=<file:line> --desc=<text>
 *             --severity=<high|medium|low|nit> [--status=open]
 *   --resolve --id=<F-NN> --round=N --status=<fixed|gated|wontfix>
 *             --resolution=<text>
 *   --verdict=<CLEAN|NARROW|REDO|ACCEPT|HUMAN>
 *   --set <key>=<value> [--gate=<text>] [--abort=<runId>:<reason>]
 *   --finish
 *   --abort [--reason=<text>]
 *   --status
 *   -h | --help
 *
 * Note on the two dual-purpose flags: `--status` and `--abort` are commands
 * when written bare and options when written with a value, so the value form
 * (`--status=fixed`, `--abort=<runId>:<reason>`) always requires the `=` sign.
 *
 * Exit codes:
 *   0  ok
 *   1  runtime / validation error
 *   2  usage error
 *
 * Environment (testing hooks, never needed in normal use):
 *   POW_ROOT    repository root to operate on (default: the parent of bin/)
 *   POW_NO_GH   when set to 1, no `gh` call is ever made; `--round` then
 *               requires `--dry-run` and `--start` cannot look up the issue
 *               title or labels (pass `--slug`/`--profile` instead)
 */

/** Round caps per profile. There is no round beyond the cap — the oracle decides. */
const POW_PROFILE_CAPS = ['full' => 4, 'light' => 2];

/** Branch prefixes that select the `full` profile (audit + gate steps mandatory). */
const POW_FULL_PREFIXES = ['fix', 'feat', 'refactor', 'perf', 'process'];

/** Branch prefixes that select the `light` profile (cap 2, no gate step). */
const POW_LIGHT_PREFIXES = ['docs', 'chore', 'ci', 'test', 'build'];

const POW_SEVERITIES = ['high', 'medium', 'low', 'nit'];

const POW_STATUSES = ['open', 'fixed', 'gated', 'wontfix'];

const POW_RESOLVE_STATUSES = ['fixed', 'gated', 'wontfix'];

const POW_VERDICTS = ['CLEAN', 'NARROW', 'REDO', 'ACCEPT', 'HUMAN'];

/** Machine facts accepted by `--set`, mapped to their manifest type. */
const POW_SET_KEYS = ['lint_exit' => 'int', 'test_exit' => 'int', 'coverage' => 'float'];

/** Flags that never take a value. */
const POW_FLAGS = ['start', 'finding', 'resolve', 'finish', 'dry-run', 'help'];

/** Options that take a value, in either `--name=value` or `--name value` form. */
const POW_VALUE_OPTIONS = [
    'issue', 'slug', 'branch', 'profile', 'round', 'role', 'run',
    'id', 'loc', 'desc', 'severity', 'resolution', 'verdict', 'reason',
    'gate', 'set',
];

/** Dual-purpose options: bare = command, `--name=value` = option. */
const POW_DUAL_OPTIONS = ['status', 'abort'];

// --------------------------------------------------------------------------
// Small helpers
// --------------------------------------------------------------------------

function powFail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'pow: ' . $message . "\n");
    exit($code);
}

function powInfo(string $message): void
{
    fwrite(STDERR, $message . "\n");
}

/**
 * Runs a command without a shell and returns its exit code and streams.
 *
 * @param list<string> $cmd
 *
 * @return array{code: int, out: string, err: string}
 */
function powRun(array $cmd, ?string $cwd = null): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = @proc_open($cmd, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
        return ['code' => 127, 'out' => '', 'err' => 'unable to start: ' . implode(' ', $cmd)];
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
}

function powGhDisabled(): bool
{
    return (string) getenv('POW_NO_GH') === '1';
}

/**
 * @param list<string> $args
 *
 * @return array{code: int, out: string, err: string}
 */
function powGh(array $args, string $root): array
{
    if (powGhDisabled()) {
        return ['code' => 127, 'out' => '', 'err' => 'gh disabled by POW_NO_GH=1'];
    }

    return powRun(['gh', ...$args], $root);
}

function powSlugify(string $text): string
{
    $slug = strtolower($text);
    $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if (strlen($slug) > 60) {
        $slug = rtrim(substr($slug, 0, 60), '-');
    }

    return $slug;
}

function powUtcNow(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** Escapes a free-text value so it survives inside one markdown table cell. */
function powCell(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace('|', '\\|', $text);

    return trim(str_replace("\n", '<br>', $text));
}

function powUncell(string $text): string
{
    return str_replace(['\\|', '<br>'], ['|', "\n"], $text);
}

function powMkdir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!@mkdir($dir, 0o775, true) && !is_dir($dir)) {
        powFail('unable to create directory ' . $dir);
    }
}

function powWrite(string $file, string $contents): void
{
    powMkdir(dirname($file));

    if (file_put_contents($file, $contents) === false) {
        powFail('unable to write ' . $file);
    }
}

function powRead(string $file): string
{
    $contents = @file_get_contents($file);

    if ($contents === false) {
        powFail('unable to read ' . $file);
    }

    return $contents;
}

// --------------------------------------------------------------------------
// Paths
// --------------------------------------------------------------------------

function powRoot(): string
{
    $configured = (string) getenv('POW_ROOT');
    $root = $configured !== '' ? $configured : dirname(__DIR__);
    $real = realpath($root);

    if ($real === false || !is_dir($real)) {
        powFail('POW_ROOT is not a directory: ' . $root);
    }

    return $real;
}

function powDir(string $root): string
{
    return $root . '/docs/proof_of_work';
}

function powCurrentDir(string $root): string
{
    return powDir($root) . '/current';
}

function powManifestFile(string $root): string
{
    return powCurrentDir($root) . '/manifest.json';
}

function powLedgerFile(string $root): string
{
    return powCurrentDir($root) . '/findings.md';
}

function powEscalationFile(string $root): string
{
    return powCurrentDir($root) . '/escalation.md';
}

function powArtifactsDir(string $root): string
{
    return $root . '/.pi-subagents/artifacts';
}

// --------------------------------------------------------------------------
// Manifest
// --------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function powLoadManifest(string $root): array
{
    $file = powManifestFile($root);

    if (!is_file($file)) {
        powFail('no cycle in progress (' . $file . ' is missing) — run --start first');
    }

    try {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode(powRead($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        powFail('malformed manifest.json: ' . $e->getMessage());
    }

    if (!is_array($manifest)) {
        powFail('malformed manifest.json: expected an object');
    }

    return $manifest;
}

/**
 * @param array<string, mixed> $manifest
 */
function powSaveManifest(string $root, array $manifest): void
{
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        powFail('unable to encode manifest.json');
    }

    powWrite(powManifestFile($root), $json . "\n");
}

/**
 * @return array<string, mixed>
 */
function powNewManifest(int $issue, string $slug, string $branch, string $profile): array
{
    return [
        'issue' => $issue,
        'slug' => $slug,
        'branch' => $branch,
        'profile' => $profile,
        'round_cap' => POW_PROFILE_CAPS[$profile],
        'created_at' => powUtcNow(),
        'rounds' => [],
        'commits' => [],
        'files_changed' => [],
        'lint_exit' => null,
        'test_exit' => null,
        'coverage' => null,
        'findings' => ['total' => 0, 'round1' => 0, 'escaped' => 0, 'open' => 0],
        'gates_added' => [],
        'aborted' => [],
        'verdict' => null,
    ];
}

// --------------------------------------------------------------------------
// Ledger
// --------------------------------------------------------------------------

function powLedgerHeader(int $issue): string
{
    return <<<MD
        # Findings ledger — issue #{$issue}

        Append-only. A status change is a NEW row with the same ID; an ID's effective
        status is that of its last row. Rows are never edited or deleted.

        | ID | round | file:line | description | severity | status | resolution |
        | --- | --- | --- | --- | --- | --- | --- |

        MD;
}

/**
 * Parses the ledger rows in file order.
 *
 * @return list<array{id: string, round: int, loc: string, desc: string, severity: string, status: string, resolution: string}>
 */
function powLedgerRows(string $root): array
{
    $file = powLedgerFile($root);

    if (!is_file($file)) {
        return [];
    }

    $rows = [];

    foreach (explode("\n", powRead($file)) as $line) {
        $line = trim($line);

        if (!str_starts_with($line, '|')) {
            continue;
        }

        $cells = preg_split('/(?<!\\\\)\|/', $line);

        if ($cells === false || count($cells) < 9) {
            continue;
        }

        $cells = array_map('trim', array_slice($cells, 1, 7));

        if ($cells[0] === 'ID' || str_starts_with($cells[0], '---')) {
            continue;
        }

        $rows[] = [
            'id' => $cells[0],
            'round' => (int) $cells[1],
            'loc' => powUncell($cells[2]),
            'desc' => powUncell($cells[3]),
            'severity' => $cells[4],
            'status' => $cells[5],
            'resolution' => powUncell($cells[6]),
        ];
    }

    return $rows;
}

/**
 * Effective (last-row) status per finding ID, in first-seen order.
 *
 * @param list<array{id: string, round: int, loc: string, desc: string, severity: string, status: string, resolution: string}> $rows
 *
 * @return array<string, array{first_round: int, status: string, loc: string, desc: string, severity: string}>
 */
function powLedgerState(array $rows): array
{
    $state = [];

    foreach ($rows as $row) {
        if (!isset($state[$row['id']])) {
            $state[$row['id']] = [
                'first_round' => $row['round'],
                'status' => $row['status'],
                'loc' => $row['loc'],
                'desc' => $row['desc'],
                'severity' => $row['severity'],
            ];

            continue;
        }

        $state[$row['id']]['status'] = $row['status'];
    }

    return $state;
}

/**
 * @param array{id: string, round: int, loc: string, desc: string, severity: string, status: string, resolution: string} $row
 */
function powAppendRow(string $root, array $row): void
{
    $file = powLedgerFile($root);
    $line = sprintf(
        "| %s | %d | %s | %s | %s | %s | %s |\n",
        powCell($row['id']),
        $row['round'],
        powCell($row['loc']),
        powCell($row['desc']),
        powCell($row['severity']),
        powCell($row['status']),
        powCell($row['resolution']),
    );

    if (file_put_contents($file, $line, FILE_APPEND) === false) {
        powFail('unable to append to ' . $file);
    }
}

/**
 * @param list<array{id: string, round: int, loc: string, desc: string, severity: string, status: string, resolution: string}> $rows
 *
 * @return array{total: int, round1: int, escaped: int, open: int}
 */
function powFindingCounters(array $rows): array
{
    $state = powLedgerState($rows);
    $round1 = 0;
    $escaped = 0;
    $open = 0;

    foreach ($state as $entry) {
        if ($entry['first_round'] <= 1) {
            $round1++;
        } else {
            $escaped++;
        }

        if ($entry['status'] === 'open') {
            $open++;
        }
    }

    return ['total' => count($state), 'round1' => $round1, 'escaped' => $escaped, 'open' => $open];
}

/**
 * @return list<string>
 */
function powOpenIds(string $root): array
{
    $open = [];

    foreach (powLedgerState(powLedgerRows($root)) as $id => $entry) {
        if ($entry['status'] === 'open') {
            $open[] = (string) $id;
        }
    }

    natsort($open);

    return array_values($open);
}

// --------------------------------------------------------------------------
// Archiving
// --------------------------------------------------------------------------

/**
 * @return list<string> entries of current/ other than .gitkeep
 */
function powCurrentEntries(string $root): array
{
    $dir = powCurrentDir($root);

    if (!is_dir($dir)) {
        return [];
    }

    $entries = scandir($dir);

    if ($entries === false) {
        return [];
    }

    return array_values(array_filter(
        $entries,
        static fn(string $entry): bool => !in_array($entry, ['.', '..', '.gitkeep'], true),
    ));
}

/**
 * Moves everything in current/ (except .gitkeep) into .abandoned/<ts>/.
 * Nothing is ever deleted.
 */
function powArchiveCurrent(string $root, ?string $reason): string
{
    $stamp = gmdate('Ymd\THis\Z');
    $base = powDir($root) . '/.abandoned/' . $stamp;
    $dest = $base;
    $suffix = 1;

    while (is_dir($dest)) {
        $dest = $base . '-' . $suffix++;
    }

    powMkdir($dest);

    foreach (powCurrentEntries($root) as $entry) {
        if (!@rename(powCurrentDir($root) . '/' . $entry, $dest . '/' . $entry)) {
            powFail('unable to archive ' . $entry . ' into ' . $dest);
        }
    }

    if ($reason !== null && $reason !== '') {
        powWrite($dest . '/abort-reason.txt', $reason . "\n");
    }

    powResetCurrent($root);

    return $dest;
}

function powResetCurrent(string $root): void
{
    $dir = powCurrentDir($root);
    powMkdir($dir);

    if (!is_file($dir . '/.gitkeep')) {
        powWrite($dir . '/.gitkeep', '');
    }
}

// --------------------------------------------------------------------------
// Commands
// --------------------------------------------------------------------------

/**
 * @param array<string, mixed> $options
 */
function powCommandStart(string $root, array $options): void
{
    $issue = $options['issue'];

    if (!is_int($issue) || $issue <= 0) {
        powFail('--start requires --issue=<positive integer>', 2);
    }

    $branch = is_string($options['branch']) ? $options['branch'] : powCurrentBranch($root);

    if ($branch === '') {
        powFail('unable to determine the branch — pass --branch=<name>');
    }

    $slug = is_string($options['slug']) ? powSlugify($options['slug']) : powSlugFromIssue($root, $issue);

    if ($slug === '') {
        powFail('unable to determine a slug — pass --slug=<kebab-case>');
    }

    $profile = powResolveProfile($root, $issue, $branch, $options['profile']);

    if (powCurrentEntries($root) !== []) {
        $archived = powArchiveCurrent($root, 'superseded by --start --issue=' . $issue);
        powInfo('Archived the previous unfinished cycle to ' . powRelative($root, $archived));
    }

    powResetCurrent($root);
    powSaveManifest($root, powNewManifest($issue, $slug, $branch, $profile));
    powWrite(powLedgerFile($root), powLedgerHeader($issue));

    fwrite(STDOUT, sprintf(
        "Started proof of work for issue #%d\n  slug:      %s\n  branch:    %s\n  profile:   %s (round cap %d)\n  manifest:  %s\n  ledger:    %s\n",
        $issue,
        $slug,
        $branch,
        $profile,
        POW_PROFILE_CAPS[$profile],
        powRelative($root, powManifestFile($root)),
        powRelative($root, powLedgerFile($root)),
    ));
}

function powCurrentBranch(string $root): string
{
    $result = powRun(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $root);

    return $result['code'] === 0 ? trim($result['out']) : '';
}

function powSlugFromIssue(string $root, int $issue): string
{
    $result = powGh(['issue', 'view', (string) $issue, '--json', 'title', '--jq', '.title'], $root);

    if ($result['code'] !== 0) {
        powFail('unable to read the title of issue #' . $issue . ' via gh — pass --slug=<kebab-case>');
    }

    return powSlugify(trim($result['out']));
}

/**
 * Profile precedence: an issue labelled `process` is always `full`; otherwise
 * an explicit --profile wins; otherwise the branch prefix decides.
 */
function powResolveProfile(string $root, int $issue, string $branch, mixed $explicit): string
{
    if ($explicit !== null) {
        if (!is_string($explicit) || !isset(POW_PROFILE_CAPS[$explicit])) {
            powFail('invalid --profile (expected full or light)', 2);
        }
        $profile = $explicit;
    } else {
        $prefix = strtolower(explode('/', $branch, 2)[0]);

        if (in_array($prefix, POW_LIGHT_PREFIXES, true)) {
            $profile = 'light';
        } elseif (in_array($prefix, POW_FULL_PREFIXES, true)) {
            $profile = 'full';
        } else {
            powInfo('Unknown branch prefix "' . $prefix . '" — defaulting to the full profile.');
            $profile = 'full';
        }
    }

    if ($profile === 'full' || powGhDisabled()) {
        return $profile;
    }

    $labels = powIssueLabels($root, $issue);

    if ($labels === null) {
        powInfo('Could not read the labels of issue #' . $issue . ' — keeping profile "' . $profile . '".');

        return $profile;
    }

    if (in_array('process', $labels, true)) {
        powInfo('Issue #' . $issue . ' is labelled "process" — forcing the full profile.');

        return 'full';
    }

    return $profile;
}

/**
 * @return list<string>|null null when gh could not answer
 */
function powIssueLabels(string $root, int $issue): ?array
{
    $result = powGh(['issue', 'view', (string) $issue, '--json', 'labels', '--jq', '[.labels[].name] | join("\n")'], $root);

    if ($result['code'] !== 0) {
        return null;
    }

    $labels = array_values(array_filter(array_map('trim', explode("\n", $result['out'])), static fn(string $l): bool => $l !== ''));

    return $labels;
}

/**
 * @param array<string, mixed> $options
 */
function powCommandRound(string $root, array $options): void
{
    $round = $options['round'];
    $role = $options['role'];
    $runId = $options['run'];

    if (!is_int($round) || $round < 1) {
        powFail('--round requires a positive integer', 2);
    }
    if (!is_string($role) || $role === '') {
        powFail('--round requires --role=<coder|review|oracle|auditor|...>', 2);
    }
    if (!is_string($runId) || $runId === '') {
        powFail('--round requires --run=<runId>', 2);
    }

    $manifest = powLoadManifest($root);
    $cap = (int) $manifest['round_cap'];

    if ($round > $cap) {
        powFail(sprintf(
            'round %d exceeds the %s profile cap of %d — there is no round %d. '
            . 'Run the oracle, let it pick one verdict (NARROW|REDO|ACCEPT|HUMAN) '
            . 'and write %s, then record it with --verdict=<VERDICT>.',
            $round,
            (string) $manifest['profile'],
            $cap,
            $cap + 1,
            powRelative($root, powEscalationFile($root)),
        ));
    }

    [$artifactFile, $agent] = powLocateArtifact($root, $runId);
    $meta = powLoadArtifactMeta($root, $runId, $agent);
    $agent = isset($meta['agent']) && is_string($meta['agent']) && $meta['agent'] !== '' ? $meta['agent'] : $agent;
    $model = isset($meta['model']) && is_string($meta['model']) ? $meta['model'] : 'unknown';

    $body = powFrontMatter([
        'round' => $round,
        'role' => $role,
        'agent' => $agent,
        'run_id' => $runId,
        'model' => $model,
        'issue' => (int) $manifest['issue'],
        'branch' => (string) $manifest['branch'],
        'generated_by' => 'bin/pow.php',
    ]) . powRead($artifactFile);

    if ($options['dry-run'] === true) {
        fwrite(STDOUT, $body);
        powInfo(sprintf(
            "\n(dry run: nothing published, nothing recorded; artifact %s, sha256 %s)",
            powRelative($root, $artifactFile),
            hash('sha256', $body),
        ));

        return;
    }

    if (powGhDisabled()) {
        powFail('POW_NO_GH=1 is set — --round can only run with --dry-run');
    }

    $pr = powResolvePrNumber($root);
    $comment = powPublishComment($root, $pr, $body);

    /** @var list<array<string, mixed>> $rounds */
    $rounds = is_array($manifest['rounds']) ? array_values($manifest['rounds']) : [];
    $previous = $rounds === [] ? null : $rounds[count($rounds) - 1];

    $rounds[] = [
        'n' => $round,
        'role' => $role,
        'agent' => $agent,
        'run_id' => $runId,
        'comment_id' => $comment['id'],
        'comment_sha256' => hash('sha256', $body),
        'prev' => is_array($previous) && isset($previous['comment_sha256']) ? $previous['comment_sha256'] : null,
        'created_at' => $comment['created_at'],
    ];

    $manifest['rounds'] = $rounds;
    powSaveManifest($root, $manifest);

    fwrite(STDOUT, sprintf(
        "Recorded round %d (%s / %s, run %s) as comment %d on PR #%d\n",
        $round,
        $role,
        $agent,
        $runId,
        $comment['id'],
        $pr,
    ));
}

/**
 * @return array{0: string, 1: string} artifact path and agent name derived from it
 */
function powLocateArtifact(string $root, string $runId): array
{
    $matches = glob(powArtifactsDir($root) . '/' . $runId . '_*_0_output.md');

    if ($matches === false || $matches === []) {
        powFail('unknown run_id ' . $runId);
    }

    sort($matches);
    $file = $matches[0];
    $agent = (string) preg_replace(
        ['/^' . preg_quote($runId . '_', '/') . '/', '/_0_output\.md$/'],
        '',
        basename($file),
    );

    return [$file, $agent];
}

/**
 * @return array<string, mixed>
 */
function powLoadArtifactMeta(string $root, string $runId, string $agent): array
{
    $file = powArtifactsDir($root) . '/' . $runId . '_' . $agent . '_0_meta.json';

    if (!is_file($file)) {
        powInfo('No meta.json next to the artifact of run ' . $runId . ' — front matter falls back to the file name.');

        return [];
    }

    try {
        $meta = json_decode(powRead($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        powInfo('Malformed ' . basename($file) . ': ' . $e->getMessage());

        return [];
    }

    return is_array($meta) ? $meta : [];
}

/**
 * @param array<string, int|string> $fields
 */
function powFrontMatter(array $fields): string
{
    $lines = ['---'];

    foreach ($fields as $key => $value) {
        $lines[] = $key . ': ' . (is_int($value) ? (string) $value : powYamlString($value));
    }

    $lines[] = '---';
    $lines[] = '';
    $lines[] = '';

    return implode("\n", $lines);
}

function powYamlString(string $value): string
{
    return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
}

function powResolvePrNumber(string $root): int
{
    $result = powGh(['pr', 'view', '--json', 'number', '--jq', '.number'], $root);

    if ($result['code'] !== 0 || trim($result['out']) === '') {
        powFail('no pull request found for the current branch — create the draft PR first (docs/workflow.md step 2.5)');
    }

    return (int) trim($result['out']);
}

/**
 * Publishes the body and reads the created comment back from the API, so the
 * recorded id and timestamp are server-assigned, never taken from the local clock.
 *
 * @return array{id: int, created_at: string}
 */
function powPublishComment(string $root, int $pr, string $body): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'pow-comment-');

    if ($tmp === false) {
        powFail('unable to create a temporary file for the comment body');
    }

    powWrite($tmp, $body);

    try {
        $result = powGh(['pr', 'comment', (string) $pr, '--body-file', $tmp], $root);
    } finally {
        @unlink($tmp);
    }

    if ($result['code'] !== 0) {
        powFail('gh pr comment failed: ' . trim($result['err'] . $result['out']));
    }

    if (preg_match('/#issuecomment-(\d+)/', $result['out'], $matches) !== 1) {
        powFail('unable to determine the comment id from gh output: ' . trim($result['out']));
    }

    $commentId = (int) $matches[1];
    $api = powGh(['api', 'repos/:owner/:repo/issues/comments/' . $commentId, '--jq', '.id, .created_at'], $root);

    if ($api['code'] !== 0) {
        powFail('unable to read comment ' . $commentId . ' back from the API: ' . trim($api['err']));
    }

    $lines = array_values(array_filter(array_map('trim', explode("\n", $api['out'])), static fn(string $l): bool => $l !== ''));

    if (count($lines) < 2) {
        powFail('unexpected API response for comment ' . $commentId);
    }

    return ['id' => (int) $lines[0], 'created_at' => $lines[1]];
}

/**
 * @param array<string, mixed> $options
 */
function powCommandFinding(string $root, array $options): void
{
    $manifest = powLoadManifest($root);
    $id = powRequireString($options, 'id', '--finding');
    $round = $options['round'];
    $loc = powRequireString($options, 'loc', '--finding');
    $desc = powRequireString($options, 'desc', '--finding');
    $severity = powRequireString($options, 'severity', '--finding');
    $status = is_string($options['status']) ? $options['status'] : 'open';

    if (!is_int($round) || $round < 1) {
        powFail('--finding requires --round=<positive integer>', 2);
    }
    if (!in_array($severity, POW_SEVERITIES, true)) {
        powFail('invalid --severity "' . $severity . '" (expected ' . implode('|', POW_SEVERITIES) . ')', 2);
    }
    if (!in_array($status, POW_STATUSES, true)) {
        powFail('invalid --status "' . $status . '" (expected ' . implode('|', POW_STATUSES) . ')', 2);
    }

    if (isset(powLedgerState(powLedgerRows($root))[$id])) {
        powFail('finding ' . $id . ' already exists — use --resolve to change its status');
    }

    powAppendRow($root, [
        'id' => $id,
        'round' => $round,
        'loc' => $loc,
        'desc' => $desc,
        'severity' => $severity,
        'status' => $status,
        'resolution' => '',
    ]);

    powRefreshCounters($root, $manifest);

    fwrite(STDOUT, sprintf("Recorded finding %s (round %d, %s, %s)\n", $id, $round, $severity, $status));
}

/**
 * @param array<string, mixed> $options
 */
function powCommandResolve(string $root, array $options): void
{
    $manifest = powLoadManifest($root);
    $id = powRequireString($options, 'id', '--resolve');
    $round = $options['round'];
    $status = powRequireString($options, 'status', '--resolve');
    $resolution = powRequireString($options, 'resolution', '--resolve');

    if (!is_int($round) || $round < 1) {
        powFail('--resolve requires --round=<positive integer>', 2);
    }
    if (!in_array($status, POW_RESOLVE_STATUSES, true)) {
        powFail('invalid --status "' . $status . '" (expected ' . implode('|', POW_RESOLVE_STATUSES) . ')', 2);
    }

    $state = powLedgerState(powLedgerRows($root));

    if (!isset($state[$id])) {
        powFail('unknown finding ' . $id . ' — record it with --finding first');
    }

    if ($status === 'wontfix' && !str_contains($resolution, 'decisions.md#') && !str_contains($resolution, 'escalation.md')) {
        powFail('a wontfix resolution must cite decisions.md#<anchor> or escalation.md');
    }

    powAppendRow($root, [
        'id' => $id,
        'round' => $round,
        'loc' => $state[$id]['loc'],
        'desc' => $state[$id]['desc'],
        'severity' => $state[$id]['severity'],
        'status' => $status,
        'resolution' => $resolution,
    ]);

    powRefreshCounters($root, $manifest);

    fwrite(STDOUT, sprintf("Resolved finding %s as %s (round %d)\n", $id, $status, $round));
}

/**
 * @param array<string, mixed> $options
 */
function powCommandVerdict(string $root, array $options): void
{
    $manifest = powLoadManifest($root);
    $verdict = $options['verdict'];

    if (!is_string($verdict) || !in_array($verdict, POW_VERDICTS, true)) {
        powFail('invalid --verdict (expected ' . implode('|', POW_VERDICTS) . ')', 2);
    }

    if ($verdict !== 'CLEAN') {
        $escalation = powEscalationFile($root);

        if (!is_file($escalation) || trim(powRead($escalation)) === '') {
            powFail('verdict ' . $verdict . ' requires a non-empty ' . powRelative($root, $escalation));
        }

        if ($verdict === 'ACCEPT') {
            $text = powRead($escalation);
            $unjustified = array_values(array_filter(
                powOpenIds($root),
                static fn(string $id): bool => !str_contains($text, $id),
            ));

            if ($unjustified !== []) {
                powFail('ACCEPT with unjustified findings: ' . implode(', ', $unjustified));
            }
        }
    }

    $manifest['verdict'] = $verdict;
    powSaveManifest($root, $manifest);

    fwrite(STDOUT, 'Recorded verdict ' . $verdict . "\n");
}

/**
 * @param array<string, mixed> $options
 */
function powCommandSet(string $root, array $options): void
{
    $manifest = powLoadManifest($root);
    $changed = [];

    /** @var list<string> $assignments */
    $assignments = $options['set'];

    foreach ($assignments as $assignment) {
        $eq = strpos($assignment, '=');

        if ($eq === false) {
            powFail('--set expects <key>=<value>, got "' . $assignment . '"', 2);
        }

        $key = substr($assignment, 0, $eq);
        $value = substr($assignment, $eq + 1);

        if (!isset(POW_SET_KEYS[$key])) {
            powFail('unknown --set key "' . $key . '" (expected ' . implode('|', array_keys(POW_SET_KEYS)) . ')', 2);
        }

        if (!is_numeric($value)) {
            powFail('--set ' . $key . ' expects a number, got "' . $value . '"', 2);
        }

        $manifest[$key] = POW_SET_KEYS[$key] === 'int' ? (int) $value : (float) $value;
        $changed[] = $key . '=' . $manifest[$key];
    }

    /** @var list<string> $gates */
    $gates = $options['gate'];

    foreach ($gates as $gate) {
        /** @var list<string> $existing */
        $existing = is_array($manifest['gates_added']) ? array_values($manifest['gates_added']) : [];
        $existing[] = $gate;
        $manifest['gates_added'] = $existing;
        $changed[] = 'gates_added+=' . $gate;
    }

    if (is_string($options['abort'])) {
        $parts = explode(':', $options['abort'], 2);

        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            powFail('--abort=<runId>:<reason> requires both a run id and a reason', 2);
        }

        /** @var list<array{run_id: string, reason: string}> $aborted */
        $aborted = is_array($manifest['aborted']) ? array_values($manifest['aborted']) : [];
        $aborted[] = ['run_id' => trim($parts[0]), 'reason' => trim($parts[1])];
        $manifest['aborted'] = $aborted;
        $changed[] = 'aborted+=' . trim($parts[0]);
    }

    powSaveManifest($root, $manifest);
    fwrite(STDOUT, 'Updated manifest: ' . implode(', ', $changed) . "\n");
}

/**
 * @param array<string, mixed> $options
 */
function powCommandFinish(string $root, array $options): void
{
    unset($options);
    $manifest = powLoadManifest($root);

    [$base, $commits, $files] = powGitFacts($root);
    $manifest['commits'] = $commits;
    $manifest['files_changed'] = $files;
    $manifest['findings'] = powFindingCounters(powLedgerRows($root));

    if ($base === null) {
        powInfo('No base ref (origin/master, master, origin/main, main) — commits[] and files_changed[] left empty.');
    }

    $problems = powValidateManifest($root, $manifest);

    if ($problems !== []) {
        powSaveManifest($root, $manifest);
        powFail("manifest is incomplete for the {$manifest['profile']} profile:\n  - " . implode("\n  - ", $problems));
    }

    powSaveManifest($root, $manifest);

    $dest = sprintf('%s/%04d-%s', powDir($root), (int) $manifest['issue'], (string) $manifest['slug']);
    powMkdir($dest);

    $moved = [];

    foreach (['manifest.json', 'findings.md', 'escalation.md'] as $name) {
        $source = powCurrentDir($root) . '/' . $name;

        if (!is_file($source)) {
            continue;
        }

        if (!@rename($source, $dest . '/' . $name)) {
            powFail('unable to move ' . $name . ' into ' . $dest);
        }

        $moved[] = $name;
    }

    foreach (powCurrentEntries($root) as $leftover) {
        powInfo('Left behind in current/ (not part of the durable proof of work): ' . $leftover);
    }

    powResetCurrent($root);

    fwrite(STDOUT, sprintf(
        "Finished proof of work for issue #%d\n  moved:    %s\n  into:     %s\n  findings: %d total, %d in round 1, %d escaped, %d open\n  verdict:  %s\n",
        (int) $manifest['issue'],
        implode(', ', $moved),
        powRelative($root, $dest),
        $manifest['findings']['total'],
        $manifest['findings']['round1'],
        $manifest['findings']['escaped'],
        $manifest['findings']['open'],
        (string) $manifest['verdict'],
    ));
}

/**
 * @return array{0: string|null, 1: list<string>, 2: list<string>}
 */
function powGitFacts(string $root): array
{
    $base = null;

    foreach (['origin/master', 'master', 'origin/main', 'main'] as $candidate) {
        if (powRun(['git', 'rev-parse', '--verify', '--quiet', $candidate], $root)['code'] === 0) {
            $base = $candidate;
            break;
        }
    }

    if ($base === null) {
        return [null, [], []];
    }

    $log = powRun(['git', 'log', '--format=%H', $base . '..HEAD'], $root);
    $diff = powRun(['git', 'diff', '--name-only', $base . '...HEAD'], $root);

    return [$base, powLines($log['out']), powLines($diff['out'])];
}

/**
 * @return list<string>
 */
function powLines(string $text): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn(string $l): bool => $l !== ''));
}

/**
 * @param array<string, mixed> $manifest
 *
 * @return list<string>
 */
function powValidateManifest(string $root, array $manifest): array
{
    $problems = [];
    $profile = (string) $manifest['profile'];
    $minimumRounds = $profile === 'full' ? 2 : 1;
    $rounds = is_array($manifest['rounds']) ? $manifest['rounds'] : [];

    if (count($rounds) < $minimumRounds) {
        $problems[] = sprintf('only %d round(s) recorded, the %s profile needs at least %d', count($rounds), $profile, $minimumRounds);
    }

    if ($manifest['lint_exit'] === null) {
        $problems[] = 'lint_exit is not set (pow.php --set lint_exit=<code>)';
    }

    if ($manifest['test_exit'] === null) {
        $problems[] = 'test_exit is not set (pow.php --set test_exit=<code>)';
    }

    $verdict = $manifest['verdict'];

    if ($verdict === null) {
        $problems[] = 'no verdict recorded (pow.php --verdict=<' . implode('|', POW_VERDICTS) . '>)';
    }

    $escalation = powEscalationFile($root);
    $hasEscalation = is_file($escalation) && trim(powRead($escalation)) !== '';
    $open = powOpenIds($root);

    if ($open !== []) {
        if ($verdict === 'CLEAN') {
            $problems[] = 'verdict CLEAN but the ledger still has open findings: ' . implode(', ', $open);
        }

        if (!$hasEscalation) {
            $problems[] = 'open findings (' . implode(', ', $open) . ') with no escalation.md justifying them';
        }
    }

    return $problems;
}

/**
 * @param array<string, mixed> $options
 */
function powCommandAbort(string $root, array $options): void
{
    $manifest = is_file(powManifestFile($root)) ? powLoadManifest($root) : null;
    $reason = is_string($options['reason']) ? $options['reason'] : 'no reason given';

    if (powCurrentEntries($root) === []) {
        powResetCurrent($root);
        fwrite(STDOUT, "Nothing to abort — current/ is already empty.\n");

        return;
    }

    $dest = powArchiveCurrent($root, $reason);

    fwrite(STDOUT, sprintf(
        "Aborted the proof of work%s\n  archived: %s\n  reason:   %s\n",
        $manifest !== null ? ' for issue #' . (int) $manifest['issue'] : '',
        powRelative($root, $dest),
        $reason,
    ));
}

function powCommandStatus(string $root): void
{
    $manifest = powLoadManifest($root);
    $rounds = is_array($manifest['rounds']) ? array_values($manifest['rounds']) : [];
    $cap = (int) $manifest['round_cap'];
    $counters = powFindingCounters(powLedgerRows($root));
    $highest = 0;

    foreach ($rounds as $round) {
        if (is_array($round) && isset($round['n'])) {
            $highest = max($highest, (int) $round['n']);
        }
    }

    fwrite(STDOUT, sprintf(
        "Proof of work — issue #%d (%s)\n  branch:   %s\n  profile:  %s (cap %d)\n  rounds:   %d entr%s, highest round %d, %d round(s) of headroom\n  findings: %d total, %d in round 1, %d escaped, %d open\n  machine:  lint_exit=%s test_exit=%s coverage=%s\n  gates:    %d added, %d recorded re-roll(s)\n  verdict:  %s\n  escalation.md: %s\n",
        (int) $manifest['issue'],
        (string) $manifest['slug'],
        (string) $manifest['branch'],
        (string) $manifest['profile'],
        $cap,
        count($rounds),
        count($rounds) === 1 ? 'y' : 'ies',
        $highest,
        max(0, $cap - $highest),
        $counters['total'],
        $counters['round1'],
        $counters['escaped'],
        $counters['open'],
        $manifest['lint_exit'] === null ? '-' : (string) $manifest['lint_exit'],
        $manifest['test_exit'] === null ? '-' : (string) $manifest['test_exit'],
        $manifest['coverage'] === null ? '-' : (string) $manifest['coverage'],
        is_array($manifest['gates_added']) ? count($manifest['gates_added']) : 0,
        is_array($manifest['aborted']) ? count($manifest['aborted']) : 0,
        $manifest['verdict'] === null ? '-' : (string) $manifest['verdict'],
        is_file(powEscalationFile($root)) ? 'present' : 'absent',
    ));

    $open = powOpenIds($root);

    if ($open !== []) {
        fwrite(STDOUT, '  open ids: ' . implode(', ', $open) . "\n");
    }
}

/**
 * @param array<string, mixed> $manifest
 */
function powRefreshCounters(string $root, array $manifest): void
{
    $manifest['findings'] = powFindingCounters(powLedgerRows($root));
    powSaveManifest($root, $manifest);
}

/**
 * @param array<string, mixed> $options
 */
function powRequireString(array $options, string $name, string $command): string
{
    $value = $options[$name] ?? null;

    if (!is_string($value) || $value === '') {
        powFail($command . ' requires --' . $name . '=<value>', 2);
    }

    return $value;
}

function powRelative(string $root, string $path): string
{
    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

// --------------------------------------------------------------------------
// CLI
// --------------------------------------------------------------------------

function powUsage(): void
{
    fwrite(STDOUT, <<<TXT
        pow.php — record the proof of work of one issue cycle (docs/workflow.md)

        Usage: php bin/pow.php <command> [options]

        Commands:
          --start --issue=N [--slug=<kebab>] [--branch=<name>] [--profile=full|light]
              Start a cycle. A non-empty current/ is archived to .abandoned/<ts>/,
              never deleted. The profile defaults to the branch prefix
              (fix|feat|refactor|perf|process -> full, docs|chore|ci|test|build ->
              light); an issue labelled "process" is always full.

          --round=N --role=<coder|review|oracle|auditor|...> --run=<runId> [--dry-run]
              Publish the harness artifact of <runId> as a PR comment with injected
              front matter and record the round. Refuses an unknown run id and any
              round beyond the profile cap (full 4, light 2).

          --finding --id=<F-NN> --round=N --loc=<file:line> --desc=<text>
                    --severity=<high|medium|low|nit> [--status=open]
              Append one row to the append-only ledger.

          --resolve --id=<F-NN> --round=N --status=<fixed|gated|wontfix>
                    --resolution=<text>
              Append a NEW row for an existing ID. A wontfix must cite
              decisions.md#<anchor> or escalation.md.

          --verdict=<CLEAN|NARROW|REDO|ACCEPT|HUMAN>
              Record the oracle verdict. Anything but CLEAN requires a non-empty
              escalation.md; ACCEPT additionally requires every open finding to be
              named there.

          --set <key>=<value>       lint_exit | test_exit | coverage (repeatable)
          --gate=<text>             append to gates_added[] (repeatable)
          --abort=<runId>:<reason>  record a re-roll in aborted[]

          --finish
              Recompute commits/files/findings from git and the ledger, validate the
              manifest for its profile, then move manifest.json, findings.md and
              escalation.md into docs/proof_of_work/<NNNN>-<slug>/.

          --abort [--reason=<text>]
              Archive current/ to .abandoned/<ts>/ and reset it.

          --status
              Print a summary of the cycle in progress.

          -h, --help
              Show this help.

        Exit codes: 0 ok, 1 runtime/validation error, 2 usage error.

        TXT);
}

/**
 * @param list<string> $argv
 *
 * @return array<string, mixed>
 */
function powParseArgs(array $argv): array
{
    $options = [
        'start' => false,
        'finding' => false,
        'resolve' => false,
        'finish' => false,
        'dry-run' => false,
        'status-cmd' => false,
        'abort-cmd' => false,
        'issue' => null,
        'slug' => null,
        'branch' => null,
        'profile' => null,
        'round' => null,
        'role' => null,
        'run' => null,
        'id' => null,
        'loc' => null,
        'desc' => null,
        'severity' => null,
        'resolution' => null,
        'verdict' => null,
        'reason' => null,
        'status' => null,
        'abort' => null,
        'set' => [],
        'gate' => [],
    ];

    $argv = array_slice($argv, 1);

    for ($i = 0; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '-h' || $arg === '--help') {
            powUsage();
            exit(0);
        }

        if (!str_starts_with($arg, '--')) {
            powFail('unexpected argument "' . $arg . '" (see --help)', 2);
        }

        $eq = strpos($arg, '=');
        $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
        $value = $eq === false ? null : substr($arg, $eq + 1);

        if (in_array($name, POW_FLAGS, true)) {
            if ($value !== null) {
                powFail('--' . $name . ' does not take a value', 2);
            }
            $options[$name] = true;

            continue;
        }

        if (in_array($name, POW_DUAL_OPTIONS, true)) {
            // Bare form is a command, `=value` form is an option.
            if ($value === null) {
                $options[$name . '-cmd'] = true;
            } else {
                $options[$name] = $value;
            }

            continue;
        }

        if (!in_array($name, POW_VALUE_OPTIONS, true)) {
            powFail('unknown option --' . $name . ' (see --help)', 2);
        }

        if ($value === null) {
            if (!isset($argv[$i + 1])) {
                powFail('option --' . $name . ' requires a value', 2);
            }
            $value = $argv[++$i];
        }

        if ($name === 'set' || $name === 'gate') {
            /** @var list<string> $list */
            $list = $options[$name];
            $list[] = $value;
            $options[$name] = $list;

            continue;
        }

        if ($name === 'issue' || $name === 'round') {
            if (!ctype_digit($value)) {
                powFail('--' . $name . ' expects a non-negative integer, got "' . $value . '"', 2);
            }
            $options[$name] = (int) $value;

            continue;
        }

        $options[$name] = $value;
    }

    return $options;
}

/**
 * @param array<string, mixed> $options
 */
function powSelectCommand(array $options): string
{
    $exclusive = [];

    foreach (['start' => 'start', 'finding' => 'finding', 'resolve' => 'resolve', 'finish' => 'finish', 'status-cmd' => 'status', 'abort-cmd' => 'abort'] as $flag => $command) {
        if ($options[$flag] === true) {
            $exclusive[] = $command;
        }
    }

    if ($options['verdict'] !== null) {
        $exclusive[] = 'verdict';
    }

    if (count($exclusive) > 1) {
        powFail('commands are mutually exclusive, got: --' . implode(' --', $exclusive), 2);
    }

    $hasFacts = $options['set'] !== [] || $options['gate'] !== [] || $options['abort'] !== null;

    if ($exclusive !== []) {
        if ($hasFacts) {
            powFail('--set/--gate/--abort=<runId>:<reason> cannot be combined with --' . $exclusive[0], 2);
        }

        return $exclusive[0];
    }

    if ($options['round'] !== null) {
        return 'round';
    }

    if ($hasFacts) {
        return 'set';
    }

    powUsage();
    exit(2);
}

/**
 * @param array<string, mixed> $options
 */
function powMain(array $options): void
{
    $root = powRoot();

    switch (powSelectCommand($options)) {
        case 'start':
            powCommandStart($root, $options);
            break;
        case 'round':
            powCommandRound($root, $options);
            break;
        case 'finding':
            powCommandFinding($root, $options);
            break;
        case 'resolve':
            powCommandResolve($root, $options);
            break;
        case 'verdict':
            powCommandVerdict($root, $options);
            break;
        case 'set':
            powCommandSet($root, $options);
            break;
        case 'finish':
            powCommandFinish($root, $options);
            break;
        case 'abort':
            powCommandAbort($root, $options);
            break;
        case 'status':
            powCommandStatus($root);
            break;
    }
}

try {
    powMain(powParseArgs($argv));
} catch (Throwable $e) {
    powFail($e->getMessage());
}
