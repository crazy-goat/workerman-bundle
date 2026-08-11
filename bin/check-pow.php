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
 *   --advisory         report only, always exit 0 (what `composer lint` runs)
 *   --verify-reality   recompute lint/test/coverage and compare them with the
 *                      values declared in the manifest (expensive; CI only)
 *   --pr=<n>           validate this pull request instead of looking one up
 *   --branch=<name>    validate this branch instead of the checked-out one
 *   -h, --help         show this help
 *
 * Exit codes:
 *   0  pass, gracefully skipped (nothing to enforce), or --advisory
 *   1  gate violation
 *   2  usage error
 *
 * Scope: the gate ENFORCES when the branch matches ^(fix|feat|process)/issue-\d+
 * or when the diff touches a protected path. It SKIPS — one notice, exit 0 —
 * on a base branch (master/main), on any other branch, when no pull request
 * exists for the branch, and when `gh` is missing, unauthenticated or offline.
 * `--strict` turns those skips into failures, because in CI "cannot determine"
 * is indistinguishable from "hidden".
 *
 * Severity of a finding decides who fails on it:
 *   violation     evidence of tampering — exit 1 unless --advisory
 *   incomplete    the cycle is not finished yet — exit 1 only with --strict
 *   undetermined  a fact could not be read — exit 1 only with --strict
 *   notice        informational, never fails
 * That split is what keeps the pre-push hook usable in the middle of a cycle:
 * the POW is legitimately incomplete until step 11.5, but a tampered comment
 * or a leaked scratch buffer is never legitimate. `composer lint` goes one step
 * further and runs `--advisory`, so no state of a cycle in progress can turn
 * the canonical entry point red (see DEC-008).
 *
 * The gate that judges a pull request is materialised from `origin/master` by
 * CI (see .github/workflows/tests.yaml), so a pull request cannot weaken it.
 * That is the single mechanism; there is deliberately no in-script equivalent,
 * because locally `master` is whatever the developer's clone happens to hold.
 *
 * Environment:
 *   CHECK_POW_ROOT         repository root to operate on (default: parent of bin/)
 *   CHECK_POW_SKIP=1       exit 0 immediately; set automatically for the
 *                          subprocesses spawned by --verify-reality so the
 *                          `composer lint` it runs does not recurse into this
 *                          script again. IGNORED under --strict — the mode CI
 *                          uses — so it can never disable the real gate.
 *   CHECK_POW_GH_FIXTURE   path to a JSON file replacing every `gh` call
 *                          (test hook, see tests/ProofOfWork/CheckPowScriptTest.php).
 *                          IGNORED when CI/GITHUB_ACTIONS is truthy.
 *   CHECK_POW_LINT_CMD     shell command used by --verify-reality instead of
 *                          `composer lint`
 *   CHECK_POW_TEST_CMD     shell command used by --verify-reality instead of
 *                          `composer test` (CI passes `composer test:coverage`
 *                          so one run yields both the exit code and the clover
 *                          file the coverage comparison needs)
 */

require_once __DIR__ . '/pow-common.php';

/** Coverage is a float derived from a clover file; compare with a tolerance. */
const CPOW_COVERAGE_TOLERANCE = 0.05;

/** An approval only counts as "maintainer" from one of these associations. */
const CPOW_MAINTAINER_ASSOCIATIONS = ['OWNER', 'MEMBER', 'COLLABORATOR'];

/** Agents whose runs must be accounted for in the manifest (round or aborted). */
const CPOW_ROUND_AGENT = '#^(coder|review)#';

/** Files that may only change through a `process/` branch with an approval. */
const CPOW_PROTECTED_FILES = ['bin/pow.php', 'bin/pow-common.php', 'bin/check-pow.php'];

/** Path prefixes under the same rule. */
const CPOW_PROTECTED_PREFIXES = ['.github/workflows/'];

const CPOW_FLAGS = ['strict', 'advisory', 'verify-reality', 'help'];

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
    $env = null;

    if ($markChild) {
        /** @var array<string, string> $env */
        $env = getenv();
        $env['CHECK_POW_SKIP'] = '1';
    }

    // powcRun drains both pipes concurrently. Reading one to EOF first
    // deadlocks the moment the child fills the other pipe's 64 KB buffer, and
    // --verify-reality runs `composer lint` and `composer test:coverage` —
    // precisely the two commands that produce that much output.
    return powcRun($cmd, $cwd, $env);
}

/**
 * @return list<string>
 */
