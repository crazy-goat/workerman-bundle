<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

use CrazyGoat\WorkermanBundle\Exception\KernelCreationException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

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
            throw new KernelCreationException();
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

    /**
     * Reset services after kernel termination to prevent memory leaks.
     */
    public function resetServices(): void
    {
        $kernel = $this->kernel;
        if (!$kernel instanceof KernelInterface) {
            return;
        }

        try {
            $container = $kernel->getContainer();
            if ($container->has('services_resetter')) {
                $resetter = $container->get('services_resetter');
                if ($resetter instanceof ResetInterface) {
                    $resetter->reset();
                }
            }
        } catch (\Throwable $e) {
            // Log error if logger is available
            if ($kernel->has('logger')) {
                $logger = $kernel->get('logger');
                if ($logger instanceof \Psr\Log\LoggerInterface) {
                    $logger->error(
                        'Failed to reset services',
                        ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                    );
                }
            }
        }
    }
}
