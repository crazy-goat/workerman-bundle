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
    /**
     * Maximum number of distinct validated hosts cached per worker.
     *
     * Symfony's {@see SymfonyRequest::getHost()} appends every newly-seen
     * matching host to a static list (Request::$trustedHosts) and scans it
     * linearly on every lookup. That list is only ever cleared by
     * {@see SymfonyRequest::setTrustedHosts()}, so with a wildcard trusted
     * host pattern a remote client can grow it without bound — one retained
     * string per distinct matching host, for the worker's lifetime, plus
     * quadratic lookup cost. This constant — not client input — bounds the
     * bundle-side cache and, through the reset-on-miss strategy below,
     * Symfony's internal list as well (issue #560).
     */
    private const MAX_VALIDATED_HOSTS = 64;

    private ?SymfonyRequest $symfonyRequest = null;
    private ?SymfonyResponse $symfonyResponse = null;
    private ?ResetInterface $servicesResetter = null;
    private bool $servicesResetterInitialized = false;

    /**
     * Validated hosts cached per worker, bounded by {@see MAX_VALIDATED_HOSTS}.
     * Only hosts that passed {@see SymfonyRequest::getHost()} are stored; a
     * cache hit skips the {@see SymfonyRequest::setTrustedHosts()} reset.
     *
     * @var array<string, true>
     */
    private array $validatedHosts = [];

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
     *
     * When trusted_hosts is configured, Symfony's global trusted host patterns
     * are applied on the first request and re-applied only when a host is not
     * in the per-worker validated-host cache (a cache miss). Re-applying via
     * setTrustedHosts() resets Symfony's internal Request::$trustedHosts memo,
     * so the memo cache benefits from cross-request reuse while staying bounded
     * by {@see MAX_VALIDATED_HOSTS} — see the constraint in issue #560.
     */
    public function __invoke(Request $request, TcpConnection $connection): Response
    {
        $this->symfonyRequest = RequestConverter::toSymfonyRequest($request);

        // Early validation: reject non-matching Host headers before kernel boot.
        // This prevents the kernel from processing requests with spoofed Host
        // headers. The patterns are re-applied on a cache miss so the reset
        // inside setTrustedHosts() still bounds Symfony's validated-host list.
        //
        // setTrustedHosts() writes global static state; each server has its own
        // controller instance, so a per-instance cache is correct for the
        // single-server case. If a process ever runs several servers with
        // different trusted_hosts, the last writer wins globally (pre-existing
        // limitation of Symfony's static API, not worsened here).
        if ($this->trustedHosts !== []) {
            $host = $this->extractHostForCache($this->symfonyRequest);
            // When the request comes from a trusted proxy, getHost() may
            // validate X-Forwarded-Host instead of the direct Host header
            // used as the cache key, so skip the cache and reset on every
            // request (still bounded, just without the cross-request memo
            // benefit).
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

            // Cache only hosts that passed validation; invalid hosts must not
            // populate the cache (they would never yield a useful hit).
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

            // Compute the request's connection intent here so strategies that
            // send the response directly (StreamedResponseStrategy) can echo
            // Connection: close in the head they build themselves — the central
            // stamping in HttpRequestHandler::sendResponse() is skipped for
            // directly-sent responses (issue #621). Mirrors
            // HttpRequestHandler::shouldCloseConnection().
            $shouldClose = $request->protocolVersion() === '1.0'
                || strcasecmp((string) $request->header('Connection', ''), 'close') === 0;

            return $this->responseConverter->convert($this->symfonyResponse, $connection, $request->protocolVersion(), $this->symfonyRequest->getMethod(), $shouldClose);
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

    /**
     * Extract the normalized host used as the validated-host cache key.
     *
     * Mirrors the host resolution of {@see SymfonyRequest::getHost()} for the
     * non-trusted-proxy path (Host header → SERVER_NAME → SERVER_ADDR), so the
     * cache key matches the value getHost() validates. When a trusted proxy is
     * in use, getHost() reads X-Forwarded-Host instead; the caller skips the
     * cache in that case (see {@see __invoke}).
     */
    private function extractHostForCache(SymfonyRequest $request): string
    {
        $host = $request->headers->get('HOST') ?: $request->server->get('SERVER_NAME') ?: $request->server->get('SERVER_ADDR', '');

        return \strtolower((string) \preg_replace('/:\d+$/', '', \trim((string) $host)));
    }
}
