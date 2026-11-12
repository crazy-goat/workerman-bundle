<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class ExceptionUsageLintTest extends LintScriptTestCase
{
    protected function getScriptName(): string
    {
        return 'check-exception-usage.php';
    }

    protected function getSandboxPrefix(): string
    {
        return 'check-exception-usage-test-';
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
        $this->writeException(
            'UnusedMarkerInterface',
            "/** Marker interface for unreferenced exceptions. */\n"
            . "interface UnusedMarkerInterface extends \\Throwable\n{\n}\n",
        );
        $this->writeReferencingFile('src/Other.php', "<?php\n\n// no reference\n");

        $result = $this->runChecked();

        self::assertStringContainsString('UnusedMarkerInterface is declared in src/Exception but referenced by no other PHP file', $result['err']);
        self::assertStringNotContainsString('for is declared in src/Exception', $result['err']);
    }

    public function testMultipleTypesInOneFileAreAllDiscovered(): void
    {
        $body = "interface FirstInterface extends \\Throwable\n{\n}\n\n"
            . "final class SecondException extends \\RuntimeException\n{\n}\n";
        $this->writeException('MultiTypeFile', $body);
        $this->writeReferencingFile('src/UsesFirst.php', "<?php\n\nthrow new \\CrazyGoat\\WorkermanBundle\\Exception\\FirstInterface('x');\n");

        $result = $this->runChecked();

        self::assertStringContainsString('SecondException is declared in src/Exception but referenced by no other PHP file', $result['err']);
        self::assertStringNotContainsString('FirstInterface is declared in src/Exception but referenced by no other PHP file', $result['err']);
    }

    public function testClassConstantFetchIsNotMistakenForADeclaration(): void
    {
        $this->writeException(
            'RealException',
            "final class RealException extends \\RuntimeException\n{\n}\n"
            . "function probe(): string\n{\n"
            . "    return \\Foo\\Bar::class . SOME_SUFFIX;\n"
            . "}\n",
        );
        $this->writeReferencingFile('src/UsesIt.php', "<?php\n\nthrow new \\CrazyGoat\\WorkermanBundle\\Exception\\RealException('x');\n");

        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(0, $result['code'], $result['err'] . $result['out']);
        self::assertStringContainsString('check-exception-usage: OK', $result['out']);
    }

    public function testAMissingExceptionDirectoryIsAUsageError(): void
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('src/Exception is missing', $result['err']);
    }

    public function testAnEmptyRootValueIsAUsageError(): void
    {
        $this->writeException('UnusedException', "final class UnusedException extends \\RuntimeException\n{\n}\n");
        $this->writeReferencingFile('src/Other.php', "<?php\n\n// a file that mentions no exception\n");

        $result = $this->runScript([], ['--root=']);

        self::assertSame(2, $result['code'], $result['out']);
        self::assertStringContainsString('--root= requires a directory path', $result['err']);
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
     * @return array{code: int, out: string, err: string}
     */
    private function runChecked(): array
    {
        $result = $this->runScript([], ['--root=' . $this->sandbox]);

        self::assertSame(1, $result['code'], 'expected an unused-type violation, got: ' . $result['out']);

        return $result;
    }
}
