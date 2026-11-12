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

    public function testNoPhpunitOrPhpbenchDocAnnotationIsLeft(): void
    {
        $found = [];
        foreach ($this->trackedPhpFiles() as $path) {
            foreach (token_get_all((string) file_get_contents($this->projectDir . '/' . $path)) as $token) {
                if (!\is_array($token) || $token[0] !== \T_DOC_COMMENT) {
                    continue;
                }
                $tag = TestAnnotationTags::find($token[1]);
                if ($tag !== null) {
                    $found[] = \sprintf('%s:%d @%s', $path, $token[2], $tag);
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "PHPUnit/PHPBench doc annotations must be attributes (#[DataProvider], #[RequiresPhpExtension], #[Revs], ...); CrazyGoat/no_narrative_comments leaves them alone so they are not lost:\n" . implode("\n", $found),
        );
    }

    public function testTheScanRecognisesAnnotations(): void
    {
        $this->assertSame('requires', TestAnnotationTags::find("/**\n * @requires extension inotify\n */"));
        $this->assertSame('dataProvider', TestAnnotationTags::find('/** @dataProvider cases */'));
        $this->assertSame('Revs', TestAnnotationTags::find("/**\n * @Revs(100)\n */"));
        $this->assertSame('test', TestAnnotationTags::find('/** @test */'));
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

    /**
     * @return list<string>
     */
    private function trackedPhpFiles(): array
    {
        exec('git -C ' . escapeshellarg($this->projectDir) . " ls-files -- '*.php' e2e/console", $output, $code);
        $this->assertSame(0, $code, 'git ls-files failed');
        $this->assertNotSame([], $output);

        return $output;
    }
}
