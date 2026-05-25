<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Resolver;
use CrazyGoat\WorkermanBundle\Runner;
use CrazyGoat\WorkermanBundle\Runtime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\RunnerInterface;

final class RuntimeTest extends TestCase
{
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
