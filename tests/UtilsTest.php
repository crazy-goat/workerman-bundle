<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CrazyGoat\WorkermanBundle\Utils
 */
final class UtilsTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    public function testIsWindows(): void
    {
        $expected = \DIRECTORY_SEPARATOR !== '/';
        $this->assertSame($expected, Utils::isWindows());
    }

    public function testCpuCountReturnsPositiveInteger(): void
    {
        $cpuCount = Utils::cpuCount();

        $this->assertGreaterThanOrEqual(1, $cpuCount);
    }

    public function testCpuCountNeverReturnsZero(): void
    {
        // Regression test for #150: Utils::cpuCount() must NEVER return 0,
        // even when shell_exec('nproc') returns null (command not available)
        // or produces empty/unexpected output. Returning 0 would cause
        // downstream issues: zero workers spawned in ServerWorker.
        $this->assertNotSame(0, Utils::cpuCount());
    }

    /**
     * @requires OS Windows
     */
    public function testCpuCountReturnsOneOnWindows(): void
    {
        $this->assertSame(1, Utils::cpuCount());
    }

    /**
     * @requires OS Linux|Darwin
     */
    public function testCpuCountReturnsOneWhenShellExecDisabled(): void
    {
        if (function_exists('shell_exec')) {
            $this->markTestSkipped('shell_exec is available, cannot test disabled state');
        }

        $this->assertSame(1, Utils::cpuCount());
    }

    /**
     * @return array<string, array{string, string, ?int}>
     */
    public static function cgroupV2Provider(): array
    {
        return [
            'one cpu' => ['100000 100000', 'v2', 1],
            'two and a half cpus round up' => ['250000 100000', 'v2', 3],
            'less than one cpu is one' => ['50000 100000', 'v2', 1],
            'max means no limit' => ['max 100000', 'v2', null],
            'broken content means no limit' => ['abc', 'v2', null],
            'v1 one cpu' => ['100000 100000', 'v1', 1],
            'v1 four cpus' => ['400000 100000', 'v1', 4],
            'v1 minus one means no limit' => ['-1 100000', 'v1', null],
            'v1 zero period means no limit' => ['100000 0', 'v1', null],
        ];
    }

    /**
     * @dataProvider cgroupV2Provider
     */
    public function testCgroupCpuLimitReadsFakeFiles(string $content, string $version, ?int $expected): void
    {
        $root = $this->makeFakeCgroup();

        if ($version === 'v2') {
            file_put_contents($root . '/cpu.max', $content);
        } else {
            [$quota, $period] = explode(' ', $content);
            mkdir($root . '/cpu');
            file_put_contents($root . '/cpu/cpu.cfs_quota_us', $quota . "\n");
            file_put_contents($root . '/cpu/cpu.cfs_period_us', $period . "\n");
        }

        $this->assertSame($expected, Utils::cgroupCpuLimit($root));
    }

    public function testCgroupCpuLimitIsNullWhenFilesAreMissing(): void
    {
        $this->assertNull(Utils::cgroupCpuLimit($this->makeFakeCgroup()));
        $this->assertNull(Utils::cgroupCpuLimit('/this/path/does/not/exist'));
    }

    /**
     * @requires OS Linux
     */
    public function testCpuCountIsLimitedByCgroupQuota(): void
    {
        $root = $this->makeFakeCgroup();
        file_put_contents($root . '/cpu.max', '100000 100000');

        $this->assertSame(1, Utils::cpuCount($root));
    }

    /**
     * @requires OS Linux
     */
    public function testCpuCountIgnoresQuotaAboveRealCpuCount(): void
    {
        $root = $this->makeFakeCgroup();
        file_put_contents($root . '/cpu.max', '100000000 100000');

        $this->assertSame(Utils::cpuCount($this->makeFakeCgroup()), Utils::cpuCount($root));
    }

    private function makeFakeCgroup(): string
    {
        $root = sys_get_temp_dir() . '/wmb-cgroup-' . bin2hex(random_bytes(6));
        mkdir($root);
        $this->roots[] = $root;

        return $root;
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach ([...(glob($root . '/*') ?: []), ...(glob($root . '/cpu/*') ?: [])] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($root . '/cpu');
            @rmdir($root);
        }
        $this->roots = [];
    }

    public function testClearOpcacheDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Utils::clearOpcache();
    }

    public function testCannotBeInstantiated(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/(private|cannot be accessed)/');

        // PHPStan doesn't understand expectException() - this line is expected to throw
        /** @phpstan-ignore-next-line */
        new Utils();
    }

    public function testSignalExtensionsAvailable(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $allowSkip = getenv('WORKERMAN_ALLOW_PCNTL_SKIP');
            if ($allowSkip !== false && $allowSkip !== '') {
                $this->markTestSkipped('pcntl and posix extensions are required for this test (skipped via WORKERMAN_ALLOW_PCNTL_SKIP).');
            }

            $this->fail('pcntl and posix extensions are required but not loaded. '
                . 'Set WORKERMAN_ALLOW_PCNTL_SKIP=1 to allow skipping this on developer machines.');
        }

        $this->addToAssertionCount(1);
    }

    public function testReloadSendsSigusr1(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        $signalReceived = false;
        \pcntl_signal(SIGUSR1, static function () use (&$signalReceived): void {
            $signalReceived = true;
        });

        try {
            Utils::reload();
            \pcntl_signal_dispatch();
            $this->assertTrue($signalReceived, 'reload() should send SIGUSR1 signal.');
        } finally {
            \pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }

    public function testDeprecatedRebootTriggersDeprecation(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        \pcntl_signal(SIGUSR1, static fn(): null => null);

        $deprecationTriggered = false;
        \set_error_handler(
            static function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
                if ($errno === \E_USER_DEPRECATED && \str_contains($errstr, 'Utils::reboot() is deprecated')) {
                    $deprecationTriggered = true;

                    return true;
                }

                return false;
            },
        );

        try {
            Utils::reboot();
            \pcntl_signal_dispatch();
            $this->assertTrue($deprecationTriggered, 'reboot() should trigger a deprecation notice.');
        } finally {
            \restore_error_handler();
            \pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }

    public function testDeprecatedRebootDelegatesToReload(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('pcntl and posix extensions are required for this test.');
        }

        $signalReceived = false;
        \pcntl_signal(SIGUSR1, static function () use (&$signalReceived): void {
            $signalReceived = true;
        });

        \set_error_handler(static fn(int $errno): bool => $errno === \E_USER_DEPRECATED);

        try {
            Utils::reboot();
            \pcntl_signal_dispatch();
            $this->assertTrue($signalReceived, 'reboot() should delegate to reload() and send SIGUSR1.');
        } finally {
            \restore_error_handler();
            \pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }
}
