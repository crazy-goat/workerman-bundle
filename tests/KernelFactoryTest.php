<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Exception\KernelCreationException;
use CrazyGoat\WorkermanBundle\KernelFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class KernelFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['WORKERMAN_RUNTIME_DIR']);
    }

    public function testCreateKernelReturnsKernel(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $factory = new KernelFactory(
            static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel,
            [],
        );

        self::assertSame($kernel, $factory->createKernel());
    }

    public function testCreateKernelMemoization(): void
    {
        $count = 0;
        $kernel = $this->createMock(KernelInterface::class);
        $factory = new KernelFactory(
            static function () use ($kernel, &$count): KernelInterface {
                ++$count;

                return $kernel;
            },
            [],
        );

        $first = $factory->createKernel();
        $second = $factory->createKernel();

        self::assertSame(1, $count, 'The closure must be called exactly once');
        self::assertSame($first, $second, 'Both calls must return the same instance');
    }

    public function testCreateKernelThrowsForNullReturn(): void
    {
        $factory = new KernelFactory(
            static fn(): null => null,
            [],
        );

        $this->expectException(KernelCreationException::class);
        $this->expectExceptionMessage('Error creating Kernel instance');

        $factory->createKernel();
    }

    public function testCreateKernelPassesArgsToClosure(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $actualArgs = null;
        $factory = new KernelFactory(
            static function (string $env, bool $debug) use ($kernel, &$actualArgs): KernelInterface {
                $actualArgs = [$env, $debug];

                return $kernel;
            },
            ['test', true],
        );

        $factory->createKernel();

        self::assertSame(['test', true], $actualArgs);
    }

    public function testCreateKernelPropagatesClosureException(): void
    {
        $factory = new KernelFactory(
            static function (): never {
                throw new \RuntimeException('Closure failed');
            },
            [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Closure failed');

        $factory->createKernel();
    }

    public function testGetEnvironment(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::once())->method('getEnvironment')->willReturn('test');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('test', $factory->getEnvironment());
    }

    public function testIsDebug(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::once())->method('isDebug')->willReturn(true);

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertTrue($factory->isDebug());
    }

    public function testGetProjectDir(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::once())->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/app', $factory->getProjectDir());
    }

    public function testGetCacheDirInNormalMode(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/app/var/cache/test', $factory->getCacheDir());
    }

    public function testGetLogDirInNormalMode(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/app/var/log', $factory->getLogDir());
    }

    public function testGetRuntimeDirInNormalMode(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/app', $factory->getRuntimeDir());
    }

    public function testGetCacheDirWithCustomRuntimeDir(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/runtime';

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/runtime/var/cache/prod', $factory->getCacheDir());
    }

    public function testGetCacheDirWithEmptyRuntimeDirFallsBack(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '';

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/app/var/cache/test', $factory->getCacheDir());
    }

    public function testGetLogDirWithCustomRuntimeDir(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/runtime';

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/runtime/var/log', $factory->getLogDir());
    }

    public function testGetRuntimeDirWithCustomRuntimeDir(): void
    {
        $_SERVER['WORKERMAN_RUNTIME_DIR'] = '/runtime';

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);
        self::assertSame('/runtime', $factory->getRuntimeDir());
    }

    public function testIsPharReturnsFalseInNormalMode(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $factory = new KernelFactory(static fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel, []);

        self::assertFalse($factory->isPhar());
    }
}
