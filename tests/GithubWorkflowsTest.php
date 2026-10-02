<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class GithubWorkflowsTest extends TestCase
{
    private const WORKFLOW_FILE = __DIR__ . '/../.github/workflows/tests.yaml';

    private string $workflowContent;

    protected function setUp(): void
    {
        self::assertFileExists(self::WORKFLOW_FILE);

        $content = file_get_contents(self::WORKFLOW_FILE);
        self::assertNotFalse($content);

        $this->workflowContent = $content;
    }

    public function testLintJobPinsPhpVersion(): void
    {
        $this->assertStringContainsString(
            "php-version: '8.2'",
            $this->workflowContent,
            'Lint job must pin PHP version to 8.2 for deterministic lint results',
        );
    }

    public function testLintJobSpecifiesComposerVersion(): void
    {
        $this->assertStringContainsString(
            'tools: composer:v2',
            $this->workflowContent,
            'Lint job must specify tools: composer:v2 for deterministic composer behavior',
        );
    }

    public function testLintAndBenchmarkInstallLockedDependencies(): void
    {
        foreach (['lint', 'benchmark'] as $job) {
            $content = $this->jobContent($job);
            $this->assertSame(1, substr_count($content, 'run: composer install --no-interaction --prefer-dist'));
            $this->assertStringNotContainsString('composer update', $content);
            $this->assertStringNotContainsString('sed -i', $content);
            $this->assertMatchesRegularExpression(
                '/run: composer install --no-interaction --prefer-dist.*run: (?:bin\/lint\.sh|composer bench)\b/s',
                $content,
                'Locked dependencies must be installed before running tools',
            );
        }
    }

    public function testTestsJobResolvesFreshDependenciesAfterMatrixRewrite(): void
    {
        foreach (['tests'] as $job) {
            $content = $this->jobContent($job);
            $this->assertSame(1, substr_count($content, "run: composer update --no-interaction --prefer-dist\n"));
            $this->assertStringNotContainsString('composer install', $content);
            $this->assertMatchesRegularExpression(
                '/export SYMFONY_VERSION=.*sed -i[^\n]+composer\.json.*run: composer update --no-interaction --prefer-dist\n.*run: composer test(?:\:coverage)?\n/s',
                $content,
                'Each matrix job must rewrite constraints, fully update dependencies, then run tests',
            );
        }
    }

    private function jobContent(string $job): string
    {
        $matched = preg_match(
            '/^  ' . preg_quote($job, '/') . ':\n.*?(?=^  [\\w-]+:\n|\z)/ms',
            $this->workflowContent,
            $matches,
        );
        $this->assertSame(1, $matched, 'Workflow job must exist: ' . $job);

        return $matches[0];
    }

    public function testTestsJobUsesMatrixPhpVersion(): void
    {
        $this->assertStringContainsString(
            'php-version: ${{ matrix.php-version }}',
            $this->workflowContent,
            'Tests job must use the matrix php-version variable',
        );
    }

    /**
     * Marking a draft ready and pushing a moment later once left two full
     * matrices — eighteen legs — running against the same pull request, and
     * nothing cancelled the one that was already obsolete. Since #597 the
     * group is per-ref and cancellation applies to pull requests only: a
     * push-triggered master run is never cancelled by a later run, so a
     * post-merge failure stays visible.
     */
    public function testASupersededRunIsCancelledInsteadOfFinishing(): void
    {
        $this->assertMatchesRegularExpression(
            '/^concurrency:\n  group: .+\n  cancel-in-progress: \${{ github\.event_name == \'pull_request\' }}$/m',
            $this->workflowContent,
            'Overlapping runs on one pull request must cancel the older one',
        );

        $this->assertMatchesRegularExpression(
            '/^  group: \${{ github\.workflow }}-\${{ github\.event\.pull_request\.number \|\| github\.sha }}$/m',
            $this->workflowContent,
            'The group must be per pull request (or per commit), or one PR would cancel another PR run',
        );
    }

    public function testWorkflowRunsOnMasterPushScheduleAndDispatch(): void
    {
        $this->assertMatchesRegularExpression(
            '/^on:\n  pull_request:\n  push:\n    branches: \[master\]/m',
            $this->workflowContent,
            'The workflow must run on pull requests and on every push to master',
        );

        $this->assertMatchesRegularExpression(
            '/^  schedule:\n    - cron: \'((0?[1-9])|([1-5][0-9])) \d+ \* \* \d+/m',
            $this->workflowContent,
            'A weekly scheduled run must exist, at a quiet minute not on the top of the hour',
        );

        $this->assertStringContainsString(
            '  workflow_dispatch:',
            $this->workflowContent,
            'Maintainers must be able to start a run manually via workflow_dispatch',
        );
    }

    public function testCodeJobsAreGatedOnTheChangesJob(): void
    {
        foreach (['lint', 'tests', 'tests-root-permissions', 'benchmark'] as $job) {
            $this->assertStringContainsString(
                "if: needs.changes.outputs.code == 'true'",
                $this->jobContent($job),
                $job . ' must be skipped when only documentation changed',
            );
        }

        $this->assertMatchesRegularExpression(
            '/^  tests:\n    name: Tests\n    runs-on: ubuntu-latest\n    needs: \[changes, lint\]\n/m',
            $this->workflowContent,
            'The tests matrix must wait for the changes and lint jobs',
        );
    }

    /**
     * Issue #859: lint runs through bin/lint.sh only, so a CI run and a local run
     * execute the same checks, and the shell tools are pinned.
     */
    public function testLintJobRunsOnlyTheLintScriptWithPinnedTools(): void
    {
        $content = $this->jobContent('lint');

        $this->assertSame(1, substr_count($content, 'run: bin/lint.sh'));
        $this->assertStringNotContainsString('composer lint', $content);
        $this->assertStringContainsString('shellcheck/releases/download/v0.11.0/', $content);
        $this->assertStringContainsString('hadolint/releases/download/v2.12.0/', $content);
        $this->assertMatchesRegularExpression(
            '/^env:\n  COMPOSER_AUTH: /m',
            $this->workflowContent,
            'COMPOSER_AUTH must be set at workflow level so every composer call is authenticated',
        );
    }

    /**
     * Documentation-only changes skip the heavy jobs. The reusable `changes` job
     * classifies the diff with a deliberately narrow docs regex (tests read every
     * Markdown file), and `docs` runs always. `ci-ok` treats skipped as green only
     * when no code changed.
     */
    public function testDocsOnlyChangeSkipsHeavyJobsButKeepsCiOk(): void
    {
        $this->assertMatchesRegularExpression(
            '/^  changes:\n    name: Changes\n    uses: crazy-goat\/\.github\/\.github\/workflows\/changes\.yml@main\n    with:\n.*?docs-regex: /ms',
            $this->workflowContent,
        );
        $this->assertMatchesRegularExpression(
            '/^  docs:\n    name: Docs\n    uses: crazy-goat\/\.github\/\.github\/workflows\/docs-check\.yml@main$/m',
            $this->workflowContent,
        );

        $ciOk = $this->jobContent('ci-ok');
        $this->assertStringContainsString('if: always()', $ciOk);
        $this->assertStringContainsString('needs: [changes, docs, lint, tests, tests-root-permissions]', $ciOk);
        $this->assertStringContainsString('if [ "$CODE" = "true" ]', $ciOk);
    }

    /**
     * Issue #760: the three root-only ConfigLoader permission cases skip on
     * a non-root runner. A dedicated job must run them under sudo and be
     * required by the ci aggregator, or the guard paths silently stop being
     * exercised in CI.
     */
    public function testRootOnlyPermissionTestsRunAsRootAndGateCi(): void
    {
        $content = $this->jobContent('tests-root-permissions');

        $this->assertStringContainsString(
            'sudo "$php_bin" vendor/bin/phpunit',
            $content,
            'The root-only permission tests must execute through sudo',
        );
        $this->assertStringContainsString(
            'Skipped: [1-9]',
            $content,
            'The root-only job must fail when its tests skip instead of passing silently',
        );

        $this->assertStringContainsString(
            'needs: [changes, docs, lint, tests, tests-root-permissions]',
            $this->jobContent('ci-ok'),
            'The ci-ok aggregator must require the root-only permission job',
        );
    }

    public function testScheduledRunFailureOpensAnIssue(): void
    {
        $this->assertMatchesRegularExpression(
            '/^      - name: Open issue on scheduled failure\n        if: failure\(\) && github\.event_name == \'schedule\'/m',
            $this->workflowContent,
            'A failing scheduled run must produce a visible signal: an issue',
        );

        $this->assertMatchesRegularExpression(
            '/^    permissions:\n      contents: read\n      issues: write$/m',
            $this->workflowContent,
            'The ci-ok job must hold issues: write for the issue opener',
        );

        $this->assertStringContainsString(
            'marker="Scheduled CI run failed"',
            $this->workflowContent,
            'The issue opener must deduplicate open issues by a title marker',
        );
    }
}
