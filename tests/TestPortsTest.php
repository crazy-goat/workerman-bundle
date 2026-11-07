<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Test\App\Kernel;
use CrazyGoat\WorkermanBundle\Test\App\TestPorts;
use PHPUnit\Framework\TestCase;

final class TestPortsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private bool $hadAppCacheDir = false;

    private ?string $originalAppCacheDir = null;

    protected function setUp(): void
    {
        foreach ([TestPorts::ENV_HTTP, TestPorts::ENV_SECOND, TestPorts::ENV_THIRD, 'WMB_LISTEN_ADDR'] as $name) {
            $this->originalEnv[$name] = \getenv($name);
            \putenv($name);
        }

        $this->hadAppCacheDir = \array_key_exists('APP_CACHE_DIR', $_SERVER);
        $appCacheDir = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->originalAppCacheDir = \is_string($appCacheDir) ? $appCacheDir : null;
        unset($_SERVER['APP_CACHE_DIR']);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                \putenv($name);
            } else {
                \putenv($name . '=' . $value);
            }
        }

        if ($this->hadAppCacheDir && $this->originalAppCacheDir !== null) {
            $_SERVER['APP_CACHE_DIR'] = $this->originalAppCacheDir;
        } else {
            unset($_SERVER['APP_CACHE_DIR']);
        }
    }

    public function testDefaultsAreTheHistoricalPorts(): void
    {
        self::assertSame(8888, TestPorts::http());
        self::assertSame(9999, TestPorts::second());
        self::assertSame(9991, TestPorts::third());
        self::assertSame([8888, 9999, 9991], TestPorts::all());
    }

    public function testEnvironmentOverridesAllThreePorts(): void
    {
        \putenv('WMB_PORT_HTTP=18888');
        \putenv('WMB_PORT_SECOND=19999');
        \putenv('WMB_PORT_THIRD=19991');

        self::assertSame(18888, TestPorts::http());
        self::assertSame(19999, TestPorts::second());
        self::assertSame(19991, TestPorts::third());
        self::assertSame([18888, 19999, 19991], TestPorts::all());
    }

    public function testEmptyEnvironmentValueFallsBackToTheDefault(): void
    {
        \putenv('WMB_PORT_HTTP=');
        \putenv('WMB_PORT_SECOND=');
        \putenv('WMB_PORT_THIRD=');

        self::assertSame([8888, 9999, 9991], TestPorts::all());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPortProvider(): array
    {
        return [
            'non-numeric' => ['abc'],
            'decimal' => ['12.5'],
            'negative' => ['-1'],
            'zero' => ['0'],
            'above range' => ['65536'],
            'integer overflow' => ['999999999999999999999'],
            'leading space' => [' 8888'],
        ];
    }

    /**
     * @dataProvider invalidPortProvider
     */
    public function testRejectsAnInvalidPort(string $value): void
    {
        \putenv('WMB_PORT_HTTP=' . $value);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WMB_PORT_HTTP must be a TCP port number between 1 and 65535');

        TestPorts::http();
    }

    public function testEveryPortVariableIsValidated(): void
    {
        \putenv('WMB_PORT_SECOND=not-a-port');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WMB_PORT_SECOND must be a TCP port number between 1 and 65535');

        TestPorts::all();
    }

    public function testResolutionIsNotCachedBetweenCalls(): void
    {
        self::assertSame(8888, TestPorts::http());

        \putenv('WMB_PORT_HTTP=18888');
        self::assertSame(18888, TestPorts::http());

        \putenv('WMB_PORT_HTTP');
        self::assertSame(8888, TestPorts::http());
    }

    public function testDefaultPortsKeepTheHistoricalCacheDir(): void
    {
        \putenv('WMB_LISTEN_ADDR=0.0.0.0');

        self::assertNull(TestPorts::cacheDir(\dirname(__DIR__)));
        TestPorts::applyCacheDir(\dirname(__DIR__));
        self::assertArrayNotHasKey('APP_CACHE_DIR', $_SERVER);
    }

    public function testCacheDirIsIsolatedPerPortSet(): void
    {
        $projectDir = \dirname(__DIR__);

        \putenv('WMB_PORT_HTTP=18888');
        \putenv('WMB_PORT_SECOND=19999');
        \putenv('WMB_PORT_THIRD=19991');
        $cacheDir = TestPorts::cacheDir($projectDir);
        $hostHash = \substr(\sha1('127.0.0.1'), 0, 8);

        self::assertSame($projectDir . '/var/cache/ports-18888-19999-19991-' . $hostHash, $cacheDir);

        TestPorts::applyCacheDir($projectDir);
        self::assertSame($cacheDir, $_SERVER['APP_CACHE_DIR'] ?? null);
        $kernel = new Kernel('test', true);
        self::assertSame($cacheDir . '/test', $kernel->getCacheDir());
        self::assertSame($kernel->getProjectDir() . '/var/cache/test', $this->kernelCacheDirWithoutOverride());
    }

    public function testCacheDirChangesWithTheBindAddress(): void
    {
        $projectDir = \dirname(__DIR__);
        \putenv('WMB_PORT_HTTP=18888');
        \putenv('WMB_PORT_SECOND=19999');
        \putenv('WMB_PORT_THIRD=19991');

        \putenv('WMB_LISTEN_ADDR=127.0.0.1');
        $loopback = TestPorts::cacheDir($projectDir);

        \putenv('WMB_LISTEN_ADDR=0.0.0.0');
        $wildcard = TestPorts::cacheDir($projectDir);

        self::assertNotNull($loopback);
        self::assertNotNull($wildcard);
        self::assertNotSame($loopback, $wildcard, 'the cache must not be reused across WMB_LISTEN_ADDR changes');
    }

    private function kernelCacheDirWithoutOverride(): string
    {
        unset($_SERVER['APP_CACHE_DIR']);

        return (new Kernel('test', true))->getCacheDir();
    }

    public function testListenUsesTheConfiguredBindAddress(): void
    {
        \putenv('WMB_LISTEN_ADDR=0.0.0.0');

        self::assertSame('0.0.0.0', TestPorts::host());
        self::assertSame('http://0.0.0.0:8888', TestPorts::listen(TestPorts::http()));
    }

    public function testUrlUsesLoopbackForAWildcardBindAddress(): void
    {
        \putenv('WMB_LISTEN_ADDR=0.0.0.0');

        self::assertSame('127.0.0.1', TestPorts::connectHost());
        self::assertSame('http://127.0.0.1:9999/dispatch_count_test', TestPorts::url(TestPorts::second(), '/dispatch_count_test'));
    }

    public function testUrlUsesIpv6LoopbackForTheIpv6WildcardAddress(): void
    {
        \putenv('WMB_LISTEN_ADDR=::');

        self::assertSame('::', TestPorts::host());
        self::assertSame('[::1]', TestPorts::connectHost());
        self::assertSame('http://[::]:9991', TestPorts::listen(TestPorts::third()));
        self::assertSame('http://[::1]:9991', TestPorts::url(TestPorts::third()));
    }

    public function testUrlBracketsAnExplicitIpv6Address(): void
    {
        \putenv('WMB_LISTEN_ADDR=::1');

        self::assertSame('::1', TestPorts::host());
        self::assertSame('[::1]', TestPorts::connectHost());
        self::assertSame('http://[::1]:8888', TestPorts::listen(TestPorts::http()));
        self::assertSame('http://[::1]:8888/response_test', TestPorts::url(TestPorts::http(), '/response_test'));
    }

    /**
     * fsockopen() rejects a bare IPv6 literal; the value connectHost() returns
     * must be usable by the readiness probe and every raw-socket test client.
     */
    public function testConnectHostIsUsableWithFsockopenOnIpv6(): void
    {
        $server = @\stream_socket_server('tcp://[::1]:0', $errorCode, $errorMessage);
        if ($server === false) {
            self::markTestSkipped(sprintf('IPv6 loopback is not available: %s (%d)', $errorMessage, $errorCode));
        }

        try {
            $name = \stream_socket_get_name($server, false);
            self::assertIsString($name);
            $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);

            \putenv('WMB_LISTEN_ADDR=::1');
            self::assertSame('[::1]', TestPorts::connectHost());

            $socket = @\fsockopen(TestPorts::connectHost(), $port, $errorCode, $errorMessage, 1);
            self::assertIsResource($socket, sprintf(
                'fsockopen(%s:%d) must connect to the IPv6 listener (%s)',
                TestPorts::connectHost(),
                $port,
                $errorMessage,
            ));
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        } finally {
            \fclose($server);
        }
    }

    public function testUrlKeepsAnExplicitConnectAddress(): void
    {
        \putenv('WMB_LISTEN_ADDR=127.0.0.2');

        self::assertSame('127.0.0.2', TestPorts::connectHost());
        self::assertSame('http://127.0.0.2:8888/response_test', TestPorts::url(TestPorts::http(), '/response_test'));
    }

    public function testUrlWithoutAPathHasNoTrailingSlash(): void
    {
        self::assertSame('http://127.0.0.1:8888', TestPorts::url(TestPorts::http()));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function outOfRangePortProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above range' => [65536],
        ];
    }

    /**
     * @dataProvider outOfRangePortProvider
     */
    public function testHelpersRejectAnOutOfRangePort(int $port): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A TCP port must be between 1 and 65535');

        TestPorts::url($port);
    }
}
