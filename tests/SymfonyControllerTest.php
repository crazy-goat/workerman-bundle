<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;
use Workerman\Connection\TcpConnection;

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

#[CoversClass(\CrazyGoat\WorkermanBundle\Middleware\SymfonyController::class)]
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

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test content', $response->rawBody());
        $this->assertTrue($kernel->bootCalled, 'Kernel boot should be called');
        $this->assertFalse($kernel->terminateCalled, 'Terminate should not be called during __invoke');

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

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);

        $controller->terminateIfNeeded();

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

        $controller($request, $this->connection);

        $controller->terminateIfNeeded();
        $this->assertSame(1, $kernel->terminateCount, 'Terminate should be called once');

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

    public function testServicesResetterFailureIsLoggedAndDoesNotBreakNextRequest(): void
    {
        $exception = new \RuntimeException('reset failed');

        $resetCalls = 0;
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects($this->exactly(2))
            ->method('reset')
            ->willReturnCallback(static function () use (&$resetCalls, $exception): never {
                ++$resetCalls;

                throw $exception;
            });

        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);
        $container->method('has')->with('services_resetter')->willReturn(true);
        $container->method('get')->with('services_resetter')->willReturn($resetter);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error')->with(
            'Failed to reset services',
            $this->callback(static fn(array $context): bool => $context['exception'] === 'reset failed'
                && $context['file'] === $exception->getFile()
                && $context['line'] === $exception->getLine()),
        );

        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'), $container);
        $controller = new SymfonyController($kernel, $this->createResponseConverter(), $logger);

        for ($requestNumber = 1; $requestNumber <= 2; ++$requestNumber) {
            $response = $controller(new Request("GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n"), $this->connection);

            $this->assertSame(200, $response->getStatusCode(), "Request $requestNumber must succeed");
            $this->assertSame('OK', $response->rawBody(), "Request $requestNumber must return the kernel response");
            $controller->terminateIfNeeded();
        }

        $this->assertSame(2, $resetCalls, 'The resetter must run once per request');
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
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /admin HTTP/1.1\r\n";
        $buffer .= "Host: localhost\r\n";
        $buffer .= "Authorization: Basic " . base64_encode('admin:secret123') . "\r\n";
        $buffer .= "\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertNotNull($kernel->receivedRequest, 'Kernel should have received the request');
        $symfonyRequest = $kernel->receivedRequest;

        $this->assertSame('admin', $symfonyRequest->getUser(), 'Basic auth user should be parsed');
        $this->assertSame('secret123', $symfonyRequest->getPassword(), 'Basic auth password should be parsed');

        $this->assertSame('Basic ' . base64_encode('admin:secret123'), $symfonyRequest->server->get('HTTP_AUTHORIZATION'));
    }

    public function testHeadersAreAvailableInServerBagE2E(): void
    {
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /api/data HTTP/1.1\r\n";
        $buffer .= "Host: api.example.com\r\n";
        $buffer .= "Accept: application/json\r\n";
        $buffer .= "X-Custom-Header: custom-value\r\n";
        $buffer .= "Content-Type: application/json\r\n";
        $buffer .= "\r\n";
        $request = new Request($buffer);

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        $this->assertSame('api.example.com', $symfonyRequest->server->get('HTTP_HOST'));
        $this->assertSame('application/json', $symfonyRequest->server->get('HTTP_ACCEPT'));
        $this->assertSame('custom-value', $symfonyRequest->server->get('HTTP_X_CUSTOM_HEADER'));

        $this->assertSame('application/json', $symfonyRequest->server->get('CONTENT_TYPE'));
        $this->assertNull($symfonyRequest->server->get('HTTP_CONTENT_TYPE'));

        $this->assertSame('api.example.com', $symfonyRequest->headers->get('Host'));
        $this->assertSame('application/json', $symfonyRequest->headers->get('Accept'));
    }

    public function testServerProtocolHasHttpPrefixE2E(): void
    {
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /protocol HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        $this->assertSame('HTTP/1.1', $symfonyRequest->server->get('SERVER_PROTOCOL'));
        $this->assertSame('HTTP/1.1', $symfonyRequest->getProtocolVersion());
    }

    public function testServerProtocolHttp2Prefix(): void
    {
        $symfonyResponse = new SymfonyResponse('OK');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /protocol HTTP/2.0\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        $this->assertStringStartsWith('HTTP/', $symfonyRequest->server->get('SERVER_PROTOCOL'));
    }

    public function testStreamedResponseE2E(): void
    {
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

        $this->assertSame(
            $initialObLevel,
            ob_get_level(),
            'OB level should remain unchanged after test',
        );

        $this->assertSame('', $response->rawBody());
    }

    public function testStreamedResponseHttp11ConnectionCloseEchoesConnectionClose(): void
    {
        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->any())
            ->method('send')
            ->willReturnCallback(function (mixed $data) use (&$sendCalls): void {
                $sendCalls[] = is_string($data) ? $data : (string) $data;
            });

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'chunk';
        });

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $controller = new SymfonyController($kernel, $this->createResponseConverter(true));

        $buffer = "GET /streamed HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request, $this->connection);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame(200, $response->getStatusCode());

        $this->assertNotEmpty($sendCalls, 'StreamedResponse must send its head via the connection');
        $this->assertStringContainsString("Connection: close\r\n", $sendCalls[0], 'HTTP/1.1 Connection: close request must echo Connection: close in the streamed head');
        $this->assertStringContainsString('Transfer-Encoding: chunked', $sendCalls[0]);
    }

    public function testStreamedResponseHttp11KeepAliveDoesNotEchoConnectionClose(): void
    {
        $initialObLevel = ob_get_level();
        $this->connection->context = new \stdClass();

        $sendCalls = [];
        $this->connection
            ->expects($this->any())
            ->method('send')
            ->willReturnCallback(function (mixed $data) use (&$sendCalls): void {
                $sendCalls[] = is_string($data) ? $data : (string) $data;
            });

        $streamedResponse = new StreamedResponse(function (): void {
            echo 'chunk';
        });

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $controller = new SymfonyController($kernel, $this->createResponseConverter(true));

        $buffer = "GET /streamed HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $controller($request, $this->connection);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertNotEmpty($sendCalls);
        $this->assertStringNotContainsString('Connection:', $sendCalls[0], 'HTTP/1.1 keep-alive request must not emit a Connection header in the streamed head');
        $this->assertStringContainsString('Transfer-Encoding: chunked', $sendCalls[0]);
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
        $contentType = $response->getHeader('Content-Type');
        $this->assertIsString($contentType);
        $this->assertStringContainsString('text/event-stream', $contentType);
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
        $symfonyResponse = new SymfonyResponse('plain');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(443);

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        $this->assertNull($symfonyRequest->server->get('HTTPS'));
        $this->assertFalse($symfonyRequest->isSecure());
        $this->assertSame('http', $symfonyRequest->getScheme());
        $this->assertSame(80, $symfonyRequest->getPort());
    }

    public function testHttpE2E(): void
    {
        $symfonyResponse = new SymfonyResponse('insecure');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /insecure HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(80);

        $controller($request, $this->connection);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

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

        $this->assertNull($kernel->receivedRequest);

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

        $controller->terminateIfNeeded();
    }

    public function testSetTrustedHostsIsNotCalledOnCacheHit(): void
    {
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

        $this->assertSame(2, $this->validatedHostsCacheCount($controller), 'reset must be skipped on a cache hit');
    }

    public function testValidatedHostListStaysBoundedForManyDistinctHosts(): void
    {
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
        $cacheCount = $this->validatedHostsCacheCount($controller);
        $memoryAfter = \memory_get_usage();

        $this->assertLessThan(
            128,
            $cacheCount,
            'Symfony Request::$trustedHosts must stay bounded, not grow towards 10000',
        );
        $this->assertLessThan(
            1024 * 1024,
            $memoryAfter - $memoryBefore,
            'Memory growth must be sublinear in the number of distinct hosts',
        );
    }

    public function testHostResolutionStaysBoundedAfterManyDistinctHosts(): void
    {
        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\\.test\\.local$'],
        );

        $controller($this->requestWithHost('cached.test.local'), $this->connection);
        for ($i = 1; $i <= 10000; ++$i) {
            $controller($this->requestWithHost("h{$i}.test.local"), $this->connection);
        }

        $this->assertLessThan(128, $this->validatedHostsCacheCount($controller));

        $start = \hrtime(true);
        for ($i = 0; $i < 1000; ++$i) {
            $controller($this->requestWithHost('cached.test.local'), $this->connection);
        }
        $elapsedNs = \hrtime(true) - $start;

        $this->assertLessThan(
            1_000_000_000,
            $elapsedNs,
            'Repeat-host resolution must stay bounded after 10 000 distinct hosts',
        );
    }

    public function testEvictedHostIsStillValidatedOnLaterRequest(): void
    {
        $kernel = new TestRequestTrackingKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\\.test\\.local$'],
        );

        for ($i = 1; $i <= 64; ++$i) {
            $controller($this->requestWithHost("h{$i}.test.local"), $this->connection);
        }
        $controller($this->requestWithHost('h65.test.local'), $this->connection);

        $this->assertNotContains('h1.test.local', $this->validatedHostsCache($controller));

        $response = $controller($this->requestWithHost('h1.test.local'), $this->connection);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('h1.test.local', $kernel->receivedRequest?->getHost());
    }

    public function testTrustedHostsUnconfiguredDoesNotAccumulate(): void
    {
        $kernel = new TestNonTerminableKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController($kernel, $this->createResponseConverter());

        for ($i = 1; $i <= 100; ++$i) {
            $controller($this->requestWithHost("h{$i}.example.com"), $this->connection);
        }

        $this->assertSame([], SymfonyRequest::getTrustedHosts(), 'no patterns must be set');
        $this->assertSame(0, $this->validatedHostsCacheCount($controller), 'validated-host cache must stay empty');
    }

    public function testTrustedHostsAcceptThenRejectThenAcceptAcrossRequests(): void
    {
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
        $kernel = new TestRequestTrackingKernel(new SymfonyResponse('OK'));
        $controller = new SymfonyController(
            $kernel,
            $this->createResponseConverter(),
            null,
            ['^.+\.example\.com$'],
        );

        SymfonyRequest::setTrustedProxies(['127.0.0.1'], SymfonyRequest::HEADER_X_FORWARDED_HOST);
        try {
            $response = $controller(
                $this->requestWithHost('direct.example.com', 'x1.example.com'),
                $this->connection,
            );
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('x1.example.com', $kernel->receivedRequest?->getHost());

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
            [],
            $this->validatedHostsCache($controller),
            'trusted-proxy path must skip the validated-host cache (reset on every request)',
        );
    }

    private function requestWithHost(string $host, ?string $forwardedHost = null): Request
    {
        $buffer = "GET /test HTTP/1.1\r\nHost: {$host}\r\n";
        if ($forwardedHost !== null) {
            $buffer .= "X-Forwarded-Host: {$forwardedHost}\r\n";
        }

        return new Request($buffer . "\r\n");
    }

    private function validatedHostsCacheCount(SymfonyController $controller): int
    {
        return \count($this->validatedHostsCache($controller));
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
