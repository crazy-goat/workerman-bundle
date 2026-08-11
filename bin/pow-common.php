<?php

declare(strict_types=1);

/**
 * Rules shared by the proof-of-work recorder (bin/pow.php) and the enforcement
 * gate (bin/check-pow.php).
 *
 * Every rule that both scripts have to agree on lives here exactly once. When
 * the recorder and the gate each carried their own copy they drifted, and every
 * drift was a hole: the recorder demanded `lint_exit`/`test_exit` and a
 * per-finding justification for `ACCEPT` while the gate accepted a three-byte
 * `escalation.md`; the recorder drained both subprocess pipes concurrently
 * while the gate deadlocked on the first 64 KB of stderr; the recorder's
 * minimum round count was a bare ternary and the gate's a constant.
 *
 * `bin/` is deliberately outside the PSR-4 autoloader (these scripts must run
 * before `composer install`), so both entry points pull this file in with a
 * plain `require_once __DIR__ . '/pow-common.php'`. Nothing here ever exits or
 * writes to a stream: it returns values and lets the caller decide how loud to
 * be. Function names are prefixed `powc` so neither script can collide with it.
 *
 * NOTE FOR CI: `.github/workflows/tests.yaml` materialises the `origin/master`
 * copy of the gate before running it. It must materialise THIS file next to it,
 * or the gate cannot start. See the "Verify the proof of work" step.
 */

/** Branch types that carry an issue number and are therefore enforced. */
const POWC_ISSUE_BRANCH_TYPES = ['fix', 'feat', 'process'];

/** Round caps per profile. There is no round beyond the cap — the oracle decides. */
const POWC_PROFILE_CAPS = ['full' => 4, 'light' => 2];

/** Minimum recorded rounds per profile. Recorder and gate read the same number. */
const POWC_MIN_ROUNDS = ['full' => 2, 'light' => 1];

/** Branch prefixes that select the `full` profile (audit + gate steps mandatory). */
const POWC_FULL_PREFIXES = ['fix', 'feat', 'refactor', 'perf', 'process'];

/** Branch prefixes that select the `light` profile (cap 2, no gate step). */
const POWC_LIGHT_PREFIXES = ['docs', 'chore', 'ci', 'test', 'build'];

/** Base ref candidates, most specific first. */
const POWC_BASE_REFS = ['origin/master', 'master', 'origin/main', 'main'];

/** Branches that are a base ref themselves and are never gated against it. */
const POWC_BASE_BRANCHES = ['master', 'main'];

const POWC_VERDICTS = ['CLEAN', 'NARROW', 'REDO', 'ACCEPT', 'HUMAN'];

/** Schema version of manifest.json. The gate refuses any other value. */
const POWC_VERSION = 1;

/** Columns of one findings.md row: ID, round, file:line, description, severity, status, resolution. */
const POWC_LEDGER_COLUMNS = 7;

// --------------------------------------------------------------------------
// Branch naming — one source for three renderings
// --------------------------------------------------------------------------

/** PCRE form; capture group 2 is the issue number. */
function powcIssueBranchPattern(): string
{
    return '#^(' . implode('|', POWC_ISSUE_BRANCH_TYPES) . ')/issue-(\d+)#';
}

/** POSIX ERE form, for `grep -E` inside the generated pre-push hook. */
function powcIssueBranchEre(): string
{
    return '^(' . implode('|', POWC_ISSUE_BRANCH_TYPES) . ')/issue-[0-9]+';
}

/**
 * The profile a branch prefix mandates, before any label or manifest is read.
 * `light` is only ever reachable from an explicitly light prefix: an unknown
 * prefix resolves to `full`, which is the strict choice.
 */
function powcProfileFromPrefix(string $branch): string
{
    $prefix = strtolower(explode('/', $branch, 2)[0]);

    return in_array($prefix, POWC_LIGHT_PREFIXES, true) ? 'light' : 'full';
}

function powcIsKnownPrefix(string $branch): bool
{
    $prefix = strtolower(explode('/', $branch, 2)[0]);

    return in_array($prefix, POWC_LIGHT_PREFIXES, true) || in_array($prefix, POWC_FULL_PREFIXES, true);
}

// --------------------------------------------------------------------------
// Subprocesses
// --------------------------------------------------------------------------

/**
 * Reads two pipes concurrently until both reach EOF.
 *
 * Draining one pipe to EOF before touching the other deadlocks as soon as the
 * child fills the other pipe's buffer (64 KB on Linux). `composer lint` and
 * `composer test:coverage` — the two commands `--verify-reality` runs — are
 * exactly the ones that produce that much output, so the deadlock would have
 * hung the CI job until its six-hour timeout instead of failing.
 *
 * @param resource $stdout
 * @param resource $stderr
 *
 * @return array{0: string, 1: string}
 */
