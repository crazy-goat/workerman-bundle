<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\StreamedResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Test kernel that implements both KernelInterface and TerminableInterface
 * with tracking capabilities for testing.
 */
final class TestTerminableKernel implements KernelInterface, TerminableInterface
{
    public bool $bootCalled = false;
    public bool $terminateCalled = false;
    public int $terminateCount = 0;
    public bool $servicesResetCalled = false;

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
             * @return array|bool|string|int|float|\UnitEnum|null
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
 * @covers \CrazyGoat\WorkermanBundle\Middleware\SymfonyController
 */
final class SymfonyControllerTest extends TestCase
{
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
        $response = $controller($request);

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
        $response = $controller($request);

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
        $controller($request);

        // First call to terminateIfNeeded - should trigger terminate
        $controller->terminateIfNeeded();
        $this->assertSame(1, $kernel->terminateCount, 'Terminate should be called once');

        // Second call should not trigger terminate again (request/response are nullified)
        $controller->terminateIfNeeded();
        $this->assertSame(1, $kernel->terminateCount, 'Terminate should not be called twice');
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

        $response = $controller($request);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        // Symfony normalizes headers to lowercase, Workerman stores them as-is
        // Content-Type is in FIX_HEADERS so it gets capitalized
        $this->assertSame(['application/json'], $response->getHeader('Content-Type'));
        // X-Custom-Header is normalized to lowercase by Symfony
        $this->assertSame(['custom-value'], $response->getHeader('x-custom-header'));
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

        $response = $controller($request);

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

        $response = $controller($request);

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

        $controller($request);

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
        $controller($request);

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
        $controller($request);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // Verify prefix logic works for HTTP/2.0
        $this->assertStringStartsWith('HTTP/', $symfonyRequest->server->get('SERVER_PROTOCOL'));
    }

    public function testStreamedResponseE2E(): void
    {
        // E2E test: Verify StreamedResponse content is properly captured
        $initialObLevel = ob_get_level();
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

        $response = $controller($request);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        // Debug: check OB level didn't change
        $this->assertSame(
            $initialObLevel,
            ob_get_level(),
            'OB level should remain unchanged after test',
        );

        // StreamedResponse content should be captured via output buffering
        $this->assertSame('chunk1chunk2chunk3', $response->rawBody());
    }

    public function testStreamedResponseWithStatusCode(): void
    {
        $initialObLevel = ob_get_level();
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

        $response = $controller($request);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('streamed content', $response->rawBody());
    }

    public function testStreamedResponseWithHeaders(): void
    {
        $initialObLevel = ob_get_level();
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

        $response = $controller($request);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        // Content-Type may have charset added by Symfony
        $this->assertStringContainsString('text/event-stream', $response->getHeader('Content-Type')[0] ?? '');
        // Headers are normalized to lowercase by Symfony/Workerman
        $this->assertSame(['true'], $response->getHeader('x-stream'));
        $this->assertSame('streaming data', $response->rawBody());
    }

    public function testStreamedResponseEmptyContent(): void
    {
        $initialObLevel = ob_get_level();
        $streamedResponse = new StreamedResponse(function (): void {
            // Echo nothing
        });

        $kernel = new TestNonTerminableKernel($streamedResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /empty-stream HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame('', $response->rawBody());
    }

    public function testStreamedJsonResponseE2E(): void
    {
        if (!class_exists(\Symfony\Component\HttpFoundation\StreamedJsonResponse::class)) {
            $this->markTestSkipped('StreamedJsonResponse requires Symfony 7.1+');
        }

        $initialObLevel = ob_get_level();

        $streamedJsonResponse = new \Symfony\Component\HttpFoundation\StreamedJsonResponse([
            'items' => [1, 2, 3],
        ]);

        $kernel = new TestNonTerminableKernel($streamedJsonResponse);
        $responseConverter = $this->createResponseConverter(true);

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /streamed-json HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request);

        $this->assertSame($initialObLevel, ob_get_level(), 'OB level should remain unchanged after test');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertJson($response->rawBody());
        $this->assertSame('{"items":[1,2,3]}', $response->rawBody());
    }

