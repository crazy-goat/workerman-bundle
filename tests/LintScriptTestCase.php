<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

abstract class LintScriptTestCase extends TestCase
{
    protected string $sandbox = '';

    protected string $script = '';

    abstract protected function getScriptName(): string;

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
     * @param array<string, string> $env
     * @param list<string>          $args
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
