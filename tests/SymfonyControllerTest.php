<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Workerman\Connection\TcpConnection;

/**
 * Test kernel that implements both KernelInterface and TerminableInterface
 * with tracking capabilities for testing.
 */
final class TestTerminableKernel implements KernelInterface, TerminableInterface
{
    public bool $bootCalled = false;
    public bool $terminateCalled = false;
    public int $terminateCount = 0;

    public function __construct(private readonly ?SymfonyResponse $responseToReturn = null)
    {
    }

    public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
        $this->terminateCalled = true;
        ++$this->terminateCount;
    }

    public function boot(): void
    {
        $this->bootCalled = true;
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return $this->responseToReturn ?? new SymfonyResponse();
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getStartTime(): float
    {
        return 0.0;
    }
}

/**
 * Test kernel with container that has services_resetter for testing service reset.
 */
final class TestKernelWithServicesResetter implements KernelInterface, TerminableInterface
{
    public bool $bootCalled = false;
    public bool $terminateCalled = false;
    public bool $servicesResetCalled = false;

    private readonly \Symfony\Component\DependencyInjection\ContainerInterface $container;

    public function __construct(private readonly ?SymfonyResponse $responseToReturn = null)
    {
        $kernel = $this;
        $this->container = new class ($kernel) implements \Symfony\Component\DependencyInjection\ContainerInterface {
            /**
             * @var array<string, object>
             */
            private array $services = [];

            public function __construct(private readonly TestKernelWithServicesResetter $kernelRef)
            {
            }

            public function get(string $id, int $invalidBehavior = \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE): object
            {
                if ($id === 'services_resetter') {
                    return new class ($this->kernelRef) implements ResetInterface {
                        public function __construct(private readonly TestKernelWithServicesResetter $kernel)
                        {
                        }

                        public function reset(): void
                        {
                            $this->kernel->servicesResetCalled = true;
                        }
                    };
                }
                throw new \RuntimeException("Service $id not found");
            }

            public function has(string $id): bool
            {
                return $id === 'services_resetter';
            }

            public function set(string $id, ?object $service): void
            {
                if ($service !== null) {
                    $this->services[$id] = $service;
                }
            }

            public function initialized(string $id): bool
            {
                return isset($this->services[$id]);
            }

            /**
             * @return array<string, mixed>|bool|float|int|string|\UnitEnum|null
             */
            public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
            {
                throw new \RuntimeException('Not implemented');
            }

            public function hasParameter(string $name): bool
            {
                return false;
            }

            public function setParameter(string $name, mixed $value): void
            {
                throw new \RuntimeException('Not implemented');
            }

            public function compile(): never
            {
                throw new \RuntimeException('Not implemented');
            }

            public function isCompiled(): bool
            {
                return true;
            }

            public function getParameterBag(): \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface
            {
                throw new \RuntimeException('Not implemented');
            }
        };
    }

    public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
        $this->terminateCalled = true;
    }

    public function boot(): void
    {
        $this->bootCalled = true;
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return $this->responseToReturn ?? new SymfonyResponse();
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        return $this->container;
    }

    public function getStartTime(): float
    {
        return 0.0;
    }
}

/**
 * Test kernel with container that does NOT have services_resetter.
 */
final class TestKernelWithoutServicesResetter implements KernelInterface, TerminableInterface
{
    public bool $bootCalled = false;
    public bool $terminateCalled = false;

    private readonly \Symfony\Component\DependencyInjection\ContainerInterface $container;