function powcDrain($stdout, $stderr): array
{
    stream_set_blocking($stdout, false);
    stream_set_blocking($stderr, false);

    $buffers = ['out' => '', 'err' => ''];
    $open = ['out' => $stdout, 'err' => $stderr];

    while ($open !== []) {
        $read = array_values($open);
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, 5) === false) {
            break;
        }

        foreach ($open as $name => $stream) {
            if (!in_array($stream, $read, true)) {
                continue;
            }

            $chunk = fread($stream, 65536);

            if ($chunk !== false && $chunk !== '') {
                $buffers[$name] .= $chunk;

                continue;
            }

            if (feof($stream)) {
                unset($open[$name]);
            }
        }
    }

    return [$buffers['out'], $buffers['err']];
}

/**
 * Runs a command and returns its exit code and both streams.
 *
 * @param list<string>|string        $cmd array form runs without a shell; the string form goes through /bin/sh
 * @param array<string, string>|null $env null inherits the caller's environment
 *
 * @return array{code: int, out: string, err: string}
 */
function powcRun(array|string $cmd, ?string $cwd = null, ?array $env = null): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);

    if (!is_resource($process)) {
        return ['code' => 127, 'out' => '', 'err' => 'unable to start: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd)];
    }

    [$out, $err] = powcDrain($pipes[1], $pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    // A command that cannot be exec'd is reported differently per platform:
    // macOS fails inside proc_open() and takes the branch above, while on Linux
    // the fork succeeds and the child exits 127 with nothing on stderr. Both
    // are normalised to the same shape so callers never have to care — and so
    // an unstartable command is never a silent failure. A real command exiting
    // 127 without writing to stderr is indistinguishable from this, which is
    // precisely what 127 conventionally means.
    if ($code === 127 && $err === '') {
        $err = 'unable to start: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd);
    }

    return ['code' => $code, 'out' => $out, 'err' => $err];
}

/**
 * @return list<string>
 */
function powcLines(string $text): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $text)),
        static fn(string $line): bool => $line !== '',
    ));
}

// --------------------------------------------------------------------------
// Ledger
// --------------------------------------------------------------------------

