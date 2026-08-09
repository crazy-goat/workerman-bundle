<?php

declare(strict_types=1);

/**
 * Picks the most impactful GitHub issues to work on next.
 *
 * Lists open milestones, selects the lowest one (by semver), scores its
 * open issues, and prints the top N candidates so a human or an LLM can
 * make the final pick. Keeps triage cheap: bodies/comments are never
 * fetched, only titles, labels, age and comment counts.
 *
 * Usage: php bin/pick-issue.php [options]
 *
 * Options:
 *   --repo=owner/name   GitHub repository (default: crazy-goat/workerman-bundle)
 *   --milestone=X       score issues from this milestone instead of the lowest
 *   --top=N             how many candidates to show (default: 5, 0 = all)
 *   --json              machine-readable output (JSON on stdout)
 *   --help              show this help
 *
 * Exit codes:
 *   0  candidates printed
 *   1  gh / API error
 *   2  usage error (bad option, unknown milestone)
 *   3  RELEASE NEEDED: the target milestone has no open issues left —
 *      stop the workflow, cut the release, close the milestone, re-run
 *
 * Release rule: the workflow works milestone-by-milestone, lowest first.
 * An empty milestone ends the picking loop — do not silently move to the
 * next one. Cut a release for the finished milestone, close it, then the
 * next run will pick the next one.
 *
 * Scoring (additive, all components shown in the breakdown):
 *   - type labels:    bug=50, security=45, enhancement=20,
 *                     technical-debt=15, code-quality=10, documentation=8
 *   - priority labels: critical=60, high=30, medium=12, minor=3
 *   - meta labels:    good first issue=+10, help wanted=+8, need review=-5
 *   - title signals:  leak=25, crash/segfault/fatal=30, security=20,
 *                     performance=15, dead code=5
 *   - age:            +0.2 per day since creation, capped at 20
 *   - comments:       +1 per comment, capped at 5 (demand signal)
 *
 * Requires the `gh` CLI to be installed and authenticated.
 */

const DEFAULT_REPO = 'crazy-goat/workerman-bundle';

const TYPE_WEIGHTS = [
    'bug' => 50,
    'security' => 45,
    'enhancement' => 20,
    'technical-debt' => 15,
    'code-quality' => 10,
    'documentation' => 8,
];

const PRIORITY_WEIGHTS = [
    'critical' => 60,
    'high' => 30,
    'medium' => 12,
    'minor' => 3,
];

const META_WEIGHTS = [
    'good first issue' => 10,
    'help wanted' => 8,
    'need review' => -5,
];

/** @var list<array{pattern: string, points: int, name: string}> */
const TITLE_SIGNALS = [
    ['pattern' => '/(?:memory )?leak|leak[s]?\b/i', 'points' => 25, 'name' => 'leak'],
    ['pattern' => '/crash|segfault|fatal|panic|corrupt/i', 'points' => 30, 'name' => 'crash-risk'],
    ['pattern' => '/security|auth(?:entication|orization)?|xss|csrf|injection/i', 'points' => 20, 'name' => 'security'],
    ['pattern' => '/performance|perf\b|benchmark/i', 'points' => 15, 'name' => 'performance'],
    ['pattern' => '/dead code|unused|never throw/i', 'points' => 5, 'name' => 'dead-code'],
];

// Picked milestone has no open issues -> stop the workflow, release time.
const EXIT_RELEASE_NEEDED = 3;

const MAX_AGE_POINTS = 20;
const MAX_COMMENT_POINTS = 5;

/**
 * Runs a `gh api` call and returns the decoded JSON payload.
 *
 * Calls use `--paginate --slurp`: gh emits one JSON array per page and
 * wraps them in an outer array. The payload is flattened back to a list;
 * callers project the fields they need immediately after.
 *
 * @param list<string> $args arguments after `gh api`
 */
