<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Shared sandbox/subprocess harness for the lint-script tests (issue #1046).
 *
 * The lint scripts (`bin/check-*.php`) are driven as subprocesses: the
 * passing case runs against the real tree, every failure scenario runs
 * against a synthetic fixture in a throw-away sandbox directory that is
 * removed after each test. This base class owns the duplicated harness
 * (`setUp()`/`tearDown()`, the `runScript()` subprocess runner, and the
 * recursive sandbox cleanup); subclasses only name their script and their
 * sandbox prefix and add their fixture helpers.
 */
abstract class LintScriptTestCase extends TestCase
{
    protected string $sandbox = '';

    protected string $script = '';

    /**
     * Basename of the script under `bin/` this test class drives,
     * e.g. `check-exception-usage.php`.
     */
    abstract protected function getScriptName(): string;

    /**
     * Prefix of the throw-away sandbox directory under the system temp
     * dir, e.g. `check-exception-usage-test-`.
     */
    abstract protected function getSandboxPrefix(): string;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = \dirname(__DIR__) . '/bin/' . $this->getScriptName();
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/' . $this->getSandboxPrefix() . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox, 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }

        parent::tearDown();
    }

    /**
     * Runs the script with the given arguments. The environment is inherited
     * from the current process (so that PATH, HOME, and any CI vars the
     * script might need are available), overlaid with any explicit overrides.
     *
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{code: int, out: string, err: string}
     */
    protected function runScript(array $env, array $args): array
    {
        $command = [\PHP_BINARY, $this->script, ...$args];

        $outFile = $this->sandbox . '/stdout.log';
        $errFile = $this->sandbox . '/stderr.log';

        $descriptors = [
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        $pipes = [];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->sandbox,
            [...getenv(), ...$env],
        );
        self::assertIsResource($process);

        $code = proc_close($process);

        return [
            'code' => $code,
            'out' => (string) file_get_contents($outFile),
            'err' => (string) file_get_contents($errFile),
        ];
    }

    protected function removeRecursively(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                if (!@rmdir($item->getPathname())) {
                    error_log(sprintf('%s removeRecursively: rmdir(%s) failed', static::class, $item->getPathname()));
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    error_log(sprintf('%s removeRecursively: unlink(%s) failed', static::class, $item->getPathname()));
                }
            }
        }

        if (!@rmdir($path)) {
            error_log(sprintf('%s removeRecursively: rmdir(%s) failed', static::class, $path));
        }
    }
}
