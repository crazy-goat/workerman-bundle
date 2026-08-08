<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response;

final class SymfonyController
{
    private ?SymfonyRequest $symfonyRequest = null;
    private ?SymfonyResponse $symfonyResponse = null;
    private ?ResetInterface $servicesResetter = null;
    private bool $servicesResetterInitialized = false;

    /**
     * @param string[] $trustedHosts List of regex patterns for trusted hostnames
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ResponseConverter $responseConverter,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $trustedHosts = [],
    ) {
    }

    /**
     * Process the request through Symfony kernel and return Workerman response.
     * Note: kernel->terminate() is NOT called here - use terminateIfNeeded() after sending response.
     * On first invocation with trusted_hosts configured, applies Symfony's global trusted host patterns.
     */
    public function __invoke(Request $request, TcpConnection $connection): Response
    {
        if ($this->trustedHosts !== []) {
            SymfonyRequest::setTrustedHosts($this->trustedHosts);
        }

        $this->symfonyRequest = RequestConverter::toSymfonyRequest($request);

        // Early validation: reject non-matching Host headers before kernel boot
        // This prevents the kernel from processing requests with spoofed Host headers
        if ($this->trustedHosts !== []) {
            try {
                $this->symfonyRequest->getHost();
            } catch (SuspiciousOperationException) {
                $this->resetServices();
                $this->symfonyRequest = null;

                return new Response(400);
            }
        }

        try {
            $this->kernel->boot();
            $this->symfonyResponse = $this->kernel->handle($this->symfonyRequest);
            $this->symfonyResponse->prepare($this->symfonyRequest);

            return $this->responseConverter->convert($this->symfonyResponse, $connection, $request->protocolVersion());
        } catch (\Throwable $e) {
            $this->resetServices();
            $this->symfonyRequest = null;
            $this->symfonyResponse = null;
            throw $e;
        }
    }

    /**
     * Terminate the kernel and/or reset request-scoped services.
     *
     * This method should be called AFTER the response has been sent to the client,
     * typically in a deferred timer callback to avoid blocking response delivery.
     *
     * This method owns the services_resetter reset on every request path:
     * kernel termination runs only for TerminableInterface kernels with both
     * Symfony request and response objects available, while service reset and
     * reference clearing happen unconditionally here (and in the exception /
     * trusted-host-rejection paths inside {@see __invoke}). The guard below
     * keeps this call idempotent so the reset never runs twice per request.
     *
     * After termination, the stored request and response references are cleared
     * to prevent memory leaks and ensure idempotency.
     *
     * @throws \Throwable If kernel termination throws (caller should handle)
     */
    public function terminateIfNeeded(): void
    {
        if (!$this->symfonyRequest instanceof SymfonyRequest && !$this->symfonyResponse instanceof SymfonyResponse) {
            return;
        }

        try {
            if ($this->kernel instanceof TerminableInterface
                && $this->symfonyRequest instanceof SymfonyRequest
                && $this->symfonyResponse instanceof SymfonyResponse
            ) {
                $this->kernel->terminate($this->symfonyRequest, $this->symfonyResponse);
            }
        } finally {
            $this->resetServices();
            $this->symfonyRequest = null;
            $this->symfonyResponse = null;
        }
    }

    private function resetServices(): void
    {
        if (!$this->servicesResetterInitialized) {
            try {
                $container = $this->kernel->getContainer();
                if ($container->has('services_resetter')) {
                    $resetter = $container->get('services_resetter');
                    if ($resetter instanceof ResetInterface) {
                        $this->servicesResetter = $resetter;
                    }
                }
                // We got a definitive answer: resetter exists or doesn't
                $this->servicesResetterInitialized = true;
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Failed to resolve services_resetter',
                    ['exception' => $e],
                );
                // Do NOT set initialized → next request will retry
            }
        }

        try {
            $this->servicesResetter?->reset();
        } catch (\Throwable $e) {
            $this->logger?->error(
                'Failed to reset services',
                ['exception' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
            );
        }
    }
}
