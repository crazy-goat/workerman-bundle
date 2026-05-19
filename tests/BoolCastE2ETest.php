<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

final class BoolCastE2ETest extends TestCase
{
    public function testBoolCastMatchesBoolvalBehavior(): void
    {
        $autoloadPath = \realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoloadPath === false) {
            self::markTestSkipped('vendor/autoload.php not found.');
        }

        $scriptFile = __DIR__ . '/Fixtures/bool_cast_e2e_runner.php';

        $exitCode = $this->runPhpScript(
            $scriptFile,
            [$autoloadPath],
        );

        self::assertSame(
            0,
            $exitCode,
            '(bool) cast should behave identically to boolval() for all input types',
        );
    }

    /**
     * @param string[] $args
     */
    private function runPhpScript(string $scriptFile, array $args): int
    {
        $command = \array_values(['php', $scriptFile, ...$args]);
        $proc = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($proc)) {
            $this->fail('Failed to start subprocess.');
        }

        \fclose($pipes[0]);
        \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        if ($exitCode !== 0 && $stderr !== '' && $stderr !== false) {
            \fwrite(\STDERR, "Subprocess stderr: " . $stderr);
        }

        return $exitCode;
    }
}
