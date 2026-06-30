<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\CacheWarmupTimeoutConfig;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Resolver;
use CrazyGoat\WorkermanBundle\Runner;
use CrazyGoat\WorkermanBundle\Runtime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

final class RuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        CacheWarmupTimeoutConfig::reset();
    }

    protected function tearDown(): void
    {
        CacheWarmupTimeoutConfig::reset();
    }

    public function testGetRunnerWithKernelFactoryReturnsRunner(): void
    {
        $kernelFactory = new KernelFactory(
            static fn(): KernelInterface => self::createMock(KernelInterface::class),
            [],
        );

        $runtime = new Runtime([]);
        $runner = $runtime->getRunner($kernelFactory);

        self::assertInstanceOf(Runner::class, $runner);
    }

    public function testGetRunnerWithUnsupportedApplicationDelegatesToParent(): void
    {
        $runtime = new Runtime([]);
        $runner = $runtime->getRunner(null);

        self::assertInstanceOf(RunnerInterface::class, $runner);
        self::assertNotInstanceOf(Runner::class, $runner);
    }

    public function testGetRunnerUsesDefaultTimeoutWhenHolderIsEmpty(): void
    {
        $kernelFactory = new KernelFactory(
            static fn(): KernelInterface => self::createMock(KernelInterface::class),
            [],
        );

        $runtime = new Runtime([]);
        $runner = $runtime->getRunner($kernelFactory);

        $ref = new \ReflectionProperty(Runner::class, 'cacheWarmupTimeout');
        self::assertSame(30, $ref->getValue($runner));
    }

    public function testGetRunnerUsesTimeoutFromHolder(): void
    {
        CacheWarmupTimeoutConfig::set(45);

        $kernelFactory = new KernelFactory(
            static fn(): KernelInterface => self::createMock(KernelInterface::class),
            [],
        );

        $runtime = new Runtime([]);
        $runner = $runtime->getRunner($kernelFactory);

        $ref = new \ReflectionProperty(Runner::class, 'cacheWarmupTimeout');
        self::assertSame(45, $ref->getValue($runner));
    }

    public function testGetRunnerReadsHolderFreshlyOnEachCall(): void
    {
        $kernelFactory = new KernelFactory(
            static fn(): KernelInterface => self::createMock(KernelInterface::class),
            [],
        );

        $runtime = new Runtime([]);

        CacheWarmupTimeoutConfig::set(45);
        $firstRunner = $runtime->getRunner($kernelFactory);

        CacheWarmupTimeoutConfig::set(99);
        $secondRunner = $runtime->getRunner($kernelFactory);

        $ref = new \ReflectionProperty(Runner::class, 'cacheWarmupTimeout');
        self::assertSame(45, $ref->getValue($firstRunner));
        self::assertSame(99, $ref->getValue($secondRunner));
    }

    public function testFullChainEnvVarToRunnerTimeout(): void
    {
        CacheWarmupTimeoutConfig::set(77);

        $kernelFactory = new KernelFactory(
            static fn(): KernelInterface => self::createMock(KernelInterface::class),
            [],
        );

        $runtime = new Runtime([]);
        $runner = $runtime->getRunner($kernelFactory);

        $ref = new \ReflectionMethod($runner, 'getCacheWarmupTimeout');

        self::assertSame(77, $ref->invoke($runner));
    }

    public function testGetResolverReturnsResolver(): void
    {
        $callable = static fn(): \stdClass => new \stdClass();
        $runtime = new Runtime([]);
        $resolver = $runtime->getResolver($callable);

        self::assertInstanceOf(Resolver::class, $resolver);
    }

    public function testGetResolverProducedResolverResolveReturnsCorrectStructure(): void
    {
        $callable = static fn(): \stdClass => new \stdClass();
        $runtime = new Runtime([]);
        $resolver = $runtime->getResolver($callable);
        [$closure, $tuple] = $resolver->resolve();

        self::assertInstanceOf(\Closure::class, $closure);
        self::assertCount(3, $tuple);

        $kernelFactory = $closure($tuple[0], $tuple[1]);
        self::assertInstanceOf(KernelFactory::class, $kernelFactory);
    }

}
