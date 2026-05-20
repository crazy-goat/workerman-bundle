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

        // Only wrap in KernelFactory for server start/restart commands.
        // For other actions (stop, status, reload, connections),
        // let the parent SymfonyRuntime handle them via the Console runner.
        $command = $_SERVER['argv'][1] ?? '';
        if ($command === 'start' || $command === 'restart') {
            return [static fn(...$args): KernelFactory => new KernelFactory(...$args), [$app, $args, $this->options]];
        }

        return [$app, $args];
    }
}