/** Escapes a free-text value so it survives inside one markdown table cell. */
function powcCell(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace('|', '\\|', $text);
    $text = str_replace("\n", '<br>', $text);

    // Control characters (ESC, FF, NUL, ...) must never reach a committed file:
    // they would let a description inject ANSI escapes into findings.md.
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

    if (!is_string($stripped)) {
        $stripped = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    return trim($stripped);
}

function powcUncell(string $text): string
{
    return str_replace(['\\|', '<br>'], ['|', "\n"], $text);
}

/**
 * Parses findings.md in file order.
 *
 * A `|`-line that is neither the header, nor the separator, nor a well-formed
 * seven-cell row is reported in `errors` rather than skipped. Skipping it is
 * how a finding disappears without a word: a six-cell row used to make an
 * `open` finding invisible to `pow --status`, to the duplicate check and to the
 * gate's POW-03 at the same time.
 *
 * @return array{rows: list<array{id: string, round: int, loc: string, desc: string, severity: string, status: string, resolution: string}>, errors: list<string>}
 */
function powcParseLedger(string $text): array
{
    $rows = [];
    $errors = [];
    $number = 0;

    foreach (explode("\n", $text) as $line) {
        $number++;
        $line = trim($line);

        if (!str_starts_with($line, '|')) {
            continue;
        }

        $cells = preg_split('/(?<!\\\\)\|/', $line);

        // "| a | b |" splits into ['', ' a ', ' b ', ''] — two sentinels.
        if ($cells === false || count($cells) !== POWC_LEDGER_COLUMNS + 2) {
            $errors[] = sprintf(
                'line %d: a ledger row has %d cells, not %d: %s',
                $number,
                $cells === false ? 0 : max(0, count($cells) - 2),
                POWC_LEDGER_COLUMNS,
                $line,
            );

            continue;
        }

        $cells = array_map('trim', array_slice($cells, 1, POWC_LEDGER_COLUMNS));

        if ($cells[0] === 'ID' || str_starts_with($cells[0], '---')) {
            continue;
        }

        if ($cells[0] === '') {
            $errors[] = sprintf('line %d: a ledger row has an empty ID cell: %s', $number, $line);

            continue;
        }

        $rows[] = [
            'id' => $cells[0],
            'round' => (int) $cells[1],
            'loc' => powcUncell($cells[2]),
            'desc' => powcUncell($cells[3]),
            'severity' => $cells[4],
            'status' => $cells[5],
            'resolution' => powcUncell($cells[6]),
        ];
    }

    return ['rows' => $rows, 'errors' => $errors];
}

/**
 * Effective (last-row) status per finding ID, in first-seen order.
 *
 * @param list<array{id: string, round: int, loc: string, desc: string, severity: string, status: string, resolution: string}> $rows
 *
 * @return array<string, array{first_round: int, status: string, loc: string, desc: string, severity: string}>
 */
function powcLedgerState(array $rows): array
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
 * @param array<string, array{first_round: int, status: string, loc: string, desc: string, severity: string}> $state
 *
 * @return list<string>
 */
function powcOpenIds(array $state): array
{
    $open = [];

    foreach ($state as $id => $entry) {
        if ($entry['status'] === 'open') {
            $open[] = (string) $id;
        }
    }

    natsort($open);

    return array_values($open);
}

/** Word-boundary match, so naming F-10 does not also justify F-1. */
function powcMentionsId(string $text, string $id): bool
{
    return preg_match('/(?<![A-Za-z0-9_-])' . preg_quote($id, '/') . '(?![A-Za-z0-9_-])/', $text) === 1;
}

// --------------------------------------------------------------------------
// Completeness — the rule the recorder enforces at --finish and the gate at POW-03
// --------------------------------------------------------------------------

/**
 * Everything that makes a cycle "not finished yet", in one list.
 *
 * The recorder calls this from `--finish` and refuses to publish; the gate
 * calls it from POW-03 and reports each entry as `incomplete`. They must agree
 * — when they did not, a three-byte `escalation.md` satisfied the gate while
 * the recorder demanded a justification per open finding.
 *
 * @param list<string> $openIds
 *
 * @return list<string>
 */
function powcCompletenessProblems(
    string $profile,
    int $roundCount,
    bool $ledgerPresent,
    ?int $lintExit,
    ?int $testExit,
    ?string $verdict,
    string $escalationText,
    array $openIds,
): array {
    $problems = [];
    $minimum = POWC_MIN_ROUNDS[$profile] ?? POWC_MIN_ROUNDS['full'];

    if ($roundCount < $minimum) {
        $problems[] = sprintf('only %d round(s) recorded, the %s profile needs at least %d', $roundCount, $profile, $minimum);
    }

    if (!$ledgerPresent) {
        $problems[] = 'findings.md is missing or empty';
    }

    if ($lintExit === null) {
        $problems[] = 'lint_exit is not set (pow.php --set lint_exit=<code>)';
    }

    if ($testExit === null) {
        $problems[] = 'test_exit is not set (pow.php --set test_exit=<code>)';
    }

    if ($verdict === null) {
        $problems[] = 'no verdict recorded (pow.php --verdict=<' . implode('|', POWC_VERDICTS) . '>)';
    }

    $hasEscalation = trim($escalationText) !== '';

    if ($openIds !== []) {
        if ($verdict === 'CLEAN') {
            $problems[] = 'verdict CLEAN but the ledger still has open findings: ' . implode(', ', $openIds);
        }

        if (!$hasEscalation && ($verdict === null || $verdict === 'CLEAN')) {
            $problems[] = 'open findings (' . implode(', ', $openIds) . ') with no escalation.md justifying them';
        }
    }

    foreach (powcEscalationProblems($verdict, $escalationText, $openIds) as $problem) {
        $problems[] = $problem;
    }

    return $problems;
}

/**
 * The escalation rules of a verdict on their own, so `--verdict` can enforce
 * them at record time as well as at `--finish`.
 *
 * @param list<string> $openIds
 *
 * @return list<string>
 */
function powcEscalationProblems(?string $verdict, string $escalationText, array $openIds): array
{
    if ($verdict === null || $verdict === 'CLEAN') {
        return [];
    }

    if (trim($escalationText) === '') {
        return ['verdict ' . $verdict . ' requires a non-empty escalation.md'];
    }

    if ($verdict !== 'ACCEPT') {
        return [];
    }

    $unjustified = array_values(array_filter(
        $openIds,
        static fn(string $id): bool => !powcMentionsId($escalationText, $id),
    ));

    return $unjustified === [] ? [] : ['ACCEPT with unjustified findings: ' . implode(', ', $unjustified)];
}
