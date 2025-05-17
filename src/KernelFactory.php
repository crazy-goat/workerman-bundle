<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use Symfony\Component\HttpKernel\KernelInterface;

final class KernelFactory
{
    private ?KernelInterface $kernel = null;

    /**
     * @param mixed[] $args
     */
    public function __construct(private readonly \Closure $app, private readonly array $args)
    {
    }

    public function createKernel(): KernelInterface
    {
        if (!$this->kernel instanceof KernelInterface) {
            $this->kernel = ($this->app)(...$this->args);
        }

        if (!$this->kernel instanceof KernelInterface) {
            throw new \RuntimeException('Error creating Kernel instance');
        }

        return $this->kernel;
    }

    public function getEnvironment(): string
    {
        return $this->createKernel()->getEnvironment();
    }

    public function isDebug(): bool
    {
        return $this->createKernel()->isDebug();
    }

    public function getProjectDir(): string
    {
        return $this->createKernel()->getProjectDir();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->getEnvironment();
    }
}
