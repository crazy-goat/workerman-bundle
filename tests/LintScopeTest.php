<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Guards two repository invariants that are invisible on a case-insensitive
 * macOS/APFS checkout but break on the case-sensitive Linux CI:
 *
 *  1. `bin/` is in the lint scope of every tool that analyses source —
 *     phpstan.neon.dist, .php-cs-fixer.dist.php and rector.php (closes #635).
 *     Without this, PHP files in `bin/` are never type-checked or
 *     style-enforced, so regressions slip through.
 *
 *  2. No two tracked paths collide when compared case-insensitively. The
 *     motivating case was `tests/Fixtures/` (13 PHP classes referenced by
 *     PSR-4 autoload) coexisting with `tests/fixtures/` (1 static data file):
 *     on macOS they overlay into one directory, on Linux CI they are two
 *     separate trees and the autoload lookups for the PHP classes fail.
 *     The fix (#688) consolidated everything into `tests/Fixtures/` via
 *     `git mv`; this test ensures the collision cannot silently return.
 *
 * @coversNothing
 */
final class LintScopeTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__);
    }

    /**
     * `bin/` must appear in the `paths:` list of phpstan.neon.dist, in the
     * Finder `->in(...)` calls of .php-cs-fixer.dist.php, and in the
     * `withPaths(...)` array of rector.php. The assertion reads the actual
     * config files (not a parsed representation) so a stale or renamed
     * entry is caught immediately.
     */
    public function testBinInLintScope(): void
    {
        // phpstan.neon.dist — paths: list.
        $phpstan = (string) file_get_contents($this->projectDir . '/phpstan.neon.dist');
        $this->assertStringContainsString('- bin', $phpstan, 'phpstan.neon.dist must list bin/ in its paths:');

        // .php-cs-fixer.dist.php — Finder ->in(...) calls.
        $csFixer = (string) file_get_contents($this->projectDir . '/.php-cs-fixer.dist.php');
        $this->assertStringContainsString("__DIR__ . '/bin'", $csFixer, '.php-cs-fixer.dist.php must include bin/ in its Finder');

        // rector.php — withPaths(...) array.
        $rector = (string) file_get_contents($this->projectDir . '/rector.php');
        $this->assertStringContainsString("__DIR__ . '/bin'", $rector, 'rector.php must include bin/ in withPaths()');
    }

    /**
     * No two tracked paths may collide when lowercased. On a case-sensitive
     * filesystem (Linux CI) two paths that differ only in case are two
     * separate files/directories; on a case-insensitive filesystem (macOS)
     * they overlay, hiding the discrepancy. This test runs `git ls-files` and
     * flags every case-colliding pair so the collision is caught on every
     * platform, not just the CI that happens to be case-sensitive.
     */
    public function testNoCaseCollidingTrackedPaths(): void
    {
        $trackedPaths = $this->gitLsFiles();
        $this->assertNotEmpty($trackedPaths, 'git ls-files returned no tracked paths — the repo is empty or git is unavailable');

        $byLowercase = [];
        foreach ($trackedPaths as $path) {
            $key = strtolower($path);
            $byLowercase[$key][] = $path;
        }

        $collisions = [];
        foreach ($byLowercase as $key => $originals) {
            if (\count($originals) > 1) {
                $collisions[] = $key . ' → ' . implode(' vs ', $originals);
            }
        }

        $this->assertSame(
            [],
            $collisions,
            "Case-colliding tracked paths found (these overlay on case-insensitive macOS but are separate on Linux CI):\n" .
            implode("\n", $collisions),
        );
    }

    /**
     * @return list<string> relative paths of every tracked file
     */
    private function gitLsFiles(): array
    {
        $command = sprintf(
            'git -C %s ls-files',
            escapeshellarg($this->projectDir),
        );

        $stdout = '';
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process, 'git ls-files could not be executed');

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $lines = explode("\n", trim($stdout));

        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }
}