    public function __construct(private readonly ?SymfonyResponse $responseToReturn = null)
    {
        $this->container = new class implements \Symfony\Component\DependencyInjection\ContainerInterface {
            /**
             * @var array<string, object>
             */
            private array $services = [];

            public function get(string $id, int $invalidBehavior = \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE): object
            {
                throw new \RuntimeException("Service $id not found");
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function set(string $id, ?object $service): void
            {
                if ($service !== null) {
                    $this->services[$id] = $service;
                }
            }

            public function initialized(string $id): bool
            {
                return isset($this->services[$id]);
            }

            /**
             * @return array<string, mixed>|bool|float|int|string|\UnitEnum|null
             */
            public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
            {
                throw new \RuntimeException('Not implemented');
            }

            public function hasParameter(string $name): bool
            {
                return false;
            }

            public function setParameter(string $name, mixed $value): void
            {
                throw new \RuntimeException('Not implemented');
            }

            public function compile(): never
            {
                throw new \RuntimeException('Not implemented');
            }

            public function isCompiled(): bool
            {
                return true;
            }

            public function getParameterBag(): \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface
            {
                throw new \RuntimeException('Not implemented');
            }
        };
    }

    public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
        $this->terminateCalled = true;
    }

    public function boot(): void
    {
        $this->bootCalled = true;
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return $this->responseToReturn ?? new SymfonyResponse();
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        return $this->container;
    }

    public function getStartTime(): float
    {
        return 0.0;
    }
}

/**
 * Test kernel that only implements KernelInterface (not TerminableInterface).
 */
final class TestNonTerminableKernel implements KernelInterface
{
    public bool $bootCalled = false;

    public function __construct(
        private readonly ?SymfonyResponse $responseToReturn = null,
        private readonly ?\Symfony\Component\DependencyInjection\ContainerInterface $container = null,
    ) {
    }

    public function boot(): void
    {
        $this->bootCalled = true;
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return $this->responseToReturn ?? new SymfonyResponse();
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        if (!$this->container instanceof \Symfony\Component\DependencyInjection\ContainerInterface) {
            throw new \RuntimeException('Not implemented');
        }

        return $this->container;
    }

    public function getStartTime(): float
    {
        return 0.0;
    }
}

/**
 * Test kernel that tracks the received request for E2E testing.
 */
final class TestRequestTrackingKernel implements KernelInterface
{
    public bool $bootCalled = false;
    public ?\Symfony\Component\HttpFoundation\Request $receivedRequest = null;

    public function __construct(private readonly ?SymfonyResponse $responseToReturn = null)
    {
    }

    public function boot(): void
    {
        $this->bootCalled = true;
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        $this->receivedRequest = $request;
        return $this->responseToReturn ?? new SymfonyResponse();
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getStartTime(): float
    {
        return 0.0;
    }
}

/**
 * Test kernel that throws during handle() to simulate crash.
 */
final class TestThrowingKernel implements KernelInterface, TerminableInterface
{
    public bool $bootCalled = false;
    public bool $terminateCalled = false;

    public function __construct(
        private readonly \Throwable $exception,
        private readonly ?\Symfony\Component\DependencyInjection\ContainerInterface $container = null,
    ) {
    }

    public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
        $this->terminateCalled = true;
    }

    public function boot(): void
    {
        $this->bootCalled = true;
    }

    public function shutdown(): void
    {
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(\Symfony\Component\Config\Loader\LoaderInterface $loader): void
    {
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = 1, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        throw $this->exception;
    }

    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): \Symfony\Component\HttpKernel\Bundle\BundleInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function locateResource(string $name): string
    {
        return '';
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return true;
    }

    public function getProjectDir(): string
    {
        return '/tmp';
    }

    public function getCacheDir(): string
    {
        return '/tmp/cache';
    }

    public function getBuildDir(): string
    {
        return '/tmp/build';
    }

    public function getShareDir(): ?string
    {
        return null;
    }

    public function getLogDir(): string
    {
        return '/tmp/log';
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        if (!$this->container instanceof \Symfony\Component\DependencyInjection\ContainerInterface) {
            throw new \RuntimeException('Not implemented');
        }

        return $this->container;
    }

    public function getStartTime(): float
    {
        return 0.0;
    }
}

/**
 * @covers \CrazyGoat\WorkermanBundle\Middleware\SymfonyController
 */
final class SymfonyControllerTest extends TestCase
{
    private TcpConnection&\PHPUnit\Framework\MockObject\MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(TcpConnection::class);