function ghApi(array $args): mixed
{
    $cmd = 'gh api ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $lines, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, 'gh api failed: ' . implode("\n", $lines) . "\n");
        exit(1);
    }

    try {
        $data = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, 'Unable to parse gh api response: ' . $e->getMessage() . "\n");
        exit(1);
    }

    // With --slurp the payload is an array of pages ([page1, page2, ...]).
    if (isset($data[0]) && is_array($data[0]) && array_is_list($data[0])) {
        $merged = [];
        foreach ($data as $page) {
            foreach ($page as $item) {
                $merged[] = $item;
            }
        }

        return $merged;
    }

    return $data;
}

/**
 * SemVer 2.0 precedence comparison; build metadata does not affect order.
 * Non-semver titles (e.g. "Backlog") fall back to natural string order.
 */
function compareVersions(string $a, string $b): int
{
    $pattern = '/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/';
    if (preg_match($pattern, $a, $ma) !== 1 || preg_match($pattern, $b, $mb) !== 1) {
        return strnatcasecmp($a, $b);
    }

    foreach ([1, 2, 3] as $i) {
        if ((int) $ma[$i] !== (int) $mb[$i]) {
            return (int) $ma[$i] <=> (int) $mb[$i];
        }
    }

    $preA = $ma[4] ?? null;
    $preB = $mb[4] ?? null;

    if ($preA === null && $preB === null) {
        return 0;
    }
    if ($preA === null) {
        return 1; // release > prerelease
    }
    if ($preB === null) {
        return -1;
    }

    $identifiersA = explode('.', $preA);
    $identifiersB = explode('.', $preB);
    $count = max(count($identifiersA), count($identifiersB));

    for ($i = 0; $i < $count; $i++) {
        $xA = $identifiersA[$i] ?? null;
        $xB = $identifiersB[$i] ?? null;

        if ($xA === null) {
            return -1; // fewer identifiers = lower precedence
        }
        if ($xB === null) {
            return 1;
        }

        $numericA = ctype_digit($xA);
        $numericB = ctype_digit($xB);

        if ($numericA && $numericB) {
            if ((int) $xA !== (int) $xB) {
                return (int) $xA <=> (int) $xB;
            }

            continue;
        }
        if ($numericA !== $numericB) {
            return $numericA ? -1 : 1; // numeric identifiers < alphanumeric
        }
        if ($xA !== $xB) {
            return strcmp($xA, $xB); // ASCII lexicographic order
        }
    }

    return 0;
}

/**
 * @param list<array<string, mixed>> $milestones
 */
function sortMilestones(array $milestones): array
{
    $versionLike = [];
    $other = [];

    foreach ($milestones as $milestone) {
        if (preg_match('/^v?\d+(?:\.\d+)*/', (string) ($milestone['title'] ?? ''))) {
            $versionLike[] = $milestone;
        } else {
            $other[] = $milestone;
        }
    }

    usort($versionLike, static fn(array $a, array $b): int => compareVersions(
        (string) ($a['title'] ?? ''),
        (string) ($b['title'] ?? ''),
    ));
    usort($other, static fn(array $a, array $b): int => strnatcasecmp(
        (string) ($a['title'] ?? ''),
        (string) ($b['title'] ?? ''),
    ));

    return [...$versionLike, ...$other];
}

/**
 * Label names of an issue, tolerant of both the raw GitHub shape
 * ([{name: ...}]) and the projected shape (["bug", ...]).
 *
 * @param array<string, mixed> $issue
 *
 * @return list<string>
 */
function labelNames(array $issue): array
{
    $names = [];

    foreach ($issue['labels'] ?? [] as $label) {
        if (is_string($label)) {
            $names[] = $label;
        } elseif (is_array($label) && isset($label['name'])) {
            $names[] = (string) $label['name'];
        }
    }

    return $names;
}

/**
 * @param array<string, mixed> $issue
 *
 * @return array{score: int, breakdown: array<string, int>}
 */
