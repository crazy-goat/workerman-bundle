<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use Symfony\Component\Runtime\ResolverInterface;

final readonly class Resolver implements ResolverInterface
{
    /**
     * @param mixed[] $options
     */
    public function __construct(private ResolverInterface $resolver, private array $options)
    {
    }

    public function resolve(): array
    {
        [$app, $args] = $this->resolver->resolve();

        return [static fn(...$args): KernelFactory => new KernelFactory(...$args), [$app, $args, $this->options]];
    }
}
