#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Enforcement gate for the proof of work recorded by bin/pow.php.
 *
 * Nothing the orchestrator writes in prose is trusted here: every check reads
 * an externally attested fact — a GitHub comment body and its server-assigned
 * timestamps, a harness artifact on disk, `git show` of an earlier commit, or
 * a recomputed exit code. The goal is not cryptographic impossibility (an
 * orchestrator with a shell can write any file) but that cheating costs more
 * than doing the work and leaves a trace in the diff.
 *
 * Usage: php bin/check-pow.php [options]
 *
 * Options:
 *   --strict           turn every "cannot determine" into a failure (CI)
 *   --verify-reality   recompute lint/test/coverage and compare them with the
 *                      values declared in the manifest (expensive; CI only)
 *   --pr=<n>           validate this pull request instead of looking one up
 *   --branch=<name>    validate this branch instead of the checked-out one
 *   --self-check       re-exec the copy of this script on origin/master, so a
 *                      pull request cannot weaken its own gate
 *   -h, --help         show this help
 *
 * Exit codes:
 *   0  pass, or gracefully skipped (nothing to enforce)
 *   1  gate violation
 *   2  usage error
 *
 * Scope: the gate ENFORCES when the branch matches ^(fix|feat|process)/issue-\d+
 * or when the diff touches a protected path. It SKIPS — one notice, exit 0 —
 * on any other branch, when no pull request exists for the branch, and when
 * `gh` is missing, unauthenticated or offline. `--strict` turns those skips
 * into failures, because in CI "cannot determine" is indistinguishable from
 * "hidden".
 *
 * Severity of a finding decides who fails on it:
 *   violation     evidence of tampering — always exit 1
 *   incomplete    the cycle is not finished yet — exit 1 only with --strict
 *   undetermined  a fact could not be read — exit 1 only with --strict
 *   notice        informational, never fails
 * That split is what keeps `composer lint` usable in the middle of a cycle:
 * the POW is legitimately incomplete until step 11.5, but a tampered comment
 * or a leaked scratch buffer is never legitimate.
 *
 * Environment:
 *   CHECK_POW_ROOT         repository root to operate on (default: parent of bin/)
 *   CHECK_POW_SKIP=1       exit 0 immediately; set automatically for the
 *                          subprocesses spawned by --verify-reality so the
 *                          `composer lint` it runs does not recurse into this
 *                          script again
 *   CHECK_POW_GH_FIXTURE   path to a JSON file replacing every `gh` call
 *                          (test hook, see tests/ProofOfWork/CheckPowScriptTest.php)
 *   CHECK_POW_LINT_CMD     shell command used by --verify-reality instead of
 *                          `composer lint`
 *   CHECK_POW_TEST_CMD     shell command used by --verify-reality instead of
 *                          `composer test` (CI passes `composer test:coverage`
 *                          so one run yields both the exit code and the clover
 *                          file the coverage comparison needs)
 */

/** Branches the gate enforces on. Capture group 2 is the issue number. */
const CPOW_ISSUE_BRANCH = '#^(fix|feat|process)/issue-(\d+)#';

/** Minimum recorded rounds per profile — mirrors bin/pow.php's --finish validation. */
const CPOW_MIN_ROUNDS = ['full' => 2, 'light' => 1];

/** Coverage is a float derived from a clover file; compare with a tolerance. */
const CPOW_COVERAGE_TOLERANCE = 0.05;

/** An approval only counts as "maintainer" from one of these associations. */
const CPOW_MAINTAINER_ASSOCIATIONS = ['OWNER', 'MEMBER', 'COLLABORATOR'];

/** Agents whose runs must be accounted for in the manifest (round or aborted). */
const CPOW_ROUND_AGENT = '#^(coder|review)#';

/** Base ref candidates, most specific first. */
const CPOW_BASE_REFS = ['origin/master', 'master', 'origin/main', 'main'];

/** Files that may only change through a `process/` branch with an approval. */
const CPOW_PROTECTED_FILES = ['bin/pow.php', 'bin/check-pow.php'];

/** Path prefixes under the same rule. */
const CPOW_PROTECTED_PREFIXES = ['.github/workflows/'];

const CPOW_FLAGS = ['strict', 'verify-reality', 'self-check', 'help'];

const CPOW_VALUE_OPTIONS = ['pr', 'branch'];

// --------------------------------------------------------------------------
// Small helpers
// --------------------------------------------------------------------------

function cpowFail(string $message, int $code = 2): never
{
    fwrite(STDERR, 'check-pow: ' . $message . "\n");
    exit($code);
}

function cpowNotice(string $message): void
{
    fwrite(STDERR, 'check-pow: ' . $message . "\n");
}

/**
 * Runs a command and returns its exit code and streams.
 *
 * @param list<string>|string $cmd array form runs without a shell; the string
 *                                 form (only used by the CHECK_POW_*_CMD env
 *                                 overrides) goes through /bin/sh
 *
 * @return array{code: int, out: string, err: string}
 */
function cpowRun(array|string $cmd, ?string $cwd = null, bool $markChild = false): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $env = null;

    if ($markChild) {
        /** @var array<string, string> $env */
        $env = getenv();
        $env['CHECK_POW_SKIP'] = '1';
    }

    $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);

    if (!is_resource($process)) {
        return ['code' => 127, 'out' => '', 'err' => 'unable to start: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd)];
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
}

/**
 * @return list<string>
 */
function cpowLines(string $text): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $text)),
        static fn(string $line): bool => $line !== '',
    ));
}

function cpowRoot(): string
{
    $configured = (string) getenv('CHECK_POW_ROOT');

    if ($configured === '') {
        $configured = (string) getenv('POW_ROOT');
    }

    $root = $configured !== '' ? $configured : dirname(__DIR__);
    $real = realpath($root);

    if ($real === false || !is_dir($real)) {
        cpowFail('CHECK_POW_ROOT is not a directory: ' . $root);
    }

    return $real;
}