function scoreIssue(array $issue): array
{
    $labelNames = labelNames($issue);

    $breakdown = [];

    // Type: first (most valuable) matching label wins.
    foreach (TYPE_WEIGHTS as $label => $weight) {
        if (in_array($label, $labelNames, true)) {
            $breakdown['type:' . $label] = $weight;
            break;
        }
    }

    // Priority and meta labels: all matches contribute.
    foreach (PRIORITY_WEIGHTS as $label => $weight) {
        if (in_array($label, $labelNames, true)) {
            $breakdown['prio:' . $label] = $weight;
        }
    }
    foreach (META_WEIGHTS as $label => $weight) {
        if (in_array($label, $labelNames, true)) {
            $breakdown['meta:' . $label] = $weight;
        }
    }

    // Title signals.
    $title = (string) ($issue['title'] ?? '');
    foreach (TITLE_SIGNALS as $signal) {
        if (preg_match($signal['pattern'], $title) === 1) {
            $breakdown['title:' . $signal['name']] = $signal['points'];
        }
    }

    // Age: older issues deserve attention, capped.
    $days = ageDays($issue);
    $agePoints = min(MAX_AGE_POINTS, (int) floor($days * 0.2));
    if ($agePoints > 0) {
        $breakdown['age'] = $agePoints;
    }

    // Comments: demand signal, capped.
    $commentPoints = min(MAX_COMMENT_POINTS, (int) ($issue['comments'] ?? 0));
    if ($commentPoints > 0) {
        $breakdown['comments'] = $commentPoints;
    }

    return ['score' => array_sum($breakdown), 'breakdown' => $breakdown];
}

/**
 * Full days since the issue was created; 0 when the date is missing or
 * unparsable (never the Unix epoch).
 *
 * @param array<string, mixed> $issue
 */
function ageDays(array $issue): int
{
    $createdAt = $issue['created_at'] ?? null;
    $createdTs = is_string($createdAt) ? strtotime($createdAt) : false;

    if ($createdTs === false) {
        return 0;
    }

    return max(0, (int) floor((time() - $createdTs) / 86400));
}

/**
 * @param list<array<string, mixed>> $issues
 *
 * @return list<array<string, mixed>> scored, sorted, capped
 */
function rankIssues(array $issues, int $top): array
{
    foreach ($issues as &$issue) {
        $result = scoreIssue($issue);
        $issue['score'] = $result['score'];
        $issue['breakdown'] = $result['breakdown'];

        $parts = [];
        foreach ($result['breakdown'] as $name => $points) {
            $parts[] = sprintf('%s:%+d', $name, $points);
        }
        $issue['rationale'] = implode(' ', $parts);
    }
    unset($issue);

    usort($issues, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'] ?: $a['number'] <=> $b['number'];
    });

    return $top > 0 ? array_slice($issues, 0, $top) : $issues;
}

/** @param list<string> $args */
function printUsage(array $args): void
{
    fwrite(STDOUT, $args[0] . " — pick top GitHub issues to work on\n\n");
    fwrite(STDOUT, "Usage: php bin/pick-issue.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --repo=owner/name   GitHub repository (default: " . DEFAULT_REPO . ")\n");
    fwrite(STDOUT, "  --milestone=X       score issues from this milestone (default: lowest open)\n");
    fwrite(STDOUT, "  --top=N             how many candidates to show (default: 5, 0 = all)\n");
    fwrite(STDOUT, "  --json              machine-readable output (JSON on stdout)\n");
    fwrite(STDOUT, "  --help              show this help\n");
}