function cpowLines(string $text): array
{
    return powcLines($text);
}

/**
 * The repository under test.
 *
 * `dirname(__DIR__)` is wrong for the one invocation that matters most: CI
 * materialises this script into $RUNNER_TEMP (so a pull request cannot supply
 * its own gate), where the parent directory is not the checkout. The gate then
 * found no git repository, no proof of work and no pull request, and the job
 * failed on POW-00/POW-02 whatever the branch had actually recorded. So when
 * the script's own parent is not a working tree, the working tree of the
 * current directory wins.
 */
function cpowRoot(): string
{
    $configured = (string) getenv('CHECK_POW_ROOT');

    if ($configured === '') {
        $configured = (string) getenv('POW_ROOT');
    }

    $root = $configured !== '' ? $configured : cpowDetectRoot();
    $real = realpath($root);

    if ($real === false || !is_dir($real)) {
        cpowFail('CHECK_POW_ROOT is not a directory: ' . $root);
    }

    return $real;
}

function cpowDetectRoot(): string
{
    $beside = dirname(__DIR__);

    if (is_dir($beside . '/.git') || is_file($beside . '/.git')) {
        return $beside;
    }

    $result = cpowRun(['git', 'rev-parse', '--show-toplevel']);
    $top = trim($result['out']);

    if ($result['code'] === 0 && $top !== '' && is_dir($top)) {
        cpowNotice('running from outside the repository — operating on ' . $top);

        return $top;
    }

    return $beside;
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
function cpowPrintReport(array $report, bool $strict, bool $advisory = false): int
{
    $labels = [
        'violation' => $advisory ? 'WARN' : 'FAIL',
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
        $failed && !$advisory ? 'FAILED' : 'ok',
        count($report),
        $advisory ? 'report-only' : ($strict ? 'strict' : 'advisory'),
    ));

    // --advisory never fails: it is what `composer lint` runs, and lint must
    // stay green for everyone who is not in the middle of a cycle (DEC-008).
    return $failed && !$advisory ? 1 : 0;
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
    foreach (POWC_BASE_REFS as $candidate) {
        if (cpowRun(['git', 'rev-parse', '--verify', '--quiet', $candidate], $root)['code'] === 0) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The files this branch adds or changes on top of the base.
 *
 * git's exit code is part of the answer: `git diff <base>...HEAD` fails with
 * "fatal: no merge base" (exit 128, empty stdout) in a shallow clone, and
 * silently returning an empty list there turned POW-04 and POW-10 off without
 * a word — exactly the "cannot determine is indistinguishable from hidden"
 * case --strict exists to catch.
 *
 * Untracked-but-not-ignored files are unioned in: a brand-new protected file
 * is otherwise invisible to the gate until it is committed.
 *
 * @return array{ok: bool, reason: string, files: list<string>}
 */
function cpowChangedFiles(string $root, ?string $base): array
{
    if ($base === null) {
        return ['ok' => false, 'reason' => 'no base ref (origin/master, master, origin/main, main)', 'files' => []];
    }

    $diff = cpowRun(['git', 'diff', '--name-only', $base . '...HEAD'], $root);

    if ($diff['code'] !== 0) {
        return [
            'ok' => false,
            'reason' => 'git diff ' . $base . '...HEAD exited ' . $diff['code'] . ': ' . trim($diff['err']),
            'files' => [],
        ];
    }

    $files = cpowLines($diff['out']);
    $untracked = cpowRun(['git', 'ls-files', '--others', '--exclude-standard'], $root);

    if ($untracked['code'] !== 0) {
        return [
            'ok' => false,
            'reason' => 'git ls-files --others exited ' . $untracked['code'] . ': ' . trim($untracked['err']),
            'files' => $files,
        ];
    }

    foreach (cpowLines($untracked['out']) as $file) {
        if (!in_array($file, $files, true)) {
            $files[] = $file;
        }
    }

    sort($files);

    return ['ok' => true, 'reason' => '', 'files' => $files];
}

function cpowShow(string $root, string $ref, string $path): ?string
{
    $result = cpowRun(['git', 'show', $ref . ':' . $path], $root);

    return $result['code'] === 0 ? $result['out'] : null;
}

// --------------------------------------------------------------------------
// gh (or its fixture)
// --------------------------------------------------------------------------

/** True for anything but unset, "", "0", "false" and "no". */
function cpowEnvTruthy(string $name): bool
{
    $value = strtolower(trim((string) getenv($name)));

    return !in_array($value, ['', '0', 'false', 'no'], true);
}

/**
 * Latches whether the `gh` fixture kill switch is honoured. Set once by
 * cpowMain() before anything can read the fixture.
 */
function cpowFixtureDisabled(?bool $set = null): bool
{
    static $disabled = false;

    if ($set !== null) {
        $disabled = $set;
    }

    return $disabled;
}

/**
 * @return array<string, mixed>|null
 */
function cpowFixture(): ?array
{
    static $fixture = false;

    if (cpowFixtureDisabled()) {
        return null;
    }

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
        // Open first: a reused branch name can otherwise resolve to a closed
        // pull request from a previous cycle, and the gate would then validate
        // that PR's comments instead of this one's.
        foreach (['open', 'all'] as $state) {
            $list = cpowGh(['pr', 'list', '--head', $branch, '--state', $state, '--limit', '1', '--json', 'number'], $root);

            if ($list['code'] !== 0) {
                return ['status' => 'unavailable', 'pr' => null, 'reason' => 'gh pr list failed: ' . trim($list['err'])];
            }

            $decoded = json_decode(trim($list['out']) === '' ? '[]' : $list['out'], true);

            if (!is_array($decoded) || $decoded === []) {
                continue;
            }

            $first = reset($decoded);
            $candidate = is_array($first) && isset($first['number']) ? (int) $first['number'] : 0;

            if ($candidate > 0) {
                $number = $candidate;

                break;
            }
        }

        if ($number === null) {
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
 * Labels of an issue, for re-deriving the profile the manifest claims.
 *
 * @return array{status: 'ok'|'unavailable', labels: list<string>}
 */
function cpowIssueLabels(string $root, int $issue): array
{
    $fixture = cpowFixture();

    if ($fixture !== null) {
        $issues = is_array($fixture['issues'] ?? null) ? $fixture['issues'] : [];
        $entry = $issues[(string) $issue] ?? null;

        return is_array($entry)
            ? ['status' => 'ok', 'labels' => cpowLabels($entry)]
            : ['status' => 'unavailable', 'labels' => []];
    }

    if (!cpowGhAvailable($root)) {
        return ['status' => 'unavailable', 'labels' => []];
    }

    $result = cpowGh(['issue', 'view', (string) $issue, '--json', 'labels'], $root);

    if ($result['code'] !== 0) {
        return ['status' => 'unavailable', 'labels' => []];
    }

    $decoded = cpowDecode($result['out']);

    return $decoded === null
        ? ['status' => 'unavailable', 'labels' => []]
        : ['status' => 'ok', 'labels' => cpowLabels($decoded)];
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
 * The most recent approval "on record": a submitted APPROVED review by someone
 * with write access. A comment saying "looks good" is not an approval.
 *
 * An absent or non-string `authorAssociation` means "not a maintainer". It used
 * to mean "assume the best", so any APPROVED review — a drive-by from a fork —
 * authorised rewriting the gate.
 *
 * `submittedAt` is read from the review object itself; `gh pr view --json`
 * has no top-level `submittedAt` field, the timestamp ships inside `reviews`.
 *
 * @param array<string, mixed> $pr
 *
 * @return array{login: string, submitted_at: string}|null
 */
function cpowMaintainerApproval(array $pr): ?array
{
    $reviews = $pr['reviews'] ?? [];

    if (!is_array($reviews)) {
        return null;
    }

    $latest = null;

    foreach ($reviews as $review) {
        if (!is_array($review) || ($review['state'] ?? null) !== 'APPROVED') {
            continue;
        }

        if (!is_string($review['authorAssociation'] ?? null)
            || !in_array($review['authorAssociation'], CPOW_MAINTAINER_ASSOCIATIONS, true)) {
            continue;
        }

        $author = $review['author'] ?? null;
        $submitted = is_string($review['submittedAt'] ?? null) ? $review['submittedAt'] : '';
        $approval = [
            'login' => is_array($author) && is_string($author['login'] ?? null) ? $author['login'] : 'unknown',
            'submitted_at' => $submitted,
        ];

        if ($latest === null || strtotime($submitted) > strtotime($latest['submitted_at'])) {
            $latest = $approval;
        }
    }

    return $latest;
}

/**
 * Author date of the newest commit touching one of the protected paths, so a
 * stale approval cannot authorise a later rewrite of the gate.
 *
 * @param list<string> $paths
 *
 * @return array{ok: bool, timestamp: int|null}
 */
function cpowNewestCommitDate(string $root, ?string $base, array $paths): array
{
    if ($base === null || $paths === []) {
        return ['ok' => false, 'timestamp' => null];
    }

    $result = cpowRun(['git', 'log', '-1', '--format=%at', $base . '..HEAD', '--', ...$paths], $root);

    if ($result['code'] !== 0) {
        return ['ok' => false, 'timestamp' => null];
    }

    $stamp = trim($result['out']);

    return ['ok' => true, 'timestamp' => $stamp === '' ? null : (int) $stamp];
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
 * The ledger, parsed by exactly the parser bin/pow.php writes it with.
 *
 * @return array{state: array<string, array{first_round: int, status: string, loc: string, desc: string, severity: string}>, errors: list<string>}
 */
function cpowLedger(string $ledger): array
{
    $parsed = powcParseLedger($ledger);

    return ['state' => powcLedgerState($parsed['rows']), 'errors' => $parsed['errors']];
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
 * A diff touching bin/pow.php, bin/pow-common.php, bin/check-pow.php,
 * .github/workflows/* or the `scripts` block of composer.json rewrites the gate
 * itself, so it needs the `process/` branch prefix plus a maintainer approval.
 *
 * Detection only — the two reporting halves run after the scope gate, so the
 * gate never speaks about a branch it has no business judging.
 *
 * @param list<string>                                          $files
 * @param list<array{level: string, id: string, message: string}> $report
 *
 * @return list<string> the protected paths this diff touches
 */
function cpowProtectedPaths(string $root, ?string $base, array $files, array &$report): array
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

    return $touched;
}

/**
 * POW-10, branch half.
 *
 * @param list<string>                                          $touched
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckProtectedBranch(array $touched, string $branch, array &$report): void
{
    if (!str_starts_with($branch, 'process/')) {
        cpowAdd($report, 'violation', 'POW-10', sprintf(
            'the diff touches protected path(s) %s but the branch is "%s" — gate and tooling changes '
            . 'require a process/ branch (bin/gh-branch <issue> process)',
            implode(', ', $touched),
            $branch,
        ));

        return;
    }

    cpowAdd($report, 'notice', 'POW-10', 'protected path(s) touched from a process/ branch: ' . implode(', ', $touched));
}

/**
 * POW-10, approval half. An approval submitted before the newest commit
 * touching a protected path did not see that commit, so it cannot authorise it.
 *
 * @param array<string, mixed>                                  $pr
 * @param list<string>                                          $touched
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckProtectedApproval(string $root, ?string $base, array $pr, int $prNumber, array $touched, bool $strict, array &$report): void
{
    $approval = cpowMaintainerApproval($pr);

    if ($approval === null) {
        cpowAdd($report, $strict ? 'violation' : 'incomplete', 'POW-10', sprintf(
            'PR #%d touches a protected path but carries no maintainer approval',
            $prNumber,
        ));

        return;
    }

    $paths = array_values(array_filter($touched, static fn(string $path): bool => !str_contains($path, ' ')));
    $newest = cpowNewestCommitDate($root, $base, $paths);
    $approvedAt = $approval['submitted_at'] === '' ? false : strtotime($approval['submitted_at']);

    if (!$newest['ok'] || $newest['timestamp'] === null) {
        cpowAdd($report, 'notice', 'POW-10', 'protected-path change approved by ' . $approval['login']
            . ' (the date of the newest protected-path commit could not be read, so the approval was not aged)');

        return;
    }

    if ($approvedAt === false) {
        cpowAdd($report, $strict ? 'violation' : 'incomplete', 'POW-10', sprintf(
            'the approval by %s carries no submittedAt, so it cannot be shown to postdate the protected-path change',
            $approval['login'],
        ));

        return;
    }

    if ($approvedAt < $newest['timestamp']) {
        cpowAdd($report, 'violation', 'POW-10', sprintf(
            'the approval by %s was submitted at %s, before the newest protected-path commit (%s) — a stale '
            . 'approval cannot authorise a later rewrite of the gate; re-request the review',
            $approval['login'],
            $approval['submitted_at'],
            gmdate('Y-m-d\TH:i:s\Z', $newest['timestamp']),
        ));

        return;
    }

    cpowAdd($report, 'notice', 'POW-10', 'protected-path change approved by ' . $approval['login'] . ' at ' . $approval['submitted_at']);
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
 * POW-02b — the manifest identifies THIS cycle, in THIS schema.
 *
 * Without this the whole proof of work of an unrelated, already-merged issue
 * can be replayed: rename its directory to match the new issue number and every
 * other check still passes, because the comments it points at are real and
 * hash correctly. The manifest's own `issue`/`branch` are what bind it.
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckManifestIdentity(array $manifest, int $issue, string $branch, string $relativeDir, array &$report): void
{
    $version = $manifest['pow_version'] ?? null;

    if (!is_int($version)) {
        cpowAdd($report, 'violation', 'POW-02', $relativeDir . '/manifest.json declares no pow_version (this gate reads version ' . POWC_VERSION . ')');
    } elseif ($version !== POWC_VERSION) {
        cpowAdd($report, 'violation', 'POW-02', sprintf(
            '%s/manifest.json declares pow_version %d, this gate reads version %d',
            $relativeDir,
            $version,
            POWC_VERSION,
        ));
    }

    $declaredIssue = $manifest['issue'] ?? null;

    if (!is_int($declaredIssue) || $declaredIssue !== $issue) {
        cpowAdd($report, 'violation', 'POW-02', sprintf(
            '%s/manifest.json records issue %s but this pull request closes #%d — a proof of work from another '
            . 'issue was replayed (renaming the directory does not rebind it)',
            $relativeDir,
            is_int($declaredIssue) ? '#' . $declaredIssue : var_export($declaredIssue, true),
            $issue,
        ));
    }

    $declaredBranch = $manifest['branch'] ?? null;

    if (!is_string($declaredBranch) || $declaredBranch !== $branch) {
        cpowAdd($report, 'violation', 'POW-02', sprintf(
            '%s/manifest.json records branch %s but the branch under test is "%s"',
            $relativeDir,
            is_string($declaredBranch) ? '"' . $declaredBranch . '"' : var_export($declaredBranch, true),
            $branch,
        ));
    }
}

/**
 * POW-03 — the manifest must be complete for the profile it is entitled to.
 *
 * The profile is re-derived here rather than read: `bin/pow.php` works hard to
 * make it unfakeable, and trusting `manifest.profile` threw that away — editing
 * one string dropped the minimum round count from 2 to 1.
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckManifest(string $root, string $dir, array $manifest, int $issue, string $branch, array &$report): void
{
    $declared = is_string($manifest['profile'] ?? null) ? $manifest['profile'] : '';
    $profile = cpowDeriveProfile($root, $issue, $branch, $report);

    if ($declared !== $profile) {
        cpowAdd($report, 'violation', 'POW-03', sprintf(
            'manifest declares profile "%s" but branch "%s" and the labels of issue #%d entitle it to "%s" — '
            . 'the profile decides the minimum number of rounds and is not the orchestrator\'s to choose',
            $declared === '' ? '(none)' : $declared,
            $branch,
            $issue,
            $profile,
        ));
    }

    $rounds = is_array($manifest['rounds'] ?? null) ? $manifest['rounds'] : [];

    $ledgerFile = $dir . '/findings.md';
    $ledger = is_file($ledgerFile) ? (string) file_get_contents($ledgerFile) : '';
    $parsed = cpowLedger($ledger);

    foreach ($parsed['errors'] as $error) {
        cpowAdd($report, 'violation', 'POW-03', 'findings.md is malformed — a row nobody can read is a finding nobody sees: ' . $error);
    }

    $escalationFile = $dir . '/escalation.md';
    $escalation = is_file($escalationFile) ? (string) file_get_contents($escalationFile) : '';

    // The same completeness rule bin/pow.php --finish refuses to publish
    // without, so the gate can never accept a cycle the recorder rejected.
    $problems = powcCompletenessProblems(
        $profile,
        count($rounds),
        trim($ledger) !== '',
        is_int($manifest['lint_exit'] ?? null) ? $manifest['lint_exit'] : null,
        is_int($manifest['test_exit'] ?? null) ? $manifest['test_exit'] : null,
        is_string($manifest['verdict'] ?? null) ? $manifest['verdict'] : null,
        $escalation,
        powcOpenIds($parsed['state']),
    );

    foreach ($problems as $problem) {
        cpowAdd($report, 'incomplete', 'POW-03', 'manifest incomplete: ' . $problem);
    }
}

/**
 * The profile a cycle is entitled to, derived from facts the orchestrator does
 * not own: `light` only from a light branch prefix on an issue that carries no
 * `process` label. When `gh` cannot answer, `full` — the strict choice — wins.
 *
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowDeriveProfile(string $root, int $issue, string $branch, array &$report): string
{
    if (powcProfileFromPrefix($branch) !== 'light') {
        return 'full';
    }

    $labels = cpowIssueLabels($root, $issue);

    if ($labels['status'] !== 'ok') {
        cpowAdd($report, 'notice', 'POW-03', sprintf(
            'the labels of issue #%d could not be read — assuming the full profile',
            $issue,
        ));

        return 'full';
    }

    return in_array('process', $labels['labels'], true) ? 'full' : 'light';
}

/**
 * POW-05 — the comment chain.
 *
 * @param array<string, mixed>                                  $manifest
 * @param list<array{level: string, id: string, message: string}> $report
 */
function cpowCheckCommentChain(string $root, array $manifest, int $prNumber, array &$report): void
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

        // The comment must live on THIS pull request. Without this binding a
        // whole proof of work can be lifted from a merged issue: its comments
        // are real, so every hash and timestamp still checks out. `issue_url`
        // rides along in the REST payload, so it costs no extra API call.
        $issueUrl = is_string($comment['issue_url'] ?? null) ? $comment['issue_url'] : '';

        if ($issueUrl === '') {
            cpowAdd($report, 'undetermined', 'POW-05', $label . ': comment ' . $id . ' carries no issue_url — it cannot be bound to PR #' . $prNumber);
        } elseif (!str_ends_with(rtrim($issueUrl, '/'), '/issues/' . $prNumber)) {
            cpowAdd($report, 'violation', 'POW-05', sprintf(
                '%s: comment %d belongs to %s, not to PR #%d — this proof of work was replayed from another pull request',
                $label,
                $id,
                $issueUrl,
                $prNumber,
            ));
        }

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

    if ($log['code'] !== 0) {
        cpowAdd($report, 'undetermined', 'POW-06', sprintf(
            'git log %s..HEAD -- %s exited %d: %s',
            $base,
            $path,
            $log['code'],
            trim($log['err']),
        ));

        return;
    }

    $commits = cpowLines($log['out']);

    if (count($commits) < 2) {
        // The documented flow commits findings.md once, at step 11.5, so this
        // is the normal case and POW-06 has nothing to compare. Say so: an
        // inert check that looks like a passing check is worse than no check.
        // The append-only property is anchored by POW-05's comment chain, which
        // does not depend on how many commits the ledger arrived in.
        cpowAdd($report, 'notice', 'POW-06', sprintf(
            '%s has %d commit(s) on this branch — the append-only comparison needs two, so it did not run '
            . '(the ledger\'s real anchor is the POW-05 comment chain)',
            $path,
            count($commits),
        ));

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
        // `.pi-subagents/` is gitignored, so it never exists on a runner. This
        // check is therefore a LOCAL advisory one, not a CI gate — see
        // bin/README.md. Saying "skipped" is the honest report; pretending it
        // passed would be the lie.
        cpowAdd($report, 'notice', 'POW-07', 'no .pi-subagents/artifacts/ here (always the case in CI) — '
            . 'the re-roll check is local-only and did not run');

        return;
    }

    if ($base === null) {
        cpowAdd($report, 'undetermined', 'POW-07', 'no base ref — the branch time window is unknown');

        return;
    }

    // %at (author date), not %ct (committer date): `git rebase` rewrites the
    // committer date to now, and docs/workflow.md documents rebasing as
    // routine, so a rebase silently emptied the window.
    $log = cpowRun(['git', 'log', '--format=%at', '--reverse', $base . '..HEAD'], $root);

    if ($log['code'] !== 0) {
        cpowAdd($report, 'undetermined', 'POW-07', sprintf(
            'git log %s..HEAD exited %d: %s',
            $base,
            $log['code'],
            trim($log['err']),
        ));

        return;
    }

    $stamps = cpowLines($log['out']);

    if ($stamps === []) {
        cpowAdd($report, 'notice', 'POW-07', 'no commits on the branch yet — the re-roll check is skipped');

        return;
    }

    // The earliest author date on the branch, not the first entry: a cherry-pick
    // can leave the list unsorted, and the earlier window is the stricter one.
    $windowStart = min(array_map('intval', $stamps));
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

    $approval = cpowMaintainerApproval($pr);

    if ($approval === null) {
        cpowAdd($report, 'violation', 'POW-09', 'the `no-pow` label requires a maintainer approval on the pull request — there is none on record');
    } else {
        cpowAdd($report, 'notice', 'POW-09', 'bypass approved by ' . $approval['login']);
    }

    // docs/process-changelog.md is created by phase 4 of issue #686, together
    // with its cycle-zero entry (see docs/process-changelog.md#1). The branch
    // below is therefore normally dead code from here on; it is kept as a
    // defensive fallback — if the file were ever deleted, a `no-pow` PR
    // degrades to `undetermined` under --strict (fails CI) rather than
    // passing silently, which is the right way round for a missing record.
    $changelog = $root . '/docs/process-changelog.md';

    if (!is_file($changelog)) {
        cpowAdd($report, $strict ? 'undetermined' : 'notice', 'POW-09', 'docs/process-changelog.md does not exist — the bypass cannot be recorded');

        return true;
    }

    $contents = (string) file_get_contents($changelog);
    $numbers = [$number];

    foreach ($issues as $issue) {
        $numbers[] = $issue;
    }

    foreach (explode("\n", $contents) as $line) {
        // "no-pow" on the line as well as the number: a changelog that happens
        // to mention #700 for an unrelated reason is not a bypass record. The
        // number is matched on a word boundary, so #700 no longer matches
        // #7001, and a line saying "not #700" no longer satisfies the check.
        if (!str_contains($line, 'no-pow')) {
            continue;
        }

        foreach ($numbers as $candidate) {
            if (preg_match('/(?<![0-9])#' . $candidate . '(?![0-9])/', $line) === 1) {
                cpowAdd($report, 'notice', 'POW-09', 'bypass recorded in docs/process-changelog.md (#' . $candidate . ')');

                return true;
            }
        }
    }

    cpowAdd($report, 'violation', 'POW-09', sprintf(
        'the `no-pow` bypass is not recorded in docs/process-changelog.md — add a line naming `no-pow` and %s',
        implode(' or ', array_map(static fn(int $n): string => '#' . $n, $numbers)),
    ));

    return true;
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
          --advisory         report only, always exit 0 (what `composer lint` runs)
          --verify-reality   recompute lint/test/coverage and compare them with the
                             values declared in the manifest (expensive)
          --pr=<n>           validate this pull request instead of looking one up
          --branch=<name>    validate this branch instead of the checked-out one
          -h, --help         show this help

        The gate enforces on ^(fix|feat|process)/issue-<N> branches and on any diff
        touching a protected path; everything else is a one-line skip with exit 0.

        CI runs the origin/master copy of this script (see
        .github/workflows/tests.yaml), so a pull request cannot weaken the gate
        that judges it.

        Exit codes: 0 pass or skip, 1 gate violation, 2 usage error.

        TXT);
}

/**
 * @param list<string> $argv
 *
 * @return array{strict: bool, advisory: bool, verify-reality: bool, pr: int|null, branch: string|null}
 */
function cpowParseArgs(array $argv): array
{
    $options = ['strict' => false, 'advisory' => false, 'verify-reality' => false, 'pr' => null, 'branch' => null];

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

    if ($options['strict'] === true && $options['advisory'] === true) {
        cpowFail('--strict and --advisory are contradictory');
    }

    /** @var array{strict: bool, advisory: bool, verify-reality: bool, pr: int|null, branch: string|null} $options */
    return $options;
}

/**
 * @param array{strict: bool, advisory: bool, verify-reality: bool, pr: int|null, branch: string|null} $options
 */
function cpowMain(array $options): int
{
    $strict = $options['strict'];
    $advisory = $options['advisory'];

    /** @var list<array{level: string, id: string, message: string}> $report */
    $report = [];

    // CHECK_POW_SKIP exists for one reason: --verify-reality spawns
    // `composer lint`, which would otherwise recurse into this script. That
    // child is never --strict, and CI always is, so honouring the switch only
    // outside --strict keeps the recursion guard and takes away the kill
    // switch. Same idea for the `gh` fixture, which is a test hook and has no
    // business answering for GitHub on a runner.
    $skipRequested = (string) getenv('CHECK_POW_SKIP') === '1';
    $onRunner = cpowEnvTruthy('CI') || cpowEnvTruthy('GITHUB_ACTIONS');
    $fixtureRequested = (string) getenv('CHECK_POW_GH_FIXTURE') !== '';

    cpowFixtureDisabled($fixtureRequested && $onRunner);

    if ($skipRequested && $strict) {
        cpowAdd($report, 'violation', 'POW-11', 'CHECK_POW_SKIP=1 is set but ignored under --strict — the gate cannot be switched off from the environment');
    }

    if ($fixtureRequested && $onRunner) {
        cpowAdd($report, 'violation', 'POW-11', 'CHECK_POW_GH_FIXTURE is set but ignored on a CI runner — GitHub is not answered from a local JSON file here');
    }

    if ($skipRequested && !$strict) {
        fwrite(STDOUT, "check-pow: skipped (CHECK_POW_SKIP=1)\n");

        return 0;
    }

    $root = cpowRoot();
    $branch = $options['branch'] ?? cpowCurrentBranch($root);

    if ($branch === '') {
        fwrite(STDOUT, "check-pow: skipped — unable to determine the branch\n");

        return $strict ? 1 : 0;
    }

    // A base branch is never gated against itself: with a stale origin/master
    // the whole of master reads as "the diff", so every protected file in the
    // repository looks freshly touched from a non-process/ branch.
    if (in_array($branch, POWC_BASE_BRANCHES, true)) {
        fwrite(STDOUT, sprintf("check-pow: skipped — \"%s\" is a base branch, there is nothing to gate it against\n", $branch));

        return cpowPrintReport($report, $strict, $advisory);
    }

    $base = cpowBaseRef($root);
    $changed = cpowChangedFiles($root, $base);
    $files = $changed['files'];

    if (!$changed['ok']) {
        // "The diff could not be read" and "the diff is clean" must never look
        // the same: POW-04 and POW-10 both read this list, and returning an
        // empty one turned both checks off silently in a shallow clone.
        cpowAdd($report, 'undetermined', 'POW-00', 'the changed-file list is incomplete, so POW-04 and POW-10 are not conclusive: ' . $changed['reason']);
    }

    $protectedTouched = cpowProtectedPaths($root, $base, $files, $report);
    $isIssueBranch = preg_match(powcIssueBranchPattern(), $branch, $branchMatch) === 1;

    if (!$isIssueBranch && $protectedTouched === []) {
        fwrite(STDOUT, sprintf(
            "check-pow: skipped — \"%s\" is not an issue branch and no protected path is touched\n",
            $branch,
        ));

        return cpowPrintReport($report, $strict, $advisory);
    }

    // POW-10, first half. Reported only now that the scope gate has decided we
    // are enforcing at all, so the ordering cannot make the gate speak about a
    // branch it has no business judging.
    if ($protectedTouched !== []) {
        cpowCheckProtectedBranch($protectedTouched, $branch, $report);
    }

    $branchIssue = $isIssueBranch ? (int) $branchMatch[2] : null;

    $resolved = cpowResolvePr($root, $options['pr'], $branch);

    if ($resolved['status'] !== 'ok' || $resolved['pr'] === null) {
        cpowAdd($report, 'undetermined', 'POW-00', 'no pull request to validate: ' . $resolved['reason']);

        return cpowPrintReport($report, $strict, $advisory);
    }

    $pr = $resolved['pr'];
    $prNumber = (int) ($pr['number'] ?? 0);

    // POW-10, second half: the approval is only knowable through gh, and it
    // does not exist yet while the change is still being written — so it is a
    // hard gate in CI (strict) and a warning locally.
    if ($protectedTouched !== []) {
        cpowCheckProtectedApproval($root, $base, $pr, $prNumber, $protectedTouched, $strict, $report);
    }

    if (cpowCheckBypass($root, $pr, $strict, $report)) {
        return cpowPrintReport($report, $strict, $advisory);
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

        return cpowPrintReport($report, $strict, $advisory);
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

        return cpowPrintReport($report, $strict, $advisory);
    }

    $relativeDir = substr($dir, strlen($root) + 1);
    $manifest = cpowManifest($dir);

    if ($manifest === null) {
        cpowAdd($report, 'violation', 'POW-02', $relativeDir . '/manifest.json is missing or not valid JSON');

        return cpowPrintReport($report, $strict, $advisory);
    }

    cpowCheckManifestIdentity($manifest, $issue, $branch, $relativeDir, $report);
    cpowCheckManifest($root, $dir, $manifest, $issue, $branch, $report);
    cpowCheckCommentChain($root, $manifest, $prNumber, $report);
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

    return cpowPrintReport($report, $strict, $advisory);
}

try {
    /** @var list<string> $argv */
    exit(cpowMain(cpowParseArgs($argv)));
} catch (Throwable $e) {
    cpowFail($e->getMessage(), 1);
}
