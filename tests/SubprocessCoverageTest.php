<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversNothing]
final class SubprocessCoverageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!$this->hasCoverageDriver()) {
            self::markTestSkipped('Needs pcov or Xdebug in coverage mode');
        }

        $this->directory = sys_get_temp_dir() . '/wmb-subprocess-coverage-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0777, true));
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->directory);
        }
    }

    public function testFixtureWritesCoverageAndMergeProducesClover(): void
    {
        $previous = getenv('WMB_SUBPROCESS_COVERAGE_DIR');
        putenv('WMB_SUBPROCESS_COVERAGE_DIR=' . $this->directory);

        try {
            exec(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/coverage_probe.php') . ' 2>&1',
                $output,
                $exitCode,
            );
        } finally {
            if ($previous === false) {
                putenv('WMB_SUBPROCESS_COVERAGE_DIR');
            } else {
                putenv('WMB_SUBPROCESS_COVERAGE_DIR=' . $previous);
            }
        }

        self::assertSame(0, $exitCode, implode("\n", $output));
        $files = glob($this->directory . '/*.cov') ?: [];
        self::assertCount(1, $files, 'The fixture must write exactly one coverage file');

        $mainDirectory = $this->directory . '/main';
        self::assertTrue(mkdir($mainDirectory));
        $main = $mainDirectory . '/main.cov';
        self::assertTrue(copy($files[0], $main));

        $clover = $mainDirectory . '/coverage.xml';
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/merge-coverage.php')
            . ' ' . escapeshellarg($main)
            . ' ' . escapeshellarg($this->directory)
            . ' ' . escapeshellarg($clover) . ' 2>&1',
            $mergeOutput,
            $mergeExitCode,
        );

        self::assertSame(0, $mergeExitCode, implode("\n", $mergeOutput));
        self::assertStringContainsString('Merged 1 subprocess coverage file(s)', implode("\n", $mergeOutput));
        self::assertFileExists($clover);

        $xml = simplexml_load_file($clover);
        self::assertNotFalse($xml);
        $metrics = $xml->xpath('/coverage/project/metrics');
        self::assertIsArray($metrics);
        self::assertNotEmpty($metrics);
        self::assertGreaterThan(0, (int) $metrics[0]['coveredstatements']);
    }

    public function testMergeRejectsAMissingMainFile(): void
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/merge-coverage.php')
            . ' ' . escapeshellarg($this->directory . '/missing.cov')
            . ' ' . escapeshellarg($this->directory)
            . ' ' . escapeshellarg($this->directory . '/coverage.xml') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('not readable', implode("\n", $output));
    }

    private function hasCoverageDriver(): bool
    {
        if (extension_loaded('pcov')) {
            return (bool) ini_get('pcov.enabled');
        }

        if (!extension_loaded('xdebug')) {
            return false;
        }

        if (in_array('coverage', explode(',', (string) getenv('XDEBUG_MODE')), true)) {
            return true;
        }

        return in_array('coverage', explode(',', (string) ini_get('xdebug.mode')), true);
    }
}
