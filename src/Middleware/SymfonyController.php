<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Workerman\Protocols\Http\Response;

final class SymfonyController
{
    private ?SymfonyRequest $symfonyRequest = null;
    private ?SymfonyResponse $symfonyResponse = null;
    private ?ResetInterface $servicesResetter = null;
    private bool $servicesResetterInitialized = false;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ResponseConverter $responseConverter,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Process the request through Symfony kernel and return Workerman response.
     * Note: kernel->terminate() is NOT called here - use terminateIfNeeded() after sending response.
     */
    public function __invoke(Request $request): Response
    {
        $this->symfonyRequest = RequestConverter::toSymfonyRequest($request);
        $this->kernel->boot();

        $this->symfonyResponse = $this->kernel->handle($this->symfonyRequest);
        $this->symfonyResponse->prepare($this->symfonyRequest);

        return $this->responseConverter->convert($this->symfonyResponse);
    }

    /**
     * Terminate the kernel if it implements TerminableInterface.
     *
     * This method should be called AFTER the response has been sent to the client,
     * typically in a deferred timer callback to avoid blocking response delivery.
     *
     * After termination, the stored request and response references are cleared
     * to prevent memory leaks and ensure idempotency.
     *
     * @throws \Throwable If kernel termination throws (caller should handle)
     */
    public function terminateIfNeeded(): void
    {
        if ($this->kernel instanceof TerminableInterface
            && $this->symfonyRequest instanceof SymfonyRequest
            && $this->symfonyResponse instanceof SymfonyResponse
        ) {
            try {
                $this->kernel->terminate($this->symfonyRequest, $this->symfonyResponse);
            } finally {
                $this->resetServices();
                $this->symfonyRequest = null;
                $this->symfonyResponse = null;
            }
        }
    }

    private function resetServices(): void
    {
        // Only attempt to reset if we haven't successfully resolved the resetter yet
        if (!$this->servicesResetterInitialized) {
            try {
                $container = $this->kernel->getContainer();
                if ($container->has('services_resetter')) {
                    $resetter = $container->get('services_resetter');
                    if ($resetter instanceof ResetInterface) {
                        $this->servicesResetter = $resetter;
                    }
                }
                // Mark as initialized regardless of success/failure to avoid repeated attempts
                $this->servicesResetterInitialized = true;
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Failed to resolve services_resetter',
                    ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
                );
                // Only mark as initialized if we got an exception - we don't want to retry on every request
                $this->servicesResetterInitialized = true;
            }
        }

        // Only attempt reset if we have a valid resetter
        if ($this->servicesResetter !== null) {
            try {
                $this->servicesResetter->reset();
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Failed to reset services',
                    ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
                );
            }
        }
    }
}
