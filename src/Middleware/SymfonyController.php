<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Protocols\Http\Response;

class SymfonyController
{
    private ?SymfonyRequest $symfonyRequest = null;
    private ?SymfonyResponse $symfonyResponse = null;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ResponseConverter $responseConverter,
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
                // Always clear references to prevent memory leaks
                $this->symfonyRequest = null;
                $this->symfonyResponse = null;
            }
        }
    }
}
