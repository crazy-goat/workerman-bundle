<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Middleware;

use CrazyGoat\WorkermanBundle\DTO\RequestConverter;
use CrazyGoat\WorkermanBundle\Http\ConnectionIntent;
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
    private const MAX_VALIDATED_HOSTS = 64;

    private ?SymfonyRequest $symfonyRequest = null;
    private ?SymfonyResponse $symfonyResponse = null;
    private ?ResetInterface $servicesResetter = null;
    private bool $servicesResetterInitialized = false;

    /**
     * @var array<string, true>
     */
    private array $validatedHosts = [];

    /**
     * @param string[] $trustedHosts
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ResponseConverter $responseConverter,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $trustedHosts = [],
    ) {
    }

    public function __invoke(Request $request, TcpConnection $connection): Response
    {
        $this->symfonyRequest = RequestConverter::toSymfonyRequest($request);

        if ($this->trustedHosts !== []) {
            $host = $this->extractHostForCache($this->symfonyRequest);
            $cacheable = !$this->symfonyRequest->isFromTrustedProxy();

            if (!$cacheable || !isset($this->validatedHosts[$host])) {
                SymfonyRequest::setTrustedHosts($this->trustedHosts);
            }

            try {
                $this->symfonyRequest->getHost();
            } catch (SuspiciousOperationException) {
                $this->resetServices();
                $this->symfonyRequest = null;

                return new Response(400);
            }

            if ($cacheable && !isset($this->validatedHosts[$host])) {
                $this->validatedHosts[$host] = true;
                if (\count($this->validatedHosts) > self::MAX_VALIDATED_HOSTS) {
                    \array_shift($this->validatedHosts);
                }
            }
        }

        try {
            $this->kernel->boot();
            $this->symfonyResponse = $this->kernel->handle($this->symfonyRequest);
            $this->symfonyResponse->prepare($this->symfonyRequest);

            $shouldClose = ConnectionIntent::shouldClose($request);

            return $this->responseConverter->convert($this->symfonyResponse, $connection, $request->protocolVersion(), $this->symfonyRequest->getMethod(), $shouldClose);
        } catch (\Throwable $e) {
            $this->resetServices();
            $this->symfonyRequest = null;
            $this->symfonyResponse = null;
            throw $e;
        }
    }

    /**
     * @throws \Throwable
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
                $this->servicesResetterInitialized = true;
            } catch (\Throwable $e) {
                $this->logger?->error(
                    'Failed to resolve services_resetter',
                    ['exception' => $e],
                );
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

    private function extractHostForCache(SymfonyRequest $request): string
    {
        $host = $request->headers->get('HOST') ?: $request->server->get('SERVER_NAME') ?: $request->server->get('SERVER_ADDR', '');

        return \strtolower((string) \preg_replace('/:\d+$/', '', \trim((string) $host)));
    }
}