/**
 * @return array<string, mixed>|null
 */
function cpowDecode(string $json): ?array
{
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

// --------------------------------------------------------------------------
// Report
// --------------------------------------------------------------------------

/**
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowAdd(array &$report, string $level, string $id, string $message): void
{
    $report[] = ['level' => $level, 'id' => $id, 'message' => $message];
}

/**
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowHasLevel(array $report, string $level): bool
{
    foreach ($report as $entry) {
        if ($entry['level'] === $level) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowPrintReport(array $report, bool $strict): int
{
    $labels = [
        'violation' => 'FAIL',
        'incomplete' => $strict ? 'FAIL' : 'PENDING',
        'undetermined' => $strict ? 'FAIL' : 'UNKNOWN',
        'notice' => 'NOTE',
    ];

    foreach ($report as $entry) {
        fwrite(STDERR, sprintf(
            "check-pow: %-7s [%s] %s\n",
            $labels[$entry['level']] ?? 'NOTE',
            $entry['id'],
            $entry['message'],
        ));
    }

    $failed = cpowHasLevel($report, 'violation')
        || ($strict && (cpowHasLevel($report, 'incomplete') || cpowHasLevel($report, 'undetermined')));

    fwrite(STDOUT, sprintf(
        "check-pow: %s (%d finding(s), %s mode)\n",
        $failed ? 'FAILED' : 'ok',
        count($report),
        $strict ? 'strict' : 'advisory',
    ));

    return $failed ? 1 : 0;
}

// --------------------------------------------------------------------------
// git
// --------------------------------------------------------------------------

function cpowCurrentBranch(string $root): string
{
    $result = cpowRun(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $root);

    return $result['code'] === 0 ? trim($result['out']) : '';
}

function cpowBaseRef(string $root): ?string
{
    foreach (CPOW_BASE_REFS as $candidate) {
        if (cpowRun(['git', 'rev-parse', '--verify', '--quiet', $candidate], $root)['code'] === 0) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return list<string>
 */
function cpowChangedFiles(string $root, ?string $base): array
{
    if ($base === null) {
        return [];
    }

    return cpowLines(cpowRun(['git', 'diff', '--name-only', $base . '...HEAD'], $root)['out']);
}

function cpowShow(string $root, string $ref, string $path): ?string
{
    $result = cpowRun(['git', 'show', $ref . ':' . $path], $root);

    return $result['code'] === 0 ? $result['out'] : null;
}

// --------------------------------------------------------------------------
// gh (or its fixture)
// --------------------------------------------------------------------------

/**
 * @return array<string, mixed>|null
 */
function cpowFixture(): ?array
{
    static $fixture = false;

    if ($fixture !== false) {
        /** @var array<string, mixed>|null $fixture */
        return $fixture;
    }

    $path = (string) getenv('CHECK_POW_GH_FIXTURE');

    if ($path === '') {
        return $fixture = null;
    }

    $contents = @file_get_contents($path);

    if ($contents === false) {
        cpowFail('CHECK_POW_GH_FIXTURE is not readable: ' . $path);
    }

    $decoded = cpowDecode($contents);

    if ($decoded === null) {
        cpowFail('CHECK_POW_GH_FIXTURE is not valid JSON: ' . $path);
    }

    return $fixture = $decoded;
}

function cpowGhAvailable(string $root): bool
{
    static $available = null;

    if (cpowFixture() !== null) {
        return true;
    }

    if ($available === null) {
        $available = cpowRun(['gh', '--version'], $root)['code'] === 0
            && cpowRun(['gh', 'auth', 'status'], $root)['code'] === 0;
    }

    return $available;
}

/**
 * @param list<string> $args
 *
 * @return array{code: int, out: string, err: string}
 */
function cpowGh(array $args, string $root): array
{
    return cpowRun(['gh', ...$args], $root);
}

/**
 * Resolves the pull request under test.
 *
 * @return array{status: 'ok'|'none'|'unavailable', pr: array<string, mixed>|null, reason: string}
 */
function cpowResolvePr(string $root, ?int $number, string $branch): array
{
    $fixture = cpowFixture();

    if ($fixture !== null) {
        $pr = $fixture['pr'] ?? null;

        return is_array($pr)
            ? ['status' => 'ok', 'pr' => $pr, 'reason' => '']
            : ['status' => 'none', 'pr' => null, 'reason' => 'the fixture declares no pull request'];
    }

    if (!cpowGhAvailable($root)) {
        return ['status' => 'unavailable', 'pr' => null, 'reason' => 'gh is missing, unauthenticated or offline'];
    }

    if ($number === null) {
        $list = cpowGh(['pr', 'list', '--head', $branch, '--state', 'all', '--limit', '1', '--json', 'number'], $root);

        if ($list['code'] !== 0) {
            return ['status' => 'unavailable', 'pr' => null, 'reason' => 'gh pr list failed: ' . trim($list['err'])];
        }

        $decoded = json_decode(trim($list['out']) === '' ? '[]' : $list['out'], true);

        if (!is_array($decoded) || $decoded === []) {
            return ['status' => 'none', 'pr' => null, 'reason' => 'no pull request for branch ' . $branch];
        }

        $first = reset($decoded);
        $number = is_array($first) && isset($first['number']) ? (int) $first['number'] : null;

        if ($number === null || $number <= 0) {
            return ['status' => 'none', 'pr' => null, 'reason' => 'no pull request for branch ' . $branch];
        }
    }

    $fields = 'number,state,isDraft,headRefName,labels,closingIssuesReferences,reviews';
    $view = cpowGh(['pr', 'view', (string) $number, '--json', $fields], $root);

    if ($view['code'] !== 0) {
        return ['status' => 'unavailable', 'pr' => null, 'reason' => 'gh pr view failed: ' . trim($view['err'])];
    }

    $pr = cpowDecode($view['out']);

    if ($pr === null) {
        return ['status' => 'unavailable', 'pr' => null, 'reason' => 'gh pr view returned no JSON object'];
    }

    return ['status' => 'ok', 'pr' => $pr, 'reason' => ''];
}

/**
 * @return array{status: 'ok'|'missing'|'unavailable', comment: array<string, mixed>|null}
 */
function cpowFetchComment(string $root, int $id): array
{
    $fixture = cpowFixture();

    if ($fixture !== null) {
        $comments = $fixture['comments'] ?? [];
        $comment = is_array($comments) ? ($comments[(string) $id] ?? null) : null;

        return is_array($comment)
            ? ['status' => 'ok', 'comment' => $comment]
            : ['status' => 'missing', 'comment' => null];
    }

    if (!cpowGhAvailable($root)) {
        return ['status' => 'unavailable', 'comment' => null];
    }

    $result = cpowGh(['api', 'repos/:owner/:repo/issues/comments/' . $id], $root);

    if ($result['code'] !== 0) {
        // gh exits non-zero both for a 404 and for a transport error; a 404 is
        // the interesting case (the comment was deleted), everything else must
        // not be reported as tampering.
        return str_contains($result['err'], 'Not Found') || str_contains($result['err'], '404')
            ? ['status' => 'missing', 'comment' => null]
            : ['status' => 'unavailable', 'comment' => null];
    }

    $comment = cpowDecode($result['out']);

    return $comment === null
        ? ['status' => 'unavailable', 'comment' => null]
        : ['status' => 'ok', 'comment' => $comment];
}

/**
 * @param array<string, mixed> $pr
 *
 * @return list<string>
 */
function cpowLabels(array $pr): array
{
    $labels = $pr['labels'] ?? [];
    $names = [];

    if (is_array($labels)) {
        foreach ($labels as $label) {
            if (is_array($label) && isset($label['name']) && is_string($label['name'])) {
                $names[] = $label['name'];
            } elseif (is_string($label)) {
                $names[] = $label;
            }
        }
    }

    return $names;
}

/**
 * @param array<string, mixed> $pr
 *
 * @return list<int>
 */
function cpowClosingIssues(array $pr): array
{
    $refs = $pr['closingIssuesReferences'] ?? [];
    $numbers = [];

    if (is_array($refs)) {
        foreach ($refs as $ref) {
            if (is_array($ref) && isset($ref['number'])) {
                $numbers[] = (int) $ref['number'];
            } elseif (is_int($ref)) {
                $numbers[] = $ref;
            }
        }
    }

    return $numbers;
}

/**
 * An approval "on record" is a submitted APPROVED review by someone with write
 * access. A comment saying "looks good" is not an approval.
 *
 * @param array<string, mixed> $pr
 */
function cpowMaintainerApproval(array $pr): ?string
{
    $reviews = $pr['reviews'] ?? [];

    if (!is_array($reviews)) {
        return null;
    }

    foreach ($reviews as $review) {
        if (!is_array($review) || ($review['state'] ?? null) !== 'APPROVED') {
            continue;
        }

        $association = is_string($review['authorAssociation'] ?? null) ? $review['authorAssociation'] : null;

        if ($association !== null && !in_array($association, CPOW_MAINTAINER_ASSOCIATIONS, true)) {
            continue;
        }

        $author = $review['author'] ?? null;
        $login = is_array($author) && is_string($author['login'] ?? null) ? $author['login'] : 'unknown';

        return $login;
    }

    return null;
}

// --------------------------------------------------------------------------
// Proof of work on disk
// --------------------------------------------------------------------------

function cpowDir(string $root): string
{
    return $root . '/docs/proof_of_work';
}

/**
 * Finds `docs/proof_of_work/<NNNN>-<slug>/` for an issue number.
 */
function cpowIssueDir(string $root, int $issue): ?string
{
    $matches = glob(cpowDir($root) . '/[0-9]*-*', GLOB_ONLYDIR);

    if ($matches === false) {
        return null;
    }

    foreach ($matches as $candidate) {
        if (preg_match('/^0*(\d+)-/', basename($candidate), $found) === 1 && (int) $found[1] === $issue) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function cpowManifest(string $dir): ?array
{
    $file = $dir . '/manifest.json';

    if (!is_file($file)) {
        return null;
    }

    $contents = @file_get_contents($file);

    return $contents === false ? null : cpowDecode($contents);
}

/**
 * Effective (last-row) status per finding ID — the same rule bin/pow.php uses.
 *
 * @return array<string, string>
 */
function cpowLedgerState(string $ledger): array
{
    $state = [];

    foreach (explode("\n", $ledger) as $line) {
        $line = trim($line);

        if (!str_starts_with($line, '|')) {
            continue;
        }

        $cells = preg_split('/(?<!\\\\)\|/', $line);

        if ($cells === false || count($cells) < 9) {
            continue;
        }

        $cells = array_map('trim', array_slice($cells, 1, 7));

        if ($cells[0] === 'ID' || $cells[0] === '' || str_starts_with($cells[0], '---')) {
            continue;
        }

        $state[$cells[0]] = $cells[5];
    }

    return $state;
}

/**
 * @return list<string>
 */
function cpowOpenIds(string $ledger): array
{
    $open = [];

    foreach (cpowLedgerState($ledger) as $id => $status) {
        if ($status === 'open') {
            $open[] = (string) $id;
        }
    }

    natsort($open);

    return array_values($open);
}

/**
 * Total line coverage of a clover file, computed exactly like
 * bin/check-coverage.php so the two numbers are comparable.
 */
function cpowCoverageOf(string $cloverFile): ?float
{
    if (!is_readable($cloverFile)) {
        return null;
    }

    $xml = @simplexml_load_file($cloverFile);

    if ($xml === false) {
        return null;
    }

    $metrics = $xml->xpath('//metrics');

    if ($metrics === false || $metrics === []) {
        return null;
    }

    $total = 0;
    $covered = 0;

    foreach ($metrics as $metric) {
        $total += (int) ((string) ($metric['statements'] ?? '0'));
        $covered += (int) ((string) ($metric['coveredstatements'] ?? '0'));
    }

    return $total === 0 ? null : ($covered / $total) * 100.0;
}

// --------------------------------------------------------------------------
// Checks
// --------------------------------------------------------------------------

/**
 * POW-10 — protected paths.
 *
 * A diff touching bin/pow.php, bin/check-pow.php, .github/workflows/* or the
 * `scripts` block of composer.json rewrites the gate itself, so it needs the
 * `process/` branch prefix plus a maintainer approval.
 *
 * @param list<string>                                          $files
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckProtectedPaths(string $root, ?string $base, array $files, string $branch, array &$report): bool
{
    $touched = [];

    foreach ($files as $file) {
        if (in_array($file, CPOW_PROTECTED_FILES, true)) {
            $touched[] = $file;

            continue;
        }

        foreach (CPOW_PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($file, $prefix)) {
                $touched[] = $file;

                continue 2;
            }
        }

        if ($file === 'composer.json' && cpowComposerScriptsChanged($root, $base, $report)) {
            $touched[] = 'composer.json (scripts)';
        }
    }

    if ($touched === []) {
        return false;
    }

    if (!str_starts_with($branch, 'process/')) {
        cpowAdd($report, 'violation', 'POW-10', sprintf(
            'the diff touches protected path(s) %s but the branch is "%s" — gate and tooling changes '
            . 'require a process/ branch (bin/gh-branch <issue> process)',
            implode(', ', $touched),
            $branch,
        ));
    } else {
        cpowAdd($report, 'notice', 'POW-10', 'protected path(s) touched from a process/ branch: ' . implode(', ', $touched));
    }

    return true;
}

/**
 * True when composer.json's `scripts` block differs from the base — an
 * unrelated composer.json edit must not trip the protected-path rule.
 *
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowComposerScriptsChanged(string $root, ?string $base, array &$report): bool
{
    $current = @file_get_contents($root . '/composer.json');
    $currentScripts = $current === false ? null : (cpowDecode($current)['scripts'] ?? null);

    if ($base === null) {
        cpowAdd($report, 'notice', 'POW-10', 'no base ref — treating any composer.json change as a scripts change');

        return true;
    }

    $previous = cpowShow($root, $base, 'composer.json');

    if ($previous === null) {
        return true;
    }

    $previousScripts = cpowDecode($previous)['scripts'] ?? null;

    return $previousScripts !== $currentScripts;
}

/**
 * POW-04 — the gitignored scratch buffer must never reach the diff.
 *
 * @param list<string>                                          $files
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckScratchBuffer(array $files, array &$report): void
{
    $leaked = [];

    foreach ($files as $file) {
        if (str_starts_with($file, 'docs/proof_of_work/current/') && basename($file) !== '.gitkeep') {
            $leaked[] = $file;
        }
    }

    if ($leaked !== []) {
        cpowAdd($report, 'violation', 'POW-04', sprintf(
            'the scratch buffer leaked into the diff: %s — only docs/proof_of_work/current/.gitkeep may be committed',
            implode(', ', $leaked),
        ));
    }
}

/**
 * POW-03 — the manifest must be complete for its profile.
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckManifest(string $dir, array $manifest, array &$report): void
{
    $profile = is_string($manifest['profile'] ?? null) ? $manifest['profile'] : 'full';
    $minimum = CPOW_MIN_ROUNDS[$profile] ?? 2;
    $rounds = is_array($manifest['rounds'] ?? null) ? $manifest['rounds'] : [];

    if (count($rounds) < $minimum) {
        cpowAdd($report, 'incomplete', 'POW-03', sprintf(
            'manifest incomplete: %d round(s) recorded, the %s profile needs at least %d',
            count($rounds),
            $profile,
            $minimum,
        ));
    }

    $ledgerFile = $dir . '/findings.md';
    $ledger = is_file($ledgerFile) ? (string) file_get_contents($ledgerFile) : '';

    if ($ledger === '') {
        cpowAdd($report, 'incomplete', 'POW-03', 'manifest incomplete: findings.md is missing or empty');
    }

    $open = cpowOpenIds($ledger);
    $verdict = is_string($manifest['verdict'] ?? null) ? $manifest['verdict'] : null;
    $escalation = is_file($dir . '/escalation.md') && trim((string) file_get_contents($dir . '/escalation.md')) !== '';

    if ($open !== []) {
        cpowAdd($report, 'incomplete', 'POW-03', sprintf(
            'manifest incomplete: the ledger still has open finding(s) %s at finish time',
            implode(', ', $open),
        ));
    }

    if ($verdict === null) {
        cpowAdd($report, 'incomplete', 'POW-03', 'manifest incomplete: no verdict recorded and no escalation.md');

        return;
    }

    if ($verdict !== 'CLEAN' && !$escalation) {
        cpowAdd($report, 'incomplete', 'POW-03', sprintf(
            'manifest incomplete: verdict %s requires a non-empty escalation.md',
            $verdict,
        ));
    }
}

/**
 * POW-05 — the comment chain.
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckCommentChain(string $root, array $manifest, array &$report): void
{
    $rounds = is_array($manifest['rounds'] ?? null) ? array_values($manifest['rounds']) : [];

    if ($rounds === []) {
        return;
    }

    $previousSha = null;
    $previousTime = null;

    foreach ($rounds as $index => $round) {
        if (!is_array($round)) {
            cpowAdd($report, 'violation', 'POW-05', 'rounds[' . $index . '] is not an object');

            continue;
        }

        $label = sprintf('round %s (%s)', (string) ($round['n'] ?? '?'), (string) ($round['role'] ?? '?'));
        $id = (int) ($round['comment_id'] ?? 0);
        $declaredSha = is_string($round['comment_sha256'] ?? null) ? $round['comment_sha256'] : '';
        $prev = $round['prev'] ?? null;
        $createdAt = is_string($round['created_at'] ?? null) ? $round['created_at'] : '';

        // prev chain — a round removed from the manifest breaks it.
        if ($index === 0) {
            if ($prev !== null) {
                cpowAdd($report, 'violation', 'POW-05', $label . ': the first round must have prev=null, got ' . var_export($prev, true));
            }
        } elseif ($prev !== $previousSha) {
            cpowAdd($report, 'violation', 'POW-05', sprintf(
                '%s: prev chain broken — expected %s, got %s (a deleted round breaks the chain)',
                $label,
                (string) $previousSha,
                is_string($prev) ? $prev : var_export($prev, true),
            ));
        }

        $previousSha = $declaredSha;

        // created_at is server-assigned, so it is the one timestamp that cannot
        // be backdated; it must increase strictly from round to round.
        $time = $createdAt === '' ? false : strtotime($createdAt);

        if ($time === false) {
            cpowAdd($report, 'violation', 'POW-05', $label . ': created_at is missing or unparsable');
        } else {
            if ($previousTime !== null && $time <= $previousTime) {
                cpowAdd($report, 'violation', 'POW-05', sprintf(
                    '%s: created_at %s is not after the previous round — the proof of work was backfilled',
                    $label,
                    $createdAt,
                ));
            }

            $previousTime = $time;
        }

        if ($id <= 0) {
            cpowAdd($report, 'violation', 'POW-05', $label . ': comment_id is missing');

            continue;
        }

        $fetched = cpowFetchComment($root, $id);

        if ($fetched['status'] === 'unavailable') {
            cpowAdd($report, 'undetermined', 'POW-05', $label . ': comment ' . $id . ' could not be read back from GitHub');

            continue;
        }

        if ($fetched['status'] === 'missing') {
            cpowAdd($report, 'violation', 'POW-05', $label . ': comment ' . $id . ' no longer exists — a round comment was deleted');

            continue;
        }

        $comment = $fetched['comment'] ?? [];
        $body = is_string($comment['body'] ?? null) ? $comment['body'] : '';
        $remoteCreated = is_string($comment['created_at'] ?? null) ? $comment['created_at'] : '';
        $remoteUpdated = is_string($comment['updated_at'] ?? null) ? $comment['updated_at'] : '';

        // GitHub may hand the body back with CRLF line endings; normalising is
        // the only tolerance allowed — every other byte must match.
        $sha = hash('sha256', $body);
        $shaNormalised = hash('sha256', str_replace("\r\n", "\n", $body));

        if ($declaredSha !== $sha && $declaredSha !== $shaNormalised) {
            cpowAdd($report, 'violation', 'POW-05', sprintf(
                '%s: comment %d body sha256 %s does not match the recorded %s — the comment was tampered with',
                $label,
                $id,
                $sha,
                $declaredSha === '' ? '(none)' : $declaredSha,
            ));
        }

        if ($remoteUpdated !== '' && $remoteCreated !== '' && $remoteUpdated !== $remoteCreated) {
            cpowAdd($report, 'violation', 'POW-05', sprintf(
                '%s: comment %d was edited after publication (created_at %s, updated_at %s)',
                $label,
                $id,
                $remoteCreated,
                $remoteUpdated,
            ));
        }

        if ($remoteCreated !== '' && $createdAt !== '' && $remoteCreated !== $createdAt) {
            cpowAdd($report, 'violation', 'POW-05', sprintf(
                '%s: comment %d created_at %s does not match the manifest value %s',
                $label,
                $id,
                $remoteCreated,
                $createdAt,
            ));
        }
    }
}

/**
 * POW-06 — the committed ledger is append-only across commits.
 *
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckLedgerHistory(string $root, ?string $base, string $relativeDir, array &$report): void
{
    if ($base === null) {
        cpowAdd($report, 'undetermined', 'POW-06', 'no base ref — the ledger history could not be walked');

        return;
    }

    $path = $relativeDir . '/findings.md';
    $log = cpowRun(['git', 'log', '--format=%H', '--reverse', $base . '..HEAD', '--', $path], $root);
    $commits = cpowLines($log['out']);

    if (count($commits) < 2) {
        return;
    }

    for ($i = 1; $i < count($commits); $i++) {
        $previous = cpowShow($root, $commits[$i - 1], $path);
        $current = cpowShow($root, $commits[$i], $path);

        if ($previous === null || $current === null) {
            cpowAdd($report, 'undetermined', 'POW-06', 'unable to read ' . $path . ' at ' . substr($commits[$i], 0, 8));

            continue;
        }

        if (!str_starts_with($current, $previous)) {
            cpowAdd($report, 'violation', 'POW-06', sprintf(
                'the ledger is not append-only: %s at %s is not a byte prefix of the version at %s — a finding was edited or deleted',
                $path,
                substr($commits[$i - 1], 0, 8),
                substr($commits[$i], 0, 8),
            ));
        }
    }
}

/**
 * POW-07 — no silent re-rolls.
 *
 * Every coder/review run whose artifact was written inside the branch time
 * window must be accounted for: as a round, or in aborted[] with a reason.
 * Otherwise the loop could be re-run until it says "clean".
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckReRolls(string $root, ?string $base, array $manifest, array &$report): void
{
    $artifacts = $root . '/.pi-subagents/artifacts';

    if (!is_dir($artifacts)) {
        cpowAdd($report, 'notice', 'POW-07', 'no .pi-subagents/artifacts/ here (CI) — the re-roll check is skipped');

        return;
    }

    if ($base === null) {
        cpowAdd($report, 'undetermined', 'POW-07', 'no base ref — the branch time window is unknown');

        return;
    }

    $stamps = cpowLines(cpowRun(['git', 'log', '--format=%ct', '--reverse', $base . '..HEAD'], $root)['out']);

    if ($stamps === []) {
        cpowAdd($report, 'notice', 'POW-07', 'no commits on the branch yet — the re-roll check is skipped');

        return;
    }

    $windowStart = (int) $stamps[0];
    $known = [];

    foreach ((is_array($manifest['rounds'] ?? null) ? $manifest['rounds'] : []) as $round) {
        if (is_array($round) && is_string($round['run_id'] ?? null)) {
            $known[$round['run_id']] = 'round';
        }
    }

    foreach ((is_array($manifest['aborted'] ?? null) ? $manifest['aborted'] : []) as $aborted) {
        if (!is_array($aborted) || !is_string($aborted['run_id'] ?? null)) {
            continue;
        }

        if (trim((string) ($aborted['reason'] ?? '')) === '') {
            cpowAdd($report, 'violation', 'POW-07', 'aborted run ' . $aborted['run_id'] . ' has no reason');

            continue;
        }

        $known[$aborted['run_id']] = 'aborted';
    }

    $unaccounted = [];

    foreach ((array) glob($artifacts . '/*_meta.json') as $file) {
        $contents = @file_get_contents((string) $file);
        $meta = $contents === false ? null : cpowDecode($contents);

        if ($meta === null) {
            continue;
        }

        $name = basename((string) $file);
        $runId = is_string($meta['runId'] ?? null) ? $meta['runId'] : explode('_', $name)[0];
        $agent = is_string($meta['agent'] ?? null) ? $meta['agent'] : (explode('_', $name)[1] ?? '');
        $timestamp = is_int($meta['timestamp'] ?? null) ? intdiv($meta['timestamp'], 1000) : null;

        if ($timestamp === null || preg_match(CPOW_ROUND_AGENT, $agent) !== 1) {
            continue;
        }

        if ($timestamp < $windowStart || $timestamp > time()) {
            continue;
        }

        if (!isset($known[$runId])) {
            $unaccounted[] = $runId . ' (' . $agent . ')';
        }
    }

    if ($unaccounted !== []) {
        sort($unaccounted);
        cpowAdd($report, 'violation', 'POW-07', sprintf(
            'silent re-roll: %s ran inside the branch window but appear(s) neither in rounds[] nor in aborted[] — '
            . 'record it with `php bin/pow.php --abort=<runId>:<reason>`',
            implode(', ', $unaccounted),
        ));
    }
}

/**
 * POW-08 — manifest vs reality.
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckReality(string $root, array $manifest, array &$report): void
{
    foreach ([['lint', 'lint_exit', ['composer', 'lint']], ['test', 'test_exit', ['composer', 'test']]] as [$name, $key, $default]) {
        $declared = $manifest[$key] ?? null;

        if (!is_int($declared)) {
            cpowAdd($report, 'incomplete', 'POW-08', $key . ' is not declared in the manifest');

            continue;
        }

        $override = (string) getenv('CHECK_POW_' . strtoupper($name) . '_CMD');
        $actual = cpowRun($override !== '' ? $override : $default, $root, true)['code'];

        if ($actual !== $declared) {
            cpowAdd($report, 'violation', 'POW-08', sprintf(
                'manifest falsified: %s is declared as %d but recomputing %s exited %d',
                $key,
                $declared,
                $name,
                $actual,
            ));
        } else {
            cpowAdd($report, 'notice', 'POW-08', $key . ' matches the recomputed value (' . $actual . ')');
        }
    }

    $declaredCoverage = $manifest['coverage'] ?? null;
    $clover = $root . '/var/coverage.xml';
    $actualCoverage = cpowCoverageOf($clover);

    if ($actualCoverage === null) {
        cpowAdd($report, 'notice', 'POW-08', 'no readable var/coverage.xml — the coverage comparison is skipped');

        return;
    }

    if (!is_int($declaredCoverage) && !is_float($declaredCoverage)) {
        cpowAdd($report, 'incomplete', 'POW-08', 'coverage is not declared in the manifest');

        return;
    }

    if (abs((float) $declaredCoverage - $actualCoverage) > CPOW_COVERAGE_TOLERANCE) {
        cpowAdd($report, 'violation', 'POW-08', sprintf(
            'manifest falsified: coverage is declared as %.2f%% but var/coverage.xml reports %.2f%% (tolerance %.2fpp)',
            (float) $declaredCoverage,
            $actualCoverage,
            CPOW_COVERAGE_TOLERANCE,
        ));
    } else {
        cpowAdd($report, 'notice', 'POW-08', sprintf('coverage matches var/coverage.xml (%.2f%%)', $actualCoverage));
    }
}

/**
 * POW-09 — the `no-pow` escape hatch.
 *
 * Returns true when the hatch applies and checks 1–8 must be skipped.
 *
 * @param array<string, mixed>                                  $pr
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckBypass(string $root, array $pr, bool $strict, array &$report): bool
{
    if (!in_array('no-pow', cpowLabels($pr), true)) {
        return false;
    }

    $number = (int) ($pr['number'] ?? 0);
    $issues = cpowClosingIssues($pr);

    fwrite(STDERR, str_repeat('!', 72) . "\n");
    fwrite(STDERR, "check-pow: BYPASS — PR #{$number} carries the `no-pow` label, checks 1-8 are skipped.\n");
    fwrite(STDERR, "check-pow: a bypass is a documented exception, never a silent one.\n");
    fwrite(STDERR, str_repeat('!', 72) . "\n");

    $approver = cpowMaintainerApproval($pr);

    if ($approver === null) {
        cpowAdd($report, 'violation', 'POW-09', 'the `no-pow` label requires a maintainer approval on the pull request — there is none on record');
    } else {
        cpowAdd($report, 'notice', 'POW-09', 'bypass approved by ' . $approver);
    }

    $changelog = $root . '/docs/process-changelog.md';

    if (!is_file($changelog)) {
        cpowAdd($report, $strict ? 'undetermined' : 'notice', 'POW-09', 'docs/process-changelog.md does not exist — the bypass cannot be recorded');

        return true;
    }

    $contents = (string) file_get_contents($changelog);
    $needles = ['#' . $number];

    foreach ($issues as $issue) {
        $needles[] = '#' . $issue;
    }

    foreach ($needles as $needle) {
        if (str_contains($contents, $needle)) {
            cpowAdd($report, 'notice', 'POW-09', 'bypass recorded in docs/process-changelog.md (' . $needle . ')');

            return true;
        }
    }

    cpowAdd($report, 'violation', 'POW-09', sprintf(
        'the `no-pow` bypass is not recorded in docs/process-changelog.md — add an entry naming %s',
        implode(' or ', $needles),
    ));

    return true;
}

// --------------------------------------------------------------------------
// Bootstrap: run the master copy of this script
// --------------------------------------------------------------------------

/**
 * Re-execs `origin/master:bin/check-pow.php`, so a pull request cannot weaken
 * the gate that judges it. Falls back — loudly — to the in-tree copy when
 * master does not carry the script yet (the pull request introducing it).
 *
 * @param list<string> $argv
 */
function cpowBootstrap(string $root, array $argv): void
{
    if ((string) getenv('CHECK_POW_BOOTSTRAPPED') === '1') {
        return;
    }

    foreach (['origin/master', 'master'] as $ref) {
        $source = cpowShow($root, $ref, 'bin/check-pow.php');

        if ($source === null || trim($source) === '') {
            continue;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'check-pow-');

        if ($tmp === false || file_put_contents($tmp, $source) === false) {
            cpowNotice('unable to materialise ' . $ref . ':bin/check-pow.php — using the in-tree copy');

            return;
        }

        cpowNotice('running the ' . $ref . ' copy of bin/check-pow.php (a PR cannot weaken its own gate)');

        $forwarded = [];

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg !== '--self-check') {
                $forwarded[] = $arg;
            }
        }

        /** @var array<string, string> $env */
        $env = getenv();
        $env['CHECK_POW_BOOTSTRAPPED'] = '1';

        $process = @proc_open(
            [PHP_BINARY, $tmp, ...$forwarded],
            [1 => STDOUT, 2 => STDERR],
            $pipes,
            $root,
            $env,
        );

        if (!is_resource($process)) {
            @unlink($tmp);
            cpowNotice('unable to run the ' . $ref . ' copy — using the in-tree copy');

            return;
        }

        $code = proc_close($process);
        @unlink($tmp);

        exit($code);
    }

    fwrite(STDERR, str_repeat('!', 72) . "\n");
    cpowNotice('origin/master has no bin/check-pow.php yet — FALLING BACK to the in-tree copy.');
    cpowNotice('this pull request is judged by its own version of the gate; that is only');
    cpowNotice('acceptable for the change that introduces the gate.');
    fwrite(STDERR, str_repeat('!', 72) . "\n");
}

// --------------------------------------------------------------------------
// CLI
// --------------------------------------------------------------------------

function cpowUsage(): void
{
    fwrite(STDOUT, <<<TXT
        check-pow.php — verify the proof of work of the current pull request

        Usage: php bin/check-pow.php [options]

        Options:
          --strict           "cannot determine" becomes a failure (CI uses this)
          --verify-reality   recompute lint/test/coverage and compare them with the
                             values declared in the manifest (expensive)
          --pr=<n>           validate this pull request instead of looking one up
          --branch=<name>    validate this branch instead of the checked-out one
          --self-check       re-exec the origin/master copy of this script first
          -h, --help         show this help

        The gate enforces on ^(fix|feat|process)/issue-<N> branches and on any diff
        touching a protected path; everything else is a one-line skip with exit 0.

        Exit codes: 0 pass or skip, 1 gate violation, 2 usage error.

        TXT);
}

/**
 * @param list<string> $argv
 *
 * @return array{strict: bool, verify-reality: bool, self-check: bool, pr: int|null, branch: string|null}
 */
function cpowParseArgs(array $argv): array
{
    $options = ['strict' => false, 'verify-reality' => false, 'self-check' => false, 'pr' => null, 'branch' => null];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') {
            cpowUsage();
            exit(0);
        }

        if (!str_starts_with($arg, '--')) {
            cpowFail('unexpected argument "' . $arg . '" (see --help)');
        }

        $eq = strpos($arg, '=');
        $name = $eq === false ? substr($arg, 2) : substr($arg, 2, $eq - 2);
        $value = $eq === false ? null : substr($arg, $eq + 1);

        if (in_array($name, CPOW_FLAGS, true)) {
            if ($value !== null) {
                cpowFail('--' . $name . ' does not take a value');
            }

            $options[$name] = true;

            continue;
        }

        if (!in_array($name, CPOW_VALUE_OPTIONS, true)) {
            cpowFail('unknown option --' . $name . ' (see --help)');
        }

        if ($value === null || $value === '') {
            cpowFail('option --' . $name . ' requires a value');
        }

        if ($name === 'pr') {
            if (!ctype_digit($value)) {
                cpowFail('--pr expects a positive integer, got "' . $value . '"');
            }

            $options['pr'] = (int) $value;

            continue;
        }

        $options['branch'] = $value;
    }

    /** @var array{strict: bool, verify-reality: bool, self-check: bool, pr: int|null, branch: string|null} $options */
    return $options;
}

