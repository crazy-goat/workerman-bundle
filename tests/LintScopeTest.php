<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LintScopeTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__);
    }

    public function testBinInLintScope(): void
    {
        $phpstan = (string) file_get_contents($this->projectDir . '/phpstan.neon.dist');
        $this->assertMatchesRegularExpression('/^\s*-\s+bin\s*$/m', $phpstan, 'phpstan.neon.dist must list bin/ in its paths:');

        $csFixer = (string) file_get_contents($this->projectDir . '/.php-cs-fixer.dist.php');
        $this->assertStringContainsString("__DIR__ . '/bin'", $csFixer, '.php-cs-fixer.dist.php must include bin/ in its Finder');

        $rector = (string) file_get_contents($this->projectDir . '/rector.php');
        $this->assertStringContainsString("__DIR__ . '/bin'", $rector, 'rector.php must include bin/ in withPaths()');
    }

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
     * @return list<string>
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
