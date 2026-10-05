<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/check-upgrade-exceptions.php` as a subprocess (issue #817).
 *
 * The script is the single source of truth for the rule "the
 * exception-hierarchy tree in UPGRADE.md matches the types declared in
 * src/Exception/", and `composer lint` runs it directly — so the pre-push
 * hook and the CI Lint job enforce exactly what these tests assert. The
 * passing case runs against the real tree; every failure scenario runs
 * against a synthetic fixture in a throw-away sandbox.
 *
 * @coversNothing
 */
final class UpgradeExceptionsLintTest extends TestCase
{
    private string $sandbox = '';

    private string $script = '';

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__) . '/bin/check-upgrade-exceptions.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/check-upgrade-exceptions-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox, 0o775, true));
        $this->sandbox = $sandbox;
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $this->removeRecursively($this->sandbox);
        }
    }

    public function testTheRealTreePassesWithNoArguments(): void
    {
        $result = $this->runScript([], []);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('check-upgrade-exceptions: OK', $result['out']);
        self::assertSame('', $result['err']);
    }

    public function testATypeMissingFromTheDocTreeFails(): void
    {
        $this->writeFixture(
            [
                'BaseException' => "abstract class BaseException extends \\RuntimeException\n{\n}\n",
                'ChildException' => "final class ChildException extends BaseException\n{\n}\n",
            ],
            <<<'TREE'
                BaseException
                └── ChildException
                TREE,
        );

        $this->writeException('BaseException', "abstract class BaseException extends \\RuntimeException\n{\n}\n");
        $this->writeException('ForgottenException', "final class ForgottenException extends BaseException\n{\n}\n");

        $result = $this->runChecked();

        self::assertStringContainsString('ForgottenException is declared in src/Exception but missing from the UPGRADE.md exception-hierarchy tree', $result['err']);
    }

    public function testAnExtraDocEntryFails(): void
    {
        $this->writeFixture(
            ['BaseException' => "abstract class BaseException extends \\RuntimeException\n{\n}\n"],
            <<<'TREE'
                BaseException
                ├── GhostException
                TREE,
        );

        $result = $this->runChecked();

        self::assertStringContainsString('GhostException is listed in the UPGRADE.md exception-hierarchy tree but declares no type in src/Exception', $result['err']);
    }

    public function testAWrongNestingParentFails(): void
    {
        $this->writeFixture(
            [
                'BaseException' => "abstract class BaseException extends \\RuntimeException\n{\n}\n",
                'OtherException' => "abstract class OtherException extends \\RuntimeException\n{\n}\n",
                'ChildException' => "final class ChildException extends BaseException\n{\n}\n",
            ],
            <<<'TREE'
                BaseException
                OtherException
                └── ChildException
                TREE,
        );

        $result = $this->runChecked();

        self::assertStringContainsString('ChildException is nested under OtherException in UPGRADE.md but extends BaseException', $result['err']);
    }

    public function testAnInterfaceEdgeThroughImplementsPasses(): void
    {
        $this->writeFixture(
            [
                'MarkerInterface' => "interface MarkerInterface\n{\n}\n",
                'ClientException' => "final class ClientException extends \\InvalidArgumentException implements MarkerInterface\n{\n}\n",
            ],
            <<<'TREE'
                MarkerInterface
                └── ClientException (extends \InvalidArgumentException)
                TREE,
        );

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('check-upgrade-exceptions: OK', $result['out']);
    }

    public function testAWrongExplicitExtendsAnnotationFails(): void
    {
        $this->writeFixture(
            [
                'BaseException' => "abstract class BaseException extends \\RuntimeException\n{\n}\n",
                'ChildException' => "final class ChildException extends BaseException\n{\n}\n",
            ],
            <<<'TREE'
                BaseException
                └── ChildException (extends \LogicException)
                TREE,
        );

        $result = $this->runChecked();

        self::assertStringContainsString('ChildException says "(extends LogicException)" in UPGRADE.md but the declaration extends BaseException', $result['err']);
    }

    public function testAMissingTreeBlockFails(): void
    {
        $this->writeException('BaseException', "abstract class BaseException extends \\RuntimeException\n{\n}\n");
        $this->writeUpgrade(<<<'MD'
            # Upgrade Guide

            ```text
            just some example output, not a hierarchy
            ```
            MD);

        $result = $this->runChecked();

        self::assertStringContainsString('no exception-hierarchy tree', $result['err']);
    }

    public function testAMissingExceptionDirectoryIsAUsageError(): void
    {
        $this->writeUpgrade(<<<'MD'
            # Upgrade Guide

            ```text
            WorkermanExceptionInterface
            ```
            MD);

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('src/Exception is missing', $result['err']);
    }

    public function testAMissingUpgradeFileIsAUsageError(): void
    {
        $this->writeException('BaseException', "abstract class BaseException extends \\RuntimeException\n{\n}\n");

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('UPGRADE.md is missing or unreadable', $result['err']);
    }

    public function testAnUnknownOptionIsAUsageError(): void
    {
        $result = $this->runScript([], ['--nope']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('Unknown argument: --nope', $result['err']);
    }

    public function testHelpExitsZeroAndPrintsUsage(): void
    {
        foreach ([['--help'], ['-h']] as $flag) {
            $result = $this->runScript([], $flag);

            self::assertSame(0, $result['code'], $result['err']);
            self::assertStringContainsString('Usage: php bin/check-upgrade-exceptions.php', $result['out']);
            self::assertStringContainsString('--root=DIR', $result['out']);
        }
    }

    /**
     * @param array<string, string> $exceptions class name => class body
     */
    private function writeFixture(array $exceptions, string $tree): void
    {
        foreach ($exceptions as $className => $body) {
            $this->writeException($className, $body);
        }

        $this->writeUpgrade(<<<"MD"
            # Upgrade Guide

            **Exception hierarchy:**

            ```text
            {$tree}
            ```
            MD);
    }

    private function writeException(string $className, string $body): void
    {
        $dir = $this->sandbox . '/src/Exception';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            self::fail('failed to create src/Exception in sandbox');
        }

        $namespace = "namespace CrazyGoat\\WorkermanBundle\\Exception;\n\n";
        $content = "<?php\n\ndeclare(strict_types=1);\n\n" . $namespace . $body;
        self::assertNotFalse(file_put_contents($dir . '/' . $className . '.php', $content));
    }

    private function writeUpgrade(string $content): void
    {
        self::assertNotFalse(file_put_contents($this->sandbox . '/UPGRADE.md', $content . "\n"));
    }

    /**
     * Runs the script against the sandbox and asserts it failed with exit
     * code 1.
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runChecked(): array
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(1, $result['code'], 'expected a drift violation, got: ' . $result['out']);

        return $result;
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runScript(array $env, array $args): array
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

    private function removeRecursively(string $path): void
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
                    error_log(sprintf('UpgradeExceptionsLintTest removeRecursively: rmdir(%s) failed', $item->getPathname()));
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    error_log(sprintf('UpgradeExceptionsLintTest removeRecursively: unlink(%s) failed', $item->getPathname()));
                }
            }
        }

        if (!@rmdir($path)) {
            error_log(sprintf('UpgradeExceptionsLintTest removeRecursively: rmdir(%s) failed', $path));
        }
    }
}
