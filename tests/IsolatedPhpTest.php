<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsolatedPhpTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function arrangements(): iterable
    {
        yield 'both built in' => [[], ['pcntl', 'posix', 'Core'], ['pcntl', 'posix', 'Core']];
        yield 'pcntl shared, posix built in (official Docker image)' => [['pcntl'], ['pcntl', 'posix'], ['posix']];
        yield 'both shared' => [['pcntl', 'posix'], ['pcntl', 'posix'], []];
        yield 'pcntl built in, posix shared' => [['posix'], ['pcntl', 'posix'], ['pcntl']];
        yield 'not loaded at all is not forced' => [[], [], []];
        yield 'extensions that are not required are ignored' => [['pcntl'], ['pcntl', 'posix', 'grpc'], ['posix']];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $loadedHere
     * @param list<string> $loadedWithoutIni
     */
    #[DataProvider('arrangements')]
    public function testOnlyMissingSharedModulesAreLoaded(array $expected, array $loadedHere, array $loadedWithoutIni): void
    {
        self::assertSame($expected, IsolatedPhp::extensionsToLoad(['pcntl', 'posix'], $loadedHere, $loadedWithoutIni));
    }


    public function testCoverageRunAddsPcovFlagsToTheIsolatedCommand(): void
    {
        if (!extension_loaded('pcov')) {
            self::markTestSkipped('pcov is not loaded');
        }

        $previous = getenv('WMB_SUBPROCESS_COVERAGE_DIR');
        putenv('WMB_SUBPROCESS_COVERAGE_DIR=' . sys_get_temp_dir() . '/wmb-isolated-coverage');

        try {
            $command = IsolatedPhp::command();
        } finally {
            if ($previous === false) {
                putenv('WMB_SUBPROCESS_COVERAGE_DIR');
            } else {
                putenv('WMB_SUBPROCESS_COVERAGE_DIR=' . $previous);
            }
        }

        self::assertContains('pcov.enabled=1', $command);
        self::assertContains('pcov.directory=' . dirname(__DIR__) . '/src', $command);

        $process = proc_open(
            [...$command, '-r', 'echo extension_loaded("pcov") ? "1" : "0";'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('1', $stdout);
        self::assertSame('', $stderr);
    }

    public function testTheCommandGivesAPhpWithPcntlAndPosixButWithoutGrpc(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix must be loaded in the test runtime.');
        }

        $process = proc_open(
            [...IsolatedPhp::command(), '-r', 'echo json_encode([function_exists("pcntl_fork"), function_exists("posix_kill"), extension_loaded("grpc")]);'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', $stderr, 'no startup warning, for example from loading a built-in module again');
        self::assertSame('[true,true,false]', $stdout);
    }
}