/**
 * @param array{strict: bool, verify-reality: bool, self-check: bool, pr: int|null, branch: string|null} $options
 * @param list<string>                                                                                   $argv
 */
function cpowMain(array $options, array $argv): int
{
    if ((string) getenv('CHECK_POW_SKIP') === '1') {
        fwrite(STDOUT, "check-pow: skipped (CHECK_POW_SKIP=1)\n");

        return 0;
    }

    $root = cpowRoot();
    $strict = $options['strict'];

    if ($options['self-check']) {
        cpowBootstrap($root, $argv);
    }

    $branch = $options['branch'] ?? cpowCurrentBranch($root);

    if ($branch === '') {
        fwrite(STDOUT, "check-pow: skipped — unable to determine the branch\n");

        return $strict ? 1 : 0;
    }

    $base = cpowBaseRef($root);
    $files = cpowChangedFiles($root, $base);

    /** @var list<array{level: string, id: string, message: string}> $report */
    $report = [];

    $protectedTouched = cpowCheckProtectedPaths($root, $base, $files, $branch, $report);
    $isIssueBranch = preg_match(CPOW_ISSUE_BRANCH, $branch, $branchMatch) === 1;

    if (!$isIssueBranch && !$protectedTouched) {
        fwrite(STDOUT, sprintf(
            "check-pow: skipped — \"%s\" is not an issue branch and no protected path is touched\n",
            $branch,
        ));

        return 0;
    }

    $branchIssue = $isIssueBranch ? (int) $branchMatch[2] : null;

    $resolved = cpowResolvePr($root, $options['pr'], $branch);

    if ($resolved['status'] !== 'ok' || $resolved['pr'] === null) {
        cpowAdd($report, 'undetermined', 'POW-00', 'no pull request to validate: ' . $resolved['reason']);

        return cpowPrintReport($report, $strict);
    }

    $pr = $resolved['pr'];
    $prNumber = (int) ($pr['number'] ?? 0);

    // POW-10, second half: the approval is only knowable through gh, and it
    // does not exist yet while the change is still being written — so it is a
    // hard gate in CI (strict) and a warning locally.
    if ($protectedTouched) {
        $approver = cpowMaintainerApproval($pr);

        if ($approver === null) {
            cpowAdd($report, $strict ? 'violation' : 'incomplete', 'POW-10', sprintf(
                'PR #%d touches a protected path but carries no maintainer approval',
                $prNumber,
            ));
        } else {
            cpowAdd($report, 'notice', 'POW-10', 'protected-path change approved by ' . $approver);
        }
    }

    if (cpowCheckBypass($root, $pr, $strict, $report)) {
        return cpowPrintReport($report, $strict);
    }

    // POW-01 — no work without an issue.
    $closing = cpowClosingIssues($pr);

    if ($closing === []) {
        cpowAdd($report, 'violation', 'POW-01', sprintf(
            'PR #%d has no closingIssuesReferences — no work without an issue (add "Closes #<N>" to the body)',
            $prNumber,
        ));
    }

    $issue = $closing === [] ? $branchIssue : $closing[0];

    if ($issue === null) {
        cpowAdd($report, 'undetermined', 'POW-02', 'no issue number could be derived from the PR or the branch');

        return cpowPrintReport($report, $strict);
    }

    if ($branchIssue !== null && $closing !== [] && !in_array($branchIssue, $closing, true)) {
        cpowAdd($report, 'notice', 'POW-01', sprintf(
            'branch %s names issue #%d but the PR closes #%s',
            $branch,
            $branchIssue,
            implode(', #', $closing),
        ));
    }

    // POW-04 — the scratch buffer must not be in the diff, whatever else is true.
    cpowCheckScratchBuffer($files, $report);

    // POW-02 — the durable proof of work must exist for that issue.
    $dir = cpowIssueDir($root, $issue);

    if ($dir === null) {
        cpowAdd($report, 'incomplete', 'POW-02', sprintf(
            'no docs/proof_of_work/%04d-<slug>/ for issue #%d — run `php bin/pow.php --finish` (workflow step 11.5)',
            $issue,
            $issue,
        ));

        return cpowPrintReport($report, $strict);
    }

    $relativeDir = substr($dir, strlen($root) + 1);
    $manifest = cpowManifest($dir);

    if ($manifest === null) {
        cpowAdd($report, 'violation', 'POW-02', $relativeDir . '/manifest.json is missing or not valid JSON');

        return cpowPrintReport($report, $strict);
    }

    cpowCheckManifest($dir, $manifest, $report);
    cpowCheckCommentChain($root, $manifest, $report);
    cpowCheckLedgerHistory($root, $base, $relativeDir, $report);
    cpowCheckReRolls($root, $base, $manifest, $report);

    if ($options['verify-reality']) {
        cpowCheckReality($root, $manifest, $report);
    }

    if ($report === []) {
        cpowAdd($report, 'notice', 'POW-00', sprintf(
            'proof of work for issue #%d verified (%s)',
            $issue,
            $relativeDir,
        ));
    }

    return cpowPrintReport($report, $strict);
}

try {
    /** @var list<string> $argv */
    exit(cpowMain(cpowParseArgs($argv), $argv));
} catch (Throwable $e) {
    cpowFail($e->getMessage(), 1);
}