/** @param list<string> $argv */
function parseArgs(array $argv): array
{
    $options = [
        'repo' => DEFAULT_REPO,
        'milestone' => null,
        'top' => 5,
        'json' => false,
    ];

    $valueOptions = ['repo', 'milestone', 'top'];
    $fullArgv = $argv;
    $argv = array_slice($argv, 1);

    for ($i = 0; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            printUsage($fullArgv);
            exit(0);
        }

        if (!str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unknown argument: $arg (see --help)\n");
            exit(2);
        }

        $eqPos = strpos($arg, '=');
        if ($eqPos !== false) {
            $name = substr($arg, 2, $eqPos - 2);
            $value = substr($arg, $eqPos + 1);
        } else {
            $name = substr($arg, 2);
            $value = null;
        }

        if ($name === 'json') {
            if ($value === null) {
                $options['json'] = true;
            } else {
                $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed === null) {
                    fwrite(STDERR, "Invalid value for --json: $value (expected true/false)\n");
                    exit(2);
                }
                $options['json'] = $parsed;
            }

            continue;
        }

        if (!in_array($name, $valueOptions, true)) {
            fwrite(STDERR, "Unknown option: --$name (see --help)\n");
            exit(2);
        }

        // Valued options accept both --top=5 and --top 5 forms.
        if ($value === null) {
            if (!isset($argv[$i + 1])) {
                fwrite(STDERR, "Option --$name requires a value.\n");
                exit(2);
            }
            $value = $argv[++$i];
        }

        switch ($name) {
            case 'repo':
                $options['repo'] = (string) $value;
                break;
            case 'milestone':
                $options['milestone'] = (string) $value;
                break;
            case 'top':
                if (!ctype_digit((string) $value)) {
                    fwrite(STDERR, "Invalid value for --top: $value (expected a non-negative integer)\n");
                    exit(2);
                }
                $options['top'] = (int) $value;
                break;
        }
    }

    return $options;
}

