<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ComposerAuditE2ETest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $projectDir = \realpath(__DIR__ . '/..');
        if ($projectDir === false) {
            self::fail('Cannot determine project root directory.');
        }
        $this->projectDir = $projectDir;
    }

    public function testComposerValidatePasses(): void
    {
        $command = 'composer validate --strict 2>&1';
        $output = $this->runCommand($command);

        self::assertStringContainsString(
            './composer.json is valid',
            $output,
            'composer.json must pass strict validation: ' . $output,
        );
    }

    public function testComposerAuditProducesValidJson(): void
    {
        $output = $this->runAuditCommand();

        $decoded = \json_decode($output, true);
        self::assertIsArray($decoded, 'composer audit --format=json must produce valid JSON');

        self::assertArrayHasKey('advisories', $decoded, 'Audit JSON must contain advisories key');
        self::assertArrayHasKey('abandoned', $decoded, 'Audit JSON must contain abandoned key');
    }

    public function testComposerAuditJsonHasAbandonedKey(): void
    {
        $output = $this->runAuditCommand();

        $decoded = \json_decode($output, true);
        self::assertIsArray($decoded, 'composer audit --format=json must produce valid JSON');

        self::assertArrayHasKey('abandoned', $decoded, 'Audit JSON must contain abandoned key');
        self::assertIsArray($decoded['abandoned'], 'abandoned value must be an array');
    }

    /**
     * @return iterable<string, array{bool, string, string}>
     */
    public static function networkErrorOutputs(): iterable
    {
        yield 'curl timeout from the issue' => [true, '', 'curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out after 10002 milliseconds'];
        yield 'DNS failure' => [true, '', 'curl error 6 while downloading https://packagist.org/: Could not resolve host: packagist.org'];
        yield 'failed to connect' => [true, '', 'Failed to connect to packagist.org port 443'];
        yield 'connection reset' => [true, '', 'Connection reset by peer'];
        yield 'marker in stdout, mixed case' => [true, 'Network is unreachable', ''];
        yield 'non-network error' => [false, '', 'In AuditCommand.php line 42: Undefined array key "advisories"'];
        yield 'empty output' => [false, '', ''];
    }

    #[DataProvider('networkErrorOutputs')]
    public function testNetworkErrorDetection(bool $expected, string $stdout, string $stderr): void
    {
        self::assertSame($expected, $this->isNetworkError($stdout, $stderr));
    }

    private function runCommand(string $command): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = \proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->projectDir,
        );

        if (!\is_resource($proc)) {
            self::fail('Failed to start command: ' . $command);
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        if ($exitCode !== 0) {
            $errorOutput = \is_string($stdout) ? $stdout : '';
            $errorStderr = \is_string($stderr) ? $stderr : '';
            self::fail(
                \sprintf(
                    "Command failed (exit code %d):\nstdout: %s\nstderr: %s",
                    $exitCode,
                    $errorOutput,
                    $errorStderr,
                ),
            );
        }

        return \is_string($stdout) ? $stdout : '';
    }

    private function runAuditCommand(): string
    {
        $command = 'composer audit --format=json --no-dev';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = \proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->projectDir,
        );

        if (!\is_resource($proc)) {
            self::fail('Failed to start composer audit');
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($proc);

        $stdout = \is_string($stdout) ? $stdout : '';
        $stderr = \is_string($stderr) ? $stderr : '';

        // composer audit exits 0 (clean) or 1 (advisories/abandoned found)
        if ($exitCode !== 0 && $exitCode !== 1) {
            if ($this->isNetworkError($stdout, $stderr)) {
                self::markTestSkipped(\sprintf(
                    'Skipping composer audit: could not reach packagist.org (exit code %d): %s %s',
                    $exitCode,
                    \trim($stdout),
                    \trim($stderr),
                ));
            }

            self::fail(\sprintf(
                "composer audit failed unexpectedly (exit code %d):\nstdout: %s\nstderr: %s",
                $exitCode,
                $stdout,
                $stderr,
            ));
        }

        if ($stdout === '') {
            self::fail(\sprintf(
                "composer audit produced no output (exit code %d):\nstderr: %s",
                $exitCode,
                $stderr,
            ));
        }

        return $stdout;
    }

    /**
     * Detects composer/packagist network failures (timeouts, DNS, refused or
     * reset connections) so the audit tests skip instead of failing on them.
     */
    private function isNetworkError(string $stdout, string $stderr): bool
    {
        $haystack = \strtolower($stdout . "\n" . $stderr);

        $markers = [
            'curl error',
            'connection timed out',
            'could not resolve host',
            'failed to connect',
            'connection refused',
            'connection reset',
            'network is unreachable',
            'operation timed out',
        ];

        foreach ($markers as $marker) {
            if (\str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