        SymfonyRequest::setTrustedHosts([]);
    }

    private function createResponseConverter(bool $withStreamedStrategy = false): ResponseConverter
    {
        // IMPORTANT: StreamedResponseStrategy MUST come before DefaultResponseStrategy
        // because DefaultResponseStrategy::supports() returns true for ALL responses.
        $strategies = [];
        if ($withStreamedStrategy) {
            $strategies[] = new StreamedResponseStrategy();
        }
        $strategies[] = new DefaultResponseStrategy();

        return new ResponseConverter($strategies);
    }

    public function testTerminateIfNeededCallsKernelTerminate(): void
    {
        $symfonyResponse = new SymfonyResponse('test content');
        $kernel = new TestTerminableKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        // Invoke controller - this should NOT call terminate
        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test content', $response->rawBody());
        $this->assertTrue($kernel->bootCalled, 'Kernel boot should be called');
        $this->assertFalse($kernel->terminateCalled, 'Terminate should not be called during __invoke');

        // Now call terminateIfNeeded - this SHOULD call terminate
        $controller->terminateIfNeeded();

        $this->assertTrue($kernel->terminateCalled, 'Terminate should be called after terminateIfNeeded');
    }

    public function testTerminateIfNeededDoesNothingWhenKernelIsNotTerminable(): void
    {
        $symfonyResponse = new SymfonyResponse();
        $kernel = new TestNonTerminableKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        // Invoke controller
        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);

        // This should not throw or cause any issues
        $controller->terminateIfNeeded();

        // No assertions needed - if we get here without error, test passes
        $this->addToAssertionCount(1);
    }

    public function testTerminateIfNeededDoesNothingWhenCalledTwice(): void
    {
        $symfonyResponse = new SymfonyResponse();
        $kernel = new TestTerminableKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        // Invoke controller
        $controller($request, $this->connection);

        // First call to terminateIfNeeded - should trigger terminate
        $controller->terminateIfNeeded();
        $this->assertSame(1, $kernel->terminateCount, 'Terminate should be called once');

        // Second call should not trigger terminate again (request/response are nullified)
        $controller->terminateIfNeeded();
        $this->assertSame(1, $kernel->terminateCount, 'Terminate should not be called twice');
    }

    public function testServicesResetterRunsForNonTerminableKernel(): void
    {
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->once())->method('reset');
        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->with('services_resetter')->willReturn(true);
        $container->method('get')->with('services_resetter')->willReturn($resetter);

        $kernel = new TestNonTerminableKernel(new SymfonyResponse(), $container);
        $controller = new SymfonyController($kernel, $this->createResponseConverter());

        $controller(new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n"), $this->connection);
        $controller->terminateIfNeeded();
    }

    public function testResponseHeadersAreConverted(): void
    {
        $symfonyResponse = new SymfonyResponse(
            content: 'test',
            headers: [
                'Content-Type' => 'application/json',
                'X-Custom-Header' => 'custom-value',
            ],
        );
        $kernel = new TestNonTerminableKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        // All headers are normalized to proper case (e.g., Content-Type, X-Custom-Header)
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame('custom-value', $response->getHeader('X-Custom-Header'));
    }

    public function testResponseStatusCodeIsPreserved(): void
    {
        $symfonyResponse = new SymfonyResponse(
            content: 'error',
            status: \Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR,
        );
        $kernel = new TestNonTerminableKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testBasicAuthHeadersAreParsedInServerBag(): void
    {
        // E2E test: Workerman Request → RequestConverter → SymfonyController → Symfony Request
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Create request with Basic Auth header
        $buffer = "GET /admin HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Authorization: Basic " . base64_encode('admin:secret123') . "\r\n";
        $buffer .= "\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        // Verify response is correct
        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        // Verify kernel received request with parsed auth credentials
        $this->assertNotNull($kernel->receivedRequest, 'Kernel should have received the request');
        $symfonyRequest = $kernel->receivedRequest;

        // These should work because HTTP_AUTHORIZATION is now in server bag
        $this->assertSame('admin', $symfonyRequest->getUser(), 'Basic auth user should be parsed');
        $this->assertSame('secret123', $symfonyRequest->getPassword(), 'Basic auth password should be parsed');

        // Also verify server bag has the header
        $this->assertSame('Basic ' . base64_encode('admin:secret123'), $symfonyRequest->server->get('HTTP_AUTHORIZATION'));
    }

    public function testHeadersAreAvailableInServerBagE2E(): void
    {
        // E2E test verifying headers are properly set in server bag for the whole stack
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /api/data HTTP/1.1\r\n";
        $buffer .= "Host: api.example.com\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "X-Custom-Header: custom-value\r\n";
        $buffer .= "Content-Type: application/json\r\n";  // Will be converted to CONTENT_TYPE
        $buffer .= "\r\n";
        $request = new Request($buffer);

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // Headers should be in server bag with HTTP_ prefix
        $this->assertSame('api.example.com', $symfonyRequest->server->get('HTTP_HOST'));
        $this->assertSame('application/json', $symfonyRequest->server->get('HTTP_ACCEPT'));
        $this->assertSame('custom-value', $symfonyRequest->server->get('HTTP_X_CUSTOM_HEADER'));

        // Content-Type should be in server bag without HTTP_ prefix (CGI convention)
        $this->assertSame('application/json', $symfonyRequest->server->get('CONTENT_TYPE'));
        $this->assertNull($symfonyRequest->server->get('HTTP_CONTENT_TYPE'));

        // Headers should also be accessible via HeaderBag
        $this->assertSame('api.example.com', $symfonyRequest->headers->get('Host'));
        $this->assertSame('application/json', $symfonyRequest->headers->get('Accept'));
    }

    public function testServerProtocolHasHttpPrefixE2E(): void
    {
        // E2E test: Verify SERVER_PROTOCOL includes HTTP/ prefix (#60)
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Test HTTP/1.1
        $buffer = "GET /protocol HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // SERVER_PROTOCOL should include HTTP/ prefix
        $this->assertSame('HTTP/1.1', $symfonyRequest->server->get('SERVER_PROTOCOL'));
        // getProtocolVersion() should also return correct value
        $this->assertSame('HTTP/1.1', $symfonyRequest->getProtocolVersion());
    }

    public function testServerProtocolHttp2Prefix(): void
    {
        // E2E test: Verify HTTP/2.0 protocol version is handled correctly
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Test HTTP/2.0
        $buffer = "GET /protocol HTTP/2.0\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // Verify prefix logic works for HTTP/2.0
        $this->assertStringStartsWith('HTTP/', $symfonyRequest->server->get('SERVER_PROTOCOL'));
    }

    public function testStreamedResponseE2E(): void
    {
        // E2E test: Verify StreamedResponse content is properly streamed via connection
        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'chunk1';
            echo 'chunk2';
            echo 'chunk3';
        });

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /streamed HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        // Debug: check OB level didn't change
        $this->assertSame(
            $initialObLevel,
            ob_get_level(),
            'OB level should remain unchanged after test',
        );

        // Content is sent directly via $connection->send(), not buffered in response
        $this->assertSame('', $response->rawBody());
    }

    public function testStreamedResponseWithStatusCode(): void
    {
        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $streamedResponse = new StreamedResponse(
            function (): void {
                echo 'streamed content';
            },
            SymfonyResponse::HTTP_ACCEPTED,
        );

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /streamed HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', $response->rawBody());
    }

    public function testStreamedResponseWithHeaders(): void
    {
        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $streamedResponse = new StreamedResponse(
            function (): void {
                echo 'streaming data';
            },
            SymfonyResponse::HTTP_OK,
            ['Content-Type' => 'text/event-stream', 'X-Stream' => 'true'],
        );

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /sse HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        // Content-Type may have charset added by Symfony
        $contentType = $response->getHeader('Content-Type');
        $this->assertIsString($contentType);
        $this->assertStringContainsString('text/event-stream', $contentType);
        // Headers are normalized to proper case
        $this->assertSame('true', $response->getHeader('X-Stream'));
        $this->assertSame('', $response->rawBody());
    }

    public function testStreamedResponseEmptyContent(): void
    {
        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $streamedResponse = new StreamedResponse(function (): void {
            // Echo nothing
        });

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /empty-stream HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame('', $response->rawBody());
    }

    public function testStreamedJsonResponseE2E(): void
    {
        if (!class_exists(\Symfony\Component\HttpFoundation\StreamedJsonResponse::class)) {
            $this->markTestSkipped('StreamedJsonResponse requires Symfony 7.1+');
        }

        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();
        $this->connection
            ->expects($this->any())
            ->method('send');

        $streamedJsonResponse = new \Symfony\Component\HttpFoundation\StreamedJsonResponse([
            'items' => [1, 2, 3],
        ]);

        $kernel = new TestNonTerminableKernel($streamedJsonResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /streamed-json HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->rawBody());
    }

    public function testHttpsE2E(): void
    {
        // E2E test: Verify port 443 without SSL transport is HTTP (#64)
        $symfonyResponse = new SymfonyResponse('plain');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Port 443 alone should NOT imply HTTPS — must use SSL transport
        $buffer = "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(443);

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // Port 443 without SSL transport = HTTP (Symfony defaults to 80 for HTTP scheme)
        $this->assertNull($symfonyRequest->server->get('HTTPS'));
        $this->assertFalse($symfonyRequest->isSecure());
        $this->assertSame('http', $symfonyRequest->getScheme());
        $this->assertSame(80, $symfonyRequest->getPort());
    }

    public function testHttpE2E(): void
    {
        // E2E test: Verify plain HTTP (port 80) does NOT set HTTPS flag (#64)
        $symfonyResponse = new SymfonyResponse('insecure');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Plain HTTP request on port 80, no X-Forwarded-Proto header
        $buffer = "GET /insecure HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(80);

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // HTTPS should NOT be set for plain HTTP
        $this->assertNull($symfonyRequest->server->get('HTTPS'));
        $this->assertFalse($symfonyRequest->isSecure());
        $this->assertSame('http', $symfonyRequest->getScheme());
        $this->assertSame(80, $symfonyRequest->getPort());
    }

    public function testHttpsE2EWithSslTransport(): void
    {
        $symfonyResponse = new SymfonyResponse('secure');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /secure HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(443, 'ssl');

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertTrue($symfonyRequest->isSecure());
        $this->assertSame('https', $symfonyRequest->getScheme());
        $this->assertSame(443, $symfonyRequest->getPort());
    }

    private function createMockConnection(int $port, string $transport = 'tcp'): \Workerman\Connection\TcpConnection
    {
        return new class ($port, $transport) extends \Workerman\Connection\TcpConnection {
            public function __construct(private readonly int $port, string $transport)
            {
                $this->remoteAddress = '192.168.1.1:12345';
                $this->transport = $transport;
            }

            public function getLocalPort(): int
            {
                return $this->port;
            }

            public function getLocalIp(): string
            {
                return '0.0.0.0';
            }

            public function getRemoteIp(): string
            {
                return '192.168.1.1';
            }

            public function getRemotePort(): int
            {
                return 12345;
            }
        };
    }

    public function testReferencesAreNullifiedWhenKernelHandleThrows(): void
    {
        $kernel = new TestThrowingKernel(new \RuntimeException('kernel crash'));
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        try {
            $controller($request, $this->connection);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('kernel crash', $e->getMessage());
        }

        $controller->terminateIfNeeded();

        $this->assertFalse($kernel->terminateCalled, 'terminate() must not be called when __invoke threw — references should be null');
    }

    public function testServicesResetterRunsWhenKernelHandleThrows(): void
    {
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->once())->method('reset');
        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->with('services_resetter')->willReturn(true);
        $container->method('get')->with('services_resetter')->willReturn($resetter);
        $kernel = new TestThrowingKernel(new \RuntimeException('kernel crash'), $container);
        $controller = new SymfonyController($kernel, $this->createResponseConverter());

        try {
            $controller(new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n"), $this->connection);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('kernel crash', $e->getMessage());
        }

        $controller->terminateIfNeeded();
    }

    public function testTerminateIfNeededCallsServicesResetter(): void
    {
        $symfonyResponse = new SymfonyResponse('test content');
        $kernel = new TestKernelWithServicesResetter($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertTrue($kernel->bootCalled, 'Kernel boot should be called');
        $this->assertFalse($kernel->terminateCalled, 'Terminate should not be called during __invoke');
        $this->assertFalse($kernel->servicesResetCalled, 'Services reset should not be called during __invoke');

        $controller->terminateIfNeeded();

        $this->assertTrue($kernel->terminateCalled, 'Terminate should be called after terminateIfNeeded');
        $this->assertTrue($kernel->servicesResetCalled, 'Services reset should be called after terminateIfNeeded');
    }

    public function testTerminateIfNeededDoesNotFailWhenServicesResetterNotAvailable(): void
    {
        $symfonyResponse = new SymfonyResponse('test content');
        $kernel = new TestKernelWithoutServicesResetter($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $controller($request, $this->connection);

        $controller->terminateIfNeeded();

        $this->assertTrue($kernel->terminateCalled, 'Terminate should be called');
    }

    public function testTrustedHostsAllowsMatchingHost(): void
    {
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter, null, ['^example\.com$']);

        $buffer = "GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('example.com', $kernel->receivedRequest?->getHost());
    }

    public function testTrustedHostsRejectsNonMatchingHost(): void
    {
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter, null, ['^example\.com$']);

        $buffer = "GET /test HTTP/1.1\r\nHost: attacker.com\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(400, $response->getStatusCode());

        // Kernel should NOT have handled the request (early validation before boot)
        $this->assertNull($kernel->receivedRequest);

        // Verify the static trusted hosts are still active for subsequent requests
        $suspiciousRequest = SymfonyRequest::create('http://attacker.com/');
        $this->expectException(SuspiciousOperationException::class);
        $suspiciousRequest->getHost();
    }

    public function testTrustedHostRejectionResetsServicesAndClearsReferences(): void
    {
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->once())->method('reset');
        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->with('services_resetter')->willReturn(true);
        $container->method('get')->with('services_resetter')->willReturn($resetter);
        $kernel = new TestNonTerminableKernel(new SymfonyResponse(), $container);
        $controller = new SymfonyController($kernel, $this->createResponseConverter(), null, ['^example\\.com$']);

        $response = $controller(new Request("GET /test HTTP/1.1\r\nHost: attacker.com\r\n\r\n"), $this->connection);
        $this->assertSame(400, $response->getStatusCode());

        // A second lifecycle call must be a no-op after the early return cleanup.
        $controller->terminateIfNeeded();
    }

    public function testSetTrustedHostsIsNotCalledOnCacheHit(): void
    {
        // setTrustedHosts() must be skipped on a validated-host cache hit.
        // The H1, H2, H1 sequence proves it: on the third request (H1, a cache
        // hit) the reset is skipped, so Symfony's internal Request::$trustedHosts
        // still holds H2 from request 2; getHost() does not find H1 in it,
        // re-validates via the pattern, and appends H1 → 2 entries. With the
        // old per-request reset, request 3 would wipe the list and re-add only
        // H1 → 1 entry. Two entries prove the reset was skipped.
        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\\.test\\.local$'],
        );

        $this->assertSame(200, $controller($this->requestWithHost('h1.test.local'), $this->connection)->getStatusCode());
        $this->assertSame(200, $controller($this->requestWithHost('h2.test.local'), $this->connection)->getStatusCode());
        $this->assertSame(200, $controller($this->requestWithHost('h1.test.local'), $this->connection)->getStatusCode());

        $this->assertSame(2, $this->trustedHostsCacheCount(), 'reset must be skipped on a cache hit');
    }

    public function testValidatedHostListStaysBoundedForManyDistinctHosts(): void
    {
        // Regression test for the unbounded memory leak this fix could
        // introduce (issue #560): with a wildcard pattern, drive 10 000
        // requests carrying distinct matching hosts. The naive fix (stop
        // resetting without a bound) would let Symfony's internal
        // Request::$trustedHosts grow to 10 000 entries. The bounded cache
        // keeps it small — each cache miss resets it via setTrustedHosts().
        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\\.test\\.local$'],
        );

        $memoryBefore = \memory_get_usage();
        for ($i = 1; $i <= 10000; ++$i) {
            $controller($this->requestWithHost("h{$i}.test.local"), $this->connection);
        }
        $cacheCount = $this->trustedHostsCacheCount();
        $memoryAfter = \memory_get_usage();

        $this->assertLessThan(
            128,
            $cacheCount,
            'Symfony Request::$trustedHosts must stay bounded, not grow towards 10000',
        );
        // Memory must not grow linearly with the number of distinct hosts.
        // The bounded cache retains at most 64 host strings (~3 KB); the
        // generous 1 MB ceiling catches an unbounded leak while tolerating
        // PHP allocator noise.
        $this->assertLessThan(
            1024 * 1024,
            $memoryAfter - $memoryBefore,
            'Memory growth must be sublinear in the number of distinct hosts',
        );
    }

    public function testHostResolutionStaysBoundedAfterManyDistinctHosts(): void
    {
        // Host resolution (the in_array fast path in Request::getHost()) must
        // stay O(bound), not O(distinct hosts seen). After 10 000 distinct
        // hosts, a repeat cached-host lookup must be as fast as after a
        // handful — because Request::$trustedHosts is bounded by the cache.
        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\\.test\\.local$'],
        );

        // Warm the cache with a known host, then flood with 10 000 distinct hosts.
        $controller($this->requestWithHost('cached.test.local'), $this->connection);
        for ($i = 1; $i <= 10000; ++$i) {
            $controller($this->requestWithHost("h{$i}.test.local"), $this->connection);
        }

        // The validated-host list is bounded, so a repeat request is O(bound).
        $this->assertLessThan(128, $this->trustedHostsCacheCount());

        $start = \hrtime(true);
        for ($i = 0; $i < 1000; ++$i) {
            $controller($this->requestWithHost('cached.test.local'), $this->connection);
        }
        $elapsedNs = \hrtime(true) - $start;

        // 1000 repeat requests must complete in well under a second. With an
        // unbounded 10 000-entry in_array scan the cost would be quadratic;
        // the bounded cache keeps it flat.
        $this->assertLessThan(
            1_000_000_000,
            $elapsedNs,
            'Repeat-host resolution must stay bounded after 10 000 distinct hosts',
        );
    }

    public function testEvictedHostIsStillValidatedOnLaterRequest(): void
    {
        // A host that was validated, then evicted from the bounded cache, must
        // still be validated correctly on a later request (cache miss →
        // setTrustedHosts reset → pattern re-match).
        $kernel = new TestRequestTrackingKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\\.test\\.local$'],
        );

        // Fill the cache (MAX_VALIDATED_HOSTS = 64) with distinct hosts.
        for ($i = 1; $i <= 64; ++$i) {
            $controller($this->requestWithHost("h{$i}.test.local"), $this->connection);
        }
        // The 65th distinct host evicts the oldest (h1) from the cache.
        $controller($this->requestWithHost('h65.test.local'), $this->connection);

        // h1 is now evicted — verify via the controller's private cache.
        $this->assertNotContains('h1.test.local', $this->validatedHostsCache($controller));

        // Re-request the evicted host: it is a cache miss, so setTrustedHosts()
        // resets and getHost() re-validates via the pattern — must still pass.
        $response = $controller($this->requestWithHost('h1.test.local'), $this->connection);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('h1.test.local', $kernel->receivedRequest?->getHost());
    }

    public function testTrustedHostsUnconfiguredDoesNotAccumulate(): void
    {
        // With trusted_hosts unconfigured, the controller must never call
        // setTrustedHosts(), so neither patterns nor the validated-host cache
        // accumulate — unchanged from before the fix.
        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController($kernel, $this->createResponseConverter());

        for ($i = 1; $i <= 100; ++$i) {
            $controller($this->requestWithHost("h{$i}.example.com"), $this->connection);
        }

        $this->assertSame([], SymfonyRequest::getTrustedHosts(), 'no patterns must be set');
        $this->assertSame(0, $this->trustedHostsCacheCount(), 'validated-host cache must stay empty');
    }

    public function testTrustedHostsAcceptThenRejectThenAcceptAcrossRequests(): void
    {
        // Host rejection/acceptance must be unchanged across multiple sequential
        // requests on the same controller instance: matching → 200, non-matching
        // → 400, matching again → 200.
        $kernel = new TestRequestTrackingKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^example\\.com$'],
        );

        $this->assertSame(200, $controller($this->requestWithHost('example.com'), $this->connection)->getStatusCode());
        $this->assertSame(400, $controller($this->requestWithHost('attacker.com'), $this->connection)->getStatusCode());
        $this->assertSame(200, $controller($this->requestWithHost('example.com'), $this->connection)->getStatusCode());
        $this->assertSame('example.com', $kernel->receivedRequest?->getHost());
    }

    public function testTrustedProxySkipsCacheAndResetsEveryRequest(): void
    {
        // When the request comes from a trusted proxy, getHost() validates
        // the value of X-Forwarded-Host rather than the direct Host header
        // used as the cache key. The controller must therefore skip the
        // validated-host cache and keep calling setTrustedHosts() on every
        // request — that reset is what keeps Symfony's internal list bounded
        // (issue #560 constraint).
        $kernel = new TestRequestTrackingKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\.example\.com$'],
        );

        SymfonyRequest::setTrustedProxies(['127.0.0.1'], SymfonyRequest::HEADER_X_FORWARDED_HOST);
        try {
            // First request: host resolves from X-Forwarded-Host, not Host.
            $response = $controller(
                $this->requestWithHost('direct.example.com', 'x1.example.com'),
                $this->connection,
            );
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('x1.example.com', $kernel->receivedRequest?->getHost());

            // Second request with a different X-Forwarded-Host: the cache must
            // be skipped again, so Symfony's internal list stays at one entry
            // (reset on every request) instead of accumulating.
            $response = $controller(
                $this->requestWithHost('direct.example.com', 'x2.example.com'),
                $this->connection,
            );
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('x2.example.com', $kernel->receivedRequest->getHost());
        } finally {
            SymfonyRequest::setTrustedProxies([], 0);
        }

        $this->assertSame(
            1,
            $this->trustedHostsCacheCount(),
            'trusted-proxy path must reset the validated-host list on every request',
        );
        $this->assertSame([], $this->validatedHostsCache($controller), 'bundle cache must stay empty behind a trusted proxy');
    }

    private function requestWithHost(string $host, ?string $forwardedHost = null): Request
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: {$host}\r\n";
        if ($forwardedHost !== null) {
            $buffer .= "X-Forwarded-Host: {$forwardedHost}\r\n";
        }

        return new Request($buffer . "\r\n");
    }

    /**
     * Read the size of Symfony's internal validated-host cache (Request::$trustedHosts).
     *
     * This protected static list is the one that grows without bound with the
     * naive fix; getTrustedHosts() returns the patterns, not this cache, so
     * reflection is the only way to observe it.
     */
    private function trustedHostsCacheCount(): int
    {
        $property = (new \ReflectionClass(SymfonyRequest::class))->getProperty('trustedHosts');
        $value = $property->getValue();
        \assert(\is_array($value));

        return \count($value);
    }

    /**
     * @return array<string, true>
     */
    private function validatedHostsCache(SymfonyController $controller): array
    {
        $property = (new \ReflectionClass(SymfonyController::class))->getProperty('validatedHosts');
        $value = $property->getValue($controller);
        \assert(\is_array($value));

        return $value;
    }
}
