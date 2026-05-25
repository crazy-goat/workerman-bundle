<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Resolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Runtime\ResolverInterface;

final class ResolverTest extends TestCase
{
    public function testResolveReturnsClosureAndArgumentsTuple(): void
    {
        $app = static fn(): \stdClass => new \stdClass();
        $args = [1, 'two', 3.0];
        $options = ['debug' => true, 'env_var_name' => 'APP_ENV'];

        $innerResolver = $this->createMock(ResolverInterface::class);
        $innerResolver->method('resolve')->willReturn([$app, $args]);

        $resolver = new Resolver($innerResolver, $options);
        [$closure, $tuple] = $resolver->resolve();

        self::assertInstanceOf(\Closure::class, $closure);
        self::assertSame([$app, $args, $options], $tuple);
    }

    public function testResolveClosureCreatesKernelFactory(): void
    {
        $app = static fn(): \stdClass => new \stdClass();
        $args = [42, 'hello', 3.14];
        $options = ['debug' => false];

        $innerResolver = $this->createMock(ResolverInterface::class);
        $innerResolver->method('resolve')->willReturn([$app, $args]);

        $resolver = new Resolver($innerResolver, $options);
        [$closure] = $resolver->resolve();

        $kernelFactory = $closure($app, $args);

        self::assertInstanceOf(KernelFactory::class, $kernelFactory);
    }

    public function testResolvePropagatesInnerResolverException(): void
    {
        $expectedException = new \RuntimeException('Inner resolver failed');

        $innerResolver = $this->createMock(ResolverInterface::class);
        $innerResolver->method('resolve')->willThrowException($expectedException);

        $resolver = new Resolver($innerResolver, ['debug' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Inner resolver failed');

        $resolver->resolve();
    }

    public function testResolveCallsInnerResolverOnce(): void
    {
        $app = static fn(): \stdClass => new \stdClass();
        $args = [];

        $innerResolver = $this->createMock(ResolverInterface::class);
        $innerResolver->expects(self::once())->method('resolve')->willReturn([$app, $args]);

        $resolver = new Resolver($innerResolver, ['debug' => true]);
        $resolver->resolve();
    }
}