    public function testHttpsE2E(): void
    {
        // E2E test: Verify HTTPS detection works through full stack (#64)
        $symfonyResponse = new SymfonyResponse('secure');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Create a mock connection with SSL port (443)
        $buffer = "GET /secure HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(443);

        $controller($request);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // HTTPS should be detected from port 443
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertTrue($symfonyRequest->isSecure());
        $this->assertSame('https', $symfonyRequest->getScheme());
        $this->assertSame(443, $symfonyRequest->getPort());
    }

    public function testXForwardedProtoHttpsE2E(): void
    {
        // E2E test: Verify X-Forwarded-Proto header is detected (#64)
        $symfonyResponse = new SymfonyResponse('proxied');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Create request with X-Forwarded-Proto header (connection port is regular 80)
        $buffer = "GET /proxied HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-Proto: https\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(80);

        $controller($request);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // HTTPS should be detected from X-Forwarded-Proto header
        // Note: when isHttps is true, SERVER_PORT defaults to 443
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertTrue($symfonyRequest->isSecure());
        $this->assertSame('https', $symfonyRequest->getScheme());
        $this->assertSame(443, $symfonyRequest->getPort());
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

        $controller($request);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // HTTPS should NOT be set for plain HTTP
        $this->assertNull($symfonyRequest->server->get('HTTPS'));
        $this->assertFalse($symfonyRequest->isSecure());
        $this->assertSame('http', $symfonyRequest->getScheme());
        $this->assertSame(80, $symfonyRequest->getPort());
    }

    public function testXForwardedProtoCaseInsensitiveE2E(): void
    {
        // E2E test: Verify X-Forwarded-Proto works with uppercase values (#64)
        $symfonyResponse = new SymfonyResponse('proxied');
        $kernel = new TestRequestTrackingKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        // Test with uppercase HTTPS value
        $buffer = "GET /proxied HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-Proto: HTTPS\r\n\r\n";
        $request = new Request($buffer);
        $request->connection = $this->createMockConnection(80);

        $controller($request);

        $this->assertNotNull($kernel->receivedRequest);
        $symfonyRequest = $kernel->receivedRequest;

        // HTTPS should be detected from uppercase X-Forwarded-Proto header
        $this->assertSame('on', $symfonyRequest->server->get('HTTPS'));
        $this->assertTrue($symfonyRequest->isSecure());
        $this->assertSame('https', $symfonyRequest->getScheme());
    }

    private function createMockConnection(int $port): \Workerman\Connection\TcpConnection
    {
        return new class ($port) extends \Workerman\Connection\TcpConnection {
            public function __construct(private readonly int $port)
            {
                $this->remoteAddress = '192.168.1.1:12345';
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

    public function testTerminateIfNeededCallsServicesResetter(): void
    {
        $symfonyResponse = new SymfonyResponse('test content');
        $kernel = new TestKernelWithServicesResetter($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $response = $controller($request);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);
        $this->assertTrue($kernel->bootCalled, 'Kernel boot should be called');
        $this->assertFalse($kernel->terminateCalled, 'Terminate should not be called during __invoke');
        $this->assertFalse($kernel->servicesResetCalled, 'Services reset should not be called during __invoke');

        $controller->terminateIfNeeded();

        $this->assertTrue($kernel->terminateCalled, 'Terminate should be called after terminateIfNeeded');
        $this->assertTrue($kernel->servicesResetCalled, 'Services reset should be called after terminateIfNeeded');
    }

    public function testTerminateIfNeededDoesNotCallServicesResetterWhenNotAvailable(): void
    {
        $symfonyResponse = new SymfonyResponse('test content');
        $kernel = new TestTerminableKernel($symfonyResponse);
        $responseConverter = $this->createResponseConverter();

        $controller = new SymfonyController($kernel, $responseConverter);

        $buffer = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = new Request($buffer);

        $controller($request);

        $controller->terminateIfNeeded();

        $this->assertTrue($kernel->terminateCalled, 'Terminate should be called');
    }
}
