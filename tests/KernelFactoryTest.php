<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tests;

use CrazyGoat\WorkermanBundle\KernelFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class KernelFactoryTest extends TestCase
{
    public function testKernelIsCached(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $app = (fn() => $kernel);

        $factory = new KernelFactory($app, []);
        $this->assertSame($kernel, $factory->createKernel());
        $this->assertSame($kernel, $factory->createKernel());
    }

    public function testResetServicesCallsResetOnResetInterface(): void
    {
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->once())
            ->method('reset');

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')
            ->with('services_resetter')
            ->willReturn(true);
        $container->method('get')
            ->with('services_resetter')
            ->willReturn($resetter);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')
            ->willReturn($container);

        $app = (fn() => $kernel);

        $factory = new KernelFactory($app, []);
        // Use reflection to set the private kernel property
        $reflection = new \ReflectionClass($factory);
        $property = $reflection->getProperty('kernel');
        $property->setValue($factory, $kernel);

        $factory->resetServices();
    }

    public function testResetServicesWhenNoResetter(): void
    {
        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')
            ->with('services_resetter')
            ->willReturn(false);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')
            ->willReturn($container);

        $app = (fn() => $kernel);

        $factory = new KernelFactory($app, []);
        $reflection = new \ReflectionClass($factory);
        $property = $reflection->getProperty('kernel');
        $property->setValue($factory, $kernel);

        // Should not throw
        $factory->resetServices();
        $this->assertTrue(true);
    }
}
