<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class TestAnnotationsLintTest extends LintScriptTestCase
{
    protected function getScriptName(): string
    {
        return 'check-test-annotations.php';
    }

    protected function getSandboxPrefix(): string
    {
        return 'check-test-annotations-test-';
    }

    public function testTheRealTreePasses(): void
    {
        $result = $this->runScript([], []);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('check-test-annotations: OK', $result['out']);
        self::assertSame('', $result['err']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function annotations(): iterable
    {
        yield 'phpunit requires' => ["/**\n * @requires extension inotify\n */", 'requires'];
        yield 'phpunit one-liner' => ['/** @dataProvider cases */', 'dataProvider'];
        yield 'phpunit usesDefaultClass' => ['/** @usesDefaultClass Foo */', 'usesDefaultClass'];
        yield 'phpbench short' => ["/**\n * @Revs(1000)\n */", 'Revs'];
        yield 'phpbench aliased' => ['/** @Bench\Revs(1000) */', 'Revs'];
        yield 'phpbench fully qualified' => ['/** @\PhpBench\Attributes\Iterations(3) */', 'Iterations'];
    }

    #[DataProvider('annotations')]
    public function testADocAnnotationFails(string $docComment, string $tag): void
    {
        mkdir($this->sandbox . '/tree/tests/Unit', 0o775, true);
        file_put_contents(
            $this->sandbox . '/tree/tests/Unit/ATest.php',
            "<?php\n\nfinal class ATest\n{\n    {$docComment}\n    public function testA(): void\n    {\n    }\n}\n",
        );

        $result = $this->runScript([], ['--root=' . $this->sandbox . '/tree']);

        self::assertSame(1, $result['code'], $result['out']);
        self::assertStringContainsString('tests/Unit/ATest.php:5 @' . $tag, $result['err']);
        self::assertStringContainsString('FAILED — 1 doc annotation(s)', $result['err']);
    }

    public function testStringsVendorAndUnrelatedTagsAreIgnored(): void
    {
        mkdir($this->sandbox . '/tree/src', 0o775, true);
        mkdir($this->sandbox . '/tree/e2e/vendor/x', 0o775, true);
        file_put_contents(
            $this->sandbox . '/tree/src/A.php',
            "<?php\n\n\$a = '/** @group e2e */';\n\n/** @var Bench\\Revs \$b */\n\$b = null;\n\n/** @testing */\nfunction a(): void\n{\n}\n",
        );
        file_put_contents($this->sandbox . '/tree/e2e/vendor/x/B.php', "<?php\n\n/** @group vendored */\nfunction b(): void\n{\n}\n");

        $result = $this->runScript([], ['--root=' . $this->sandbox . '/tree']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('check-test-annotations: OK', $result['out']);
    }

    public function testUsageErrors(): void
    {
        $help = $this->runScript([], ['--help']);
        self::assertSame(0, $help['code']);
        self::assertStringContainsString('Usage: php bin/check-test-annotations.php', $help['out']);

        $unknown = $this->runScript([], ['--nope']);
        self::assertSame(2, $unknown['code']);
        self::assertStringContainsString('Unknown argument: --nope', $unknown['err']);

        $emptyRoot = $this->runScript([], ['--root=']);
        self::assertSame(2, $emptyRoot['code']);

        $missing = $this->runScript([], ['--root=' . $this->sandbox . '/missing']);
        self::assertSame(2, $missing['code']);
        self::assertStringContainsString('Root directory does not exist', $missing['err']);
    }
}
