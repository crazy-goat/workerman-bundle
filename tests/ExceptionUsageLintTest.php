<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\TestCase;

/**
 * Drives `bin/check-exception-usage.php` as a subprocess (issue #593).
 *
 * The script is the single source of truth for the rule "every type in
 * src/Exception/ is referenced by at least one PHP file outside its own
 * definition", and `composer lint` runs it directly — so the pre-push hook
 * and the CI Lint job enforce exactly what these tests assert. The passing
 * case runs against the real tree; every failure scenario runs against a
 * synthetic fixture in a throw-away sandbox.
 *
 * @coversNothing
 */
final class ExceptionUsageLintTest extends TestCase
{
    private string $sandbox = '';

    private string $script = '';

    protected function setUp(): void
    {
        $this->script = \dirname(__DIR__) . '/bin/check-exception-usage.php';
        self::assertFileExists($this->script);

        $sandbox = sys_get_temp_dir() . '/check-exception-usage-test-' . bin2hex(random_bytes(6));
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
        self::assertStringContainsString('check-exception-usage: OK', $result['out']);
        self::assertSame('', $result['err']);
    }

    public function testAnUnusedExceptionClassFails(): void
    {
        // A declared class that no other PHP file mentions is the exact
        // defect #593 found: the hierarchy advertises coverage it does not
        // have.
        $this->writeException('UnusedException', "final class UnusedException extends \\RuntimeException\n{\n}\n");
        $this->writeReferencingFile('src/Other.php', "<?php\n\n// a file that mentions no exception\n");

        $result = $this->runChecked();

        self::assertStringContainsString('UnusedException is declared in src/Exception but referenced by no other PHP file', $result['err']);
    }

    public function testAReferencedExceptionClassPasses(): void
    {
        $this->writeException('ReferencedException', "final class ReferencedException extends \\RuntimeException\n{\n}\n");
        $this->writeReferencingFile('src/UsesIt.php', "<?php\n\nthrow new \\CrazyGoat\\WorkermanBundle\\Exception\\ReferencedException('x');\n");

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('check-exception-usage: OK', $result['out']);
    }

    public function testAReferenceInsideTheSameFileDoesNotCount(): void
    {
        // A class whose only mention is in its own file is unused by
        // definition: the self-reference in a docblock or return type must
        // not satisfy the check.
        $this->writeException(
            'SelfReferentialException',
            "/** @return SelfReferentialException */\n"
            . "final class SelfReferentialException extends \\RuntimeException\n{\n}\n",
        );
        $this->writeReferencingFile('src/Other.php', "<?php\n\n// no reference\n");

        $result = $this->runChecked();

        self::assertStringContainsString('SelfReferentialException is declared in src/Exception but referenced by no other PHP file', $result['err']);
    }

    public function testTheWordInterfaceInADocblockIsNotMistakenForADeclaration(): void
    {
        // Regression test for review finding F-01: the type-discovery step
        // must use the PHP tokenizer, not a regex, so that the word
        // "interface" (or "class") inside a docblock cannot be captured as
        // the declared type name. A naive regex captured "for" from
        // "Marker interface for exceptions", making the real interface
        // silently unchecked.
        $this->writeException(
            'UnusedMarkerInterface',
            "/** Marker interface for unreferenced exceptions. */\n"
            . "interface UnusedMarkerInterface extends \\Throwable\n{\n}\n",
        );
        $this->writeReferencingFile('src/Other.php', "<?php\n\n// no reference\n");

        $result = $this->runChecked();

        // The script must report the *real* type name, not "for".
        self::assertStringContainsString('UnusedMarkerInterface is declared in src/Exception but referenced by no other PHP file', $result['err']);
        self::assertStringNotContainsString('for is declared in src/Exception', $result['err']);
    }

    public function testMultipleTypesInOneFileAreAllDiscovered(): void
    {
        // Regression test for review finding F-02: a file that declares
        // multiple types must have all of them discovered and checked.
        $body = "interface FirstInterface extends \\Throwable\n{\n}\n\n"
            . "final class SecondException extends \\RuntimeException\n{\n}\n";
        $this->writeException('MultiTypeFile', $body);
        // Reference the first type so only the second is unused.
        $this->writeReferencingFile('src/UsesFirst.php', "<?php\n\nthrow new \\CrazyGoat\\WorkermanBundle\\Exception\\FirstInterface('x');\n");

        $result = $this->runChecked();

        self::assertStringContainsString('SecondException is declared in src/Exception but referenced by no other PHP file', $result['err']);
        self::assertStringNotContainsString('FirstInterface is declared in src/Exception but referenced by no other PHP file', $result['err']);
    }

    public function testAMissingExceptionDirectoryIsAUsageError(): void
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('src/Exception is missing', $result['err']);
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
            self::assertStringContainsString('Usage: php bin/check-exception-usage.php', $result['out']);
            self::assertStringContainsString('--root=DIR', $result['out']);
        }
    }

    private function writeException(string $className, string $body): void
    {
        $dir = $this->sandbox . '/src/Exception';
        self::assertTrue(mkdir($dir, 0o775, true), 'failed to create src/Exception in sandbox');

        $namespace = "namespace CrazyGoat\\WorkermanBundle\\Exception;\n\n";
        $content = "<?php\n\ndeclare(strict_types=1);\n\n" . $namespace . $body;
        self::assertNotFalse(file_put_contents($dir . '/' . $className . '.php', $content));
    }

    private function writeReferencingFile(string $relativePath, string $content): void
    {
        $full = $this->sandbox . '/' . $relativePath;
        $dir = \dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            self::fail('failed to create dir for referencing file: ' . $dir);
        }
        self::assertNotFalse(file_put_contents($full, $content));
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

        self::assertSame(1, $result['code'], 'expected an unused-type violation, got: ' . $result['out']);

        return $result;
    }

    /**
     * Runs the script with the given arguments. The environment is inherited
     * from the current process (so that PATH, HOME, and any CI vars the
     * script might need are available), overlaid with any explicit overrides.
     *
     * @param array<string, string> $env
     * @param list<string> $args
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
                    @trigger_error(sprintf('removeRecursively: rmdir(%s) failed', $item->getPathname()), \E_USER_WARNING);
                }
            } else {
                if (!@unlink($item->getPathname())) {
                    @trigger_error(sprintf('removeRecursively: unlink(%s) failed', $item->getPathname()), \E_USER_WARNING);
                }
            }
        }

        if (!@rmdir($path)) {
            @trigger_error(sprintf('removeRecursively: rmdir(%s) failed', $path), \E_USER_WARNING);
        }
    }
}
