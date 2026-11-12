<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Tools;

use CrazyGoat\WorkermanBundle\Tools\PhpCsFixer\TestAnnotationTags;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class NoTestDocAnnotationsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__, 2);
        require_once $this->projectDir . '/tools/php-cs-fixer/TestAnnotationTags.php';
    }

    public function testTheScanRecognisesAnnotations(): void
    {
        $this->assertSame('requires', TestAnnotationTags::find("/**\n * @requires extension inotify\n */"));
        $this->assertSame('dataProvider', TestAnnotationTags::find('/** @dataProvider cases */'));
        $this->assertSame('Revs', TestAnnotationTags::find("/**\n * @Revs(100)\n */"));
        $this->assertSame('test', TestAnnotationTags::find('/** @test */'));
        $this->assertSame('usesDefaultClass', TestAnnotationTags::find('/** @usesDefaultClass Foo */'));
        $this->assertSame('Revs', TestAnnotationTags::find('/** @Bench\\Revs(1000) */'));
        $this->assertSame('Revs', TestAnnotationTags::find('/** @\\PhpBench\\Attributes\\Revs(1) */'));
        $this->assertSame('Iterations', TestAnnotationTags::find("/**\n * @PhpBench\\Benchmark\\Metadata\\Annotations\\Iterations(3)\n */"));
        $this->assertNull(TestAnnotationTags::find('/** @Foo\\requires */'));
        $this->assertNull(TestAnnotationTags::find('/** @var Bench\\Revs $b */'));
        $this->assertNull(TestAnnotationTags::find('/** @testing */'));
        $this->assertNull(TestAnnotationTags::find('/** @param int $group */'));
        $this->assertNull(TestAnnotationTags::find('/** @var string user@group.example */'));
    }

    public function testRectorConvertsPhpunitAnnotationsBeforeTheFixerRuns(): void
    {
        $this->assertStringContainsString(
            'PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES',
            (string) file_get_contents($this->projectDir . '/rector.php'),
        );
    }

    public function testThePhpunitListCoversEveryAnnotationPhpunitParses(): void
    {
        $parser = $this->projectDir . '/vendor/phpunit/phpunit/src/Metadata/Parser/AnnotationParser.php';
        if (!is_file($parser)) {
            $this->markTestSkipped('This PHPUnit version has no annotation parser, so doc annotations are ignored by PHPUnit itself');
        }

        preg_match_all("~case '([A-Za-z]+)':~", (string) file_get_contents($parser), $matches);
        $parsed = array_values(array_unique([...$matches[1], 'requires', 'testWith', 'dataProviderExternal', 'dependsExternal']));
        $this->assertGreaterThan(20, \count($parsed), 'The PHPUnit annotation parser layout changed; update this test');

        $this->assertSame([], array_values(array_diff($parsed, TestAnnotationTags::PHPUNIT)), 'TestAnnotationTags::PHPUNIT is missing annotations PHPUnit parses');
    }

    public function testThePhpbenchListCoversEveryPhpbenchAnnotationClass(): void
    {
        $directory = $this->projectDir . '/vendor/phpbench/phpbench/lib/Benchmark/Metadata/Annotations';
        $this->assertDirectoryExists($directory, 'PHPBench moved its annotation classes; update this test');

        $classes = [];
        foreach ((array) glob($directory . '/*.php') as $file) {
            $name = basename((string) $file, '.php');
            if (!str_starts_with($name, 'Abstract')) {
                $classes[] = $name;
            }
        }
        $this->assertNotSame([], $classes);

        $this->assertSame([], array_values(array_diff($classes, TestAnnotationTags::PHPBENCH)), 'TestAnnotationTags::PHPBENCH is missing PHPBench annotations');
    }

    public function testComposerLintRunsTheAnnotationCheck(): void
    {
        $this->assertStringContainsString(
            'step "test-annotations" php bin/check-test-annotations.php',
            (string) file_get_contents($this->projectDir . '/bin/lint.sh'),
        );
    }
}
