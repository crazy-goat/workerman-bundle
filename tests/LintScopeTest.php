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

    public function testCognitiveComplexityGateIsWiredAndOnlyRatchetsDown(): void
    {
        $phpstan = (string) file_get_contents($this->projectDir . '/phpstan.neon.dist');

        $this->assertMatchesRegularExpression(
            '#^includes:\s*\n(?:\s+-\s+\S+\s*\n)*\s+-\s+vendor/tomasvotruba/cognitive-complexity/config/extension\.neon\s*$#m',
            $phpstan,
            'phpstan.neon.dist must include the cognitive-complexity extension explicitly (phpstan/extension-installer is not installed)',
        );
        $this->assertMatchesRegularExpression('/^\s+cognitive_complexity:\s*$/m', $phpstan);
        $this->assertSame(1, preg_match('/^\s+function:\s*(\d+)\s*$/m', $phpstan, $function), 'cognitive_complexity.function must be set');
        $this->assertSame(1, preg_match('/^\s+class:\s*(\d+)\s*$/m', $phpstan, $class), 'cognitive_complexity.class must be set');
        $this->assertLessThanOrEqual(50, (int) ($function[1] ?? \PHP_INT_MAX), 'The function complexity limit only goes down');
        $this->assertLessThanOrEqual(150, (int) ($class[1] ?? \PHP_INT_MAX), 'The class complexity limit only goes down');
        $this->assertStringNotContainsStringIgnoringCase('baseline', $phpstan, 'No PHPStan baseline: violations are refactored, not recorded');
        $this->assertStringNotContainsString('complexity.', $phpstan, 'No complexity.* identifiers may be ignored');
        $this->assertDoesNotMatchRegularExpression('/[Cc]ognitive complexity/', $phpstan, 'No complexity message may be ignored');

        $composer = json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);
        $this->assertSame('1.3.0', $composer['require-dev']['tomasvotruba/cognitive-complexity'] ?? null, 'tomasvotruba/cognitive-complexity is pinned to an exact version');
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