/** @param array<string, mixed> $options */
function main(array $options): void
{
    $repo = (string) $options['repo'];
    $top = (int) $options['top'];
    $json = (bool) $options['json'];

    // 1. List open milestones.
    $milestones = ghApi([
        '--paginate',
        '--slurp',
        "repos/$repo/milestones?state=open&per_page=100",
    ]);
    $milestones = is_array($milestones) ? $milestones : [];

    if ($milestones === []) {
        fwrite(STDERR, "No open milestones found.\n");
        exit(1);
    }

    $milestones = sortMilestones($milestones);

    // 2. Pick the target milestone: explicit override or the lowest one.
    if ($options['milestone'] !== null) {
        $target = null;
        foreach ($milestones as $milestone) {
            if (($milestone['title'] ?? '') === $options['milestone']) {
                $target = $milestone;
                break;
            }
        }
        if ($target === null) {
            fwrite(STDERR, sprintf("Milestone \"%s\" not found among open milestones.\n", $options['milestone']));
            exit(2);
        }
    } else {
        $target = $milestones[0];
    }

    // 3. Release rule: an empty milestone ends the workflow — stop here.
    $targetTitle = (string) ($target['title'] ?? '');
    $targetOpen = (int) ($target['open_issues'] ?? 0);
    if ($targetOpen === 0) {
        $releaseNeeded = [
            'message' => sprintf(
                "Milestone %s is complete (0 open issues left). STOP the workflow — cut the release:\n" .
                "  1. Tag + publish the release (e.g. gh release create v%s)\n" .
                "  2. Close milestone %s\n" .
                "  3. Re-run this script to pick the next milestone\n",
                $targetTitle,
                ltrim($targetTitle, 'v'),
                $targetTitle,
            ),
            'milestone' => [
                'title' => $targetTitle,
                'open_issues' => 0,
                'closed_issues' => (int) ($target['closed_issues'] ?? 0),
            ],
        ];

        if ($json) {
            echo json_encode(['release_needed' => true] + $releaseNeeded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            fwrite(STDOUT, "RELEASE NEEDED — workflow stopped.\n\n" . $releaseNeeded['message']);
        }

        exit(EXIT_RELEASE_NEEDED);
    }

    // 4. Fetch open issues of the target milestone. GitHub's /issues endpoint
    //    also returns pull requests — they are filtered out below. The payload
    //    is projected immediately: bodies are dropped before anything is read.
    $issues = ghApi([
        '--paginate',
        '--slurp',
        sprintf('repos/%s/issues?state=open&milestone=%d&per_page=100', $repo, (int) ($target['number'] ?? 0)),
    ]);
    $issues = is_array($issues) ? $issues : [];
    $issues = array_map(static fn(array $issue): array => [
        'number' => isset($issue['number']) ? (int) $issue['number'] : null,
        'title' => (string) ($issue['title'] ?? ''),
        'labels' => array_map(
            static fn(array $label): string => (string) ($label['name'] ?? ''),
            $issue['labels'] ?? [],
        ),
        'created_at' => $issue['created_at'] ?? null,
        'comments' => (int) ($issue['comments'] ?? 0),
        'is_pr' => isset($issue['pull_request']),
    ], $issues);

    $issues = array_values(array_filter(
        $issues,
        static fn(array $issue): bool => !$issue['is_pr'] && $issue['number'] !== null,
    ));

    if ($issues === []) {
        fwrite(STDERR, sprintf(
            "Inconsistent API data: milestone %s reports %d open issue(s) but the issue request returned none.\n",
            $targetTitle,
            $targetOpen,
        ));
        exit(1);
    }

    $ranked = rankIssues($issues, $top);

    if ($json) {
        $payload = [
            'milestones' => array_map(
                static fn(array $m): array => [
                    'title' => (string) ($m['title'] ?? ''),
                    'open_issues' => (int) ($m['open_issues'] ?? 0),
                ],
                $milestones,
            ),
            'picked_milestone' => $targetTitle,
            'picked_reason' => $options['milestone'] !== null
                ? 'explicit --milestone override'
                : 'lowest open milestone by version',
            'top' => $top,
            'issues' => array_map(
                static fn(array $i): array => [
                    'number' => (int) $i['number'],
                    'title' => (string) ($i['title'] ?? ''),
                    'labels' => labelNames($i),
                    'score' => (int) $i['score'],
                    'rationale' => $i['rationale'],
                    'age_days' => ageDays($i),
                    'comments' => (int) ($i['comments'] ?? 0),
                ],
                $ranked,
            ),
        ];
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        return;
    }

    // Human-readable output.
    fwrite(STDOUT, "Open milestones:\n");
    foreach ($milestones as $milestone) {
        $marker = ($milestone['title'] ?? '') === (string) ($target['title'] ?? '') ? '  <-- picked' : '';
        fwrite(STDOUT, sprintf(
            "  %s (%d open, %d closed)%s\n",
            (string) ($milestone['title'] ?? ''),
            (int) ($milestone['open_issues'] ?? 0),
            (int) ($milestone['closed_issues'] ?? 0),
            $marker,
        ));
    }

    fwrite(STDOUT, sprintf(
        "\n%s — top %d of %d issue(s), ordered by score (highest first):\n\n",
        $targetTitle,
        min($top, count($issues)),
        count($issues),
    ));

    foreach ($ranked as $index => $issue) {
        $labelSummary = implode(', ', array_slice(labelNames($issue), 0, 3));

        fwrite(STDOUT, sprintf(
            "%2d. #%d  (%3d pts)  [%s] %s\n",
            $index + 1,
            (int) $issue['number'],
            (int) $issue['score'],
            $labelSummary !== '' ? $labelSummary : 'no labels',
            (string) $issue['title'],
        ));
        fwrite(STDOUT, "     " . (string) $issue['rationale'] . "\n");
    }

    $best = $ranked[0];
    fwrite(STDOUT, sprintf(
        "\nHighest-scoring candidate: #%d (%d pts) — %s\n",
        (int) $best['number'],
        (int) $best['score'],
        (string) $best['title'],
    ));
    fwrite(STDOUT, "Pick one of these, then run the workflow (docs/workflow.md).\n");
}

try {
    main(parseArgs($argv));
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}
