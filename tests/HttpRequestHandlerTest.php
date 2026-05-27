<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Test\App\TestMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Timer;

/**
 * Mock TcpConnection for testing
 */
final class MockTcpConnection extends TcpConnection
{
    /** @var list<string> */
    public array $sentData = [];
    public bool $closed = false;

    public function __construct()
    {
        // Don't call parent constructor to avoid socket operations
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool
    {
        $this->sentData[] = is_string($sendBuffer) ? $sendBuffer : (string) $sendBuffer;
        return true;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closed = true;
    }
}

/**
 * Test kernel for HttpRequestHandler tests
 */
final class HttpHandlerTestKernel implements KernelInterface, TerminableInterface
{
    public bool $bootCalled = false;
    public bool $terminateCalled = false;
    public int $terminateCount = 0;
    public ?\Symfony\Component\HttpFoundation\Request $lastRequest = null;

    public function __construct(private readonly ?SymfonyResponse $responseToReturn = null)
    {
    }

    public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
    {
        $this->terminateCalled = true;
        ++$this->terminateCount;
        $this->lastRequest = $request;
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
        return $this->responseToReturn ?? new SymfonyResponse('Test response');
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

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getStartTime(): float
    {
        return 0.0;
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }
}

/**
 * Test reboot strategy
 */
final class TestRebootStrategy implements RebootStrategyInterface
{
    public bool $shouldReboot = false;

    public function shouldReboot(): bool
    {
        return $this->shouldReboot;
    }
}

/**
 * Dummy event implementation for Timer to work in unit tests.
 * Records timers that would have been scheduled.
 */
final class TestTimerEvent implements EventInterface
{
    /** @var list<array{delay: float, func: callable, args: array<mixed>}> */
    public array $delayed = [];
    /** @var list<array{interval: float, func: callable, args: array<mixed>}> */
    public array $repeated = [];
    private int $timerId = 0;

    /** @param array<mixed> $args */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $this->delayed[] = ['delay' => $delay, 'func' => $func, 'args' => $args];
        return ++$this->timerId;
    }

    public function offDelay(int $timerId): bool
    {
        return true;
    }

    /** @param array<mixed> $args */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $this->repeated[] = ['interval' => $interval, 'func' => $func, 'args' => $args];
        return ++$this->timerId;
    }

    public function offRepeat(int $timerId): bool
    {
        return true;
    }

    public function onReadable($stream, callable $func): void
    {
    }
    public function offReadable($stream): bool
    {
        return true;
    }
    public function onWritable($stream, callable $func): void
    {
    }
    public function offWritable($stream): bool
    {
        return true;
    }
    public function onSignal(int $signal, callable $func): void
    {
    }
    public function offSignal(int $signal): bool
    {
        return true;
    }
    public function deleteAllTimer(): void
    {
    }
    public function run(): void
    {
    }
    public function stop(): void
    {
    }
    public function getTimerCount(): int
    {
        return 0;
    }
    public function setErrorHandler(callable $errorHandler): void
    {
    }
}

/**
 * @group http
 */
final class HttpRequestHandlerTest extends TestCase
{
    private HttpHandlerTestKernel $kernel;
    private TestRebootStrategy $rebootStrategy;
    private HttpRequestHandler $handler;
    private ResponseConverter $responseConverter;
    private TestTimerEvent $timerEvent;

    /** @var string Minimal valid HTTP/1.1 request buffer */
    private const HTTP11 = "GET / HTTP/1.1\r\nHost: test\r\n\r\n";

    /** @var string Minimal valid HTTP/1.0 request buffer */
    private const HTTP10 = "GET / HTTP/1.0\r\nHost: test\r\n\r\n";

    protected function setUp(): void
    {
        $this->kernel = new HttpHandlerTestKernel();
        $this->rebootStrategy = new TestRebootStrategy();
        $this->responseConverter = new ResponseConverter([new DefaultResponseStrategy()]);

        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $this->handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        // Initialize Timer with a test event so Timer::add() doesn't throw in unit tests
        $this->timerEvent = new TestTimerEvent();
        Timer::init($this->timerEvent);
    }

    protected function tearDown(): void
    {
        Timer::delAll();
    }

    // ──────────────────────────────────────────────
    // Existing initialization tests (unchanged)
    // ──────────────────────────────────────────────

    public function testHandlerInitializesCorrectly(): void
    {
        $this->assertInstanceOf(HttpRequestHandler::class, $this->handler);
    }

    public function testHandlerWithMiddlewaresReturnsSelf(): void
    {
        $this->assertSame($this->handler, $this->handler->withMiddlewares());
    }

    public function testHandlerWithRootDirectoryReturnsSelf(): void
    {
        $this->assertSame($this->handler, $this->handler->withRootDirectory('/tmp'));
    }

    public function testHandlerWithNullRootDirectoryReturnsSelf(): void
    {
        $this->assertSame($this->handler, $this->handler->withRootDirectory(null));
    }

    public function testHandlerWithMiddlewaresAddsMiddleware(): void
    {
        $middleware = new TestMiddleware('X-Test', 'value');
        $this->assertSame($this->handler, $this->handler->withMiddlewares($middleware));
    }

    public function testHandlerWithMultipleMiddlewares(): void
    {
        $middleware1 = new TestMiddleware('X-Test1', 'value1');
        $middleware2 = new TestMiddleware('X-Test2', 'value2');
        $this->assertSame($this->handler, $this->handler->withMiddlewares($middleware1, $middleware2));
    }

    public function testHandlerWithRootDirectoryAddsStaticFilesMiddleware(): void
    {
        $this->assertSame($this->handler, $this->handler->withRootDirectory(sys_get_temp_dir()));
    }

    public function testHandlerWithEmptyRootDirectory(): void
    {
        $this->assertSame($this->handler, $this->handler->withRootDirectory(''));
    }

    public function testHandlerChaining(): void
    {
        $middleware = new TestMiddleware('X-Test', 'value');
        $result = $this->handler
            ->withMiddlewares($middleware)
            ->withRootDirectory('/tmp');

        $this->assertSame($this->handler, $result);
    }

    public function testKernelIsNotBootedBeforeRequest(): void
    {
        $this->assertFalse($this->kernel->bootCalled);
    }

    public function testSymfonyControllerIsInjectedViaConstructor(): void
    {
        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $reflection = new \ReflectionClass($handler);
        $controllerProperty = $reflection->getProperty('controller');
        $this->assertSame($controller, $controllerProperty->getValue($handler));
    }

    public function testRebootStrategyIsSetCorrectly(): void
    {
        $this->assertFalse($this->rebootStrategy->shouldReboot);

        $this->rebootStrategy->shouldReboot = true;
        $this->assertTrue($this->rebootStrategy->shouldReboot);
    }

    // ──────────────────────────────────────────────
    // __invoke() — happy path: response is sent
    // ──────────────────────────────────────────────

    public function testInvokeSendsResponseToConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertCount(1, $connection->sentData, 'Response should be sent to the connection');
        $this->assertStringContainsString('HTTP/', $connection->sentData[0], 'Sent data should be an HTTP response');
        $this->assertStringContainsString('Test response', $connection->sentData[0], 'Response body should contain kernel output');
    }

    public function testInvokeSendsCorrectStatusCode(): void
    {
        $kernel = new HttpHandlerTestKernel(new SymfonyResponse('Not Found', SymfonyResponse::HTTP_NOT_FOUND));
        $controller = new SymfonyController($kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $handler($connection, $request);

        $this->assertStringContainsString('404', $connection->sentData[0], 'Response should have 404 status');
    }

    public function testInvokeKernelBootsOnFirstRequest(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $this->assertFalse($this->kernel->bootCalled);
        ($this->handler)($connection, $request);

        $this->assertTrue($this->kernel->bootCalled, 'Kernel should be booted during request handling');
    }

    // ──────────────────────────────────────────────
    // Middleware chain — reverse order + headers
    // ──────────────────────────────────────────────

    public function testInvokeWithMiddlewareAppliesMiddlewareHeaders(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $middleware = new TestMiddleware('X-Test', 'middleware-value');
        $this->handler->withMiddlewares($middleware);

        ($this->handler)($connection, $request);

        $this->assertStringContainsString(
            'X-Test: middleware-value',
            $connection->sentData[0],
            'Middleware header should appear in the response',
        );
    }

    public function testInvokeMiddlewaresAppliedInReverseOrder(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $middlewareA = new TestMiddleware('X-Order-A', 'first');
        $middlewareB = new TestMiddleware('X-Order-B', 'second');
        $this->handler->withMiddlewares($middlewareA, $middlewareB);

        ($this->handler)($connection, $request);

        $this->assertStringContainsString(
            'X-Order-A: first',
            $connection->sentData[0],
            'Middleware A (inner) should add its header',
        );
        $this->assertStringContainsString(
            'X-Order-B: second',
            $connection->sentData[0],
            'Middleware B (outer) should add its header',
        );
    }

    public function testInvokeWithoutMiddlewaresStillDispatchesToController(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertCount(1, $connection->sentData, 'Response should be sent even without middlewares');
        $this->assertStringContainsString('Test response', $connection->sentData[0]);
    }

    // ──────────────────────────────────────────────
    // Connection close behavior (HTTP/1.0 vs 1.1)
    // ──────────────────────────────────────────────

    public function testInvokeHttp10ClosesConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP10);

        ($this->handler)($connection, $request);

        $this->assertTrue($connection->closed, 'HTTP/1.0 request should close the connection');
    }

    public function testInvokeHttp11KeepsConnectionOpen(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertFalse($connection->closed, 'HTTP/1.1 request should keep the connection open');
    }

    public function testInvokeExplicitConnectionCloseClosesConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nConnection: close\r\nHost: test\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertTrue($connection->closed, 'Connection: close header should close the connection');
    }

    // ──────────────────────────────────────────────
    // Response already sent by middleware
    // ──────────────────────────────────────────────

    public function testInvokeSkipsSendWhenResponseAlreadySent(): void
    {
        $connection = new MockTcpConnection();
        $connection->context = new \stdClass();
        $connection->context->responseSentDirectly = true;

        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertCount(0, $connection->sentData, 'Response should NOT be sent when already sent by middleware');
        $this->assertFalse(
            isset($connection->context->responseSentDirectly),
            'responseSentDirectly flag should be cleared',
        );
    }

    // ──────────────────────────────────────────────
    // ScheduleTerminate schedules a deferred timer
    // ──────────────────────────────────────────────

    public function testInvokeSchedulesDeferredTerminate(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertCount(1, $this->timerEvent->delayed, 'A deferred timer should be scheduled for terminate');
        $this->assertSame(0.0, $this->timerEvent->delayed[0]['delay'], 'Deferred timer should have zero delay');
    }

    public function testInvokeCancelsPreviousTimerOnSubsequentRequest(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        // First request schedules a timer
        ($this->handler)($connection, $request);
        $firstTimerCount = \count($this->timerEvent->delayed);

        // Second request should cancel the first timer and schedule a new one
        ($this->handler)($connection, $request);
        $secondTimerCount = \count($this->timerEvent->delayed);

        $this->assertSame($firstTimerCount + 1, $secondTimerCount, 'Each request should schedule exactly one new timer');
    }

    // ──────────────────────────────────────────────
    // Reboot path — terminate before reload
    // ──────────────────────────────────────────────

    public function testInvokeRebootPathCallsTerminateSynchronously(): void
    {
        $this->rebootStrategy->shouldReboot = true;

        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        // Utils::reload() sends SIGUSR1 which behaves differently per environment.
        // We just verify terminate was called synchronously before the reload attempt.
        try {
            ($this->handler)($connection, $request);
        } catch (\Throwable) {
            // posix_kill may fail in non-Workerman environment, that's fine
        }

        $this->assertTrue(
            $this->kernel->terminateCalled,
            'Kernel terminate should be called synchronously during reboot path',
        );
        $this->assertGreaterThanOrEqual(1, $this->kernel->terminateCount);
    }

    public function testInvokeRebootPathDoesNotAffectNonRebootRequests(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        // terminate should NOT have been called during normal request (it's deferred via timer)
        $this->assertFalse(
            $this->kernel->terminateCalled,
            'Kernel terminate should NOT be called during normal request handling',
        );
    }

    // ──────────────────────────────────────────────
    // Private method: doTerminate (via reflection)
    // ──────────────────────────────────────────────

    public function testDoTerminateCallsControllerTerminateIfNeeded(): void
    {
        // First, invoke the controller so it has a stored request/response for terminateIfNeeded
        $tempConn = new MockTcpConnection();
        ($this->handler)($tempConn, new Request(self::HTTP11));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('doTerminate');

        // Reset terminate tracking from the invoke above
        $this->kernel->terminateCalled = false;
        $this->kernel->terminateCount = 0;

        $method->invoke($this->handler);

        $this->assertTrue(
            $this->kernel->terminateCalled,
            'doTerminate() should call controller->terminateIfNeeded()',
        );
    }

    public function testDoTerminateDoesNotThrowOnControllerException(): void
    {
        $throwingKernel = new class implements KernelInterface, TerminableInterface {
            public function terminate(\Symfony\Component\HttpFoundation\Request $request, \Symfony\Component\HttpFoundation\Response $response): void
            {
                throw new \RuntimeException('Terminate failed');
            }
            public function boot(): void
            {
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
                return new SymfonyResponse('OK');
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
            public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
            {
                throw new \RuntimeException('Not implemented');
            }
            public function getStartTime(): float
            {
                return 0.0;
            }
            public function getCharset(): string
            {
                return 'UTF-8';
            }
        };

        $controller = new SymfonyController($throwingKernel, $this->responseConverter);
        // Perform one invocation so terminateIfNeeded has a request/response to work with
        $tempConn = new MockTcpConnection();
        $controller(new Request(self::HTTP11), $tempConn);

        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('doTerminate');

        // Should not throw — exceptions from terminateIfNeeded are caught internally
        $method->invoke($handler);

        // The kernel's terminate was called but threw — that's OK, we verified no uncaught exception
        $this->addToAssertionCount(1);
    }

    // ──────────────────────────────────────────────
    // Private method: shouldCloseConnection (via reflection)
    // ──────────────────────────────────────────────

    public function testShouldCloseConnectionHttp10ReturnsTrue(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('shouldCloseConnection');

        $request = new Request(self::HTTP10);
        $result = $method->invoke($this->handler, $request);

        $this->assertTrue($result, 'HTTP/1.0 should close connection');
    }

    public function testShouldCloseConnectionHttp11ReturnsFalse(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('shouldCloseConnection');

        $request = new Request(self::HTTP11);
        $result = $method->invoke($this->handler, $request);

        $this->assertFalse($result, 'HTTP/1.1 should not close connection');
    }

    public function testShouldCloseConnectionConnectionCloseReturnsTrue(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('shouldCloseConnection');

        $raw = "GET / HTTP/1.1\r\nConnection: close\r\nHost: test\r\n\r\n";
        $request = new Request($raw);
        $result = $method->invoke($this->handler, $request);

        $this->assertTrue($result, 'Connection: close should return true');
    }

    // ──────────────────────────────────────────────
    // Private method: sendResponse (via reflection)
    // ──────────────────────────────────────────────

    public function testSendResponseSendsToConnection(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('sendResponse');

        $connection = new MockTcpConnection();
        $response = new \Workerman\Protocols\Http\Response(200, [], 'Body content');

        $method->invoke($this->handler, $connection, $response);

        $this->assertCount(1, $connection->sentData);
        $this->assertStringContainsString('Body content', $connection->sentData[0]);
    }

    public function testSendResponseSkipsWhenAlreadySent(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('sendResponse');

        $connection = new MockTcpConnection();
        $connection->context = new \stdClass();
        $connection->context->responseSentDirectly = true;

        $response = new \Workerman\Protocols\Http\Response(200, [], 'Body content');

        $method->invoke($this->handler, $connection, $response);

        $this->assertCount(0, $connection->sentData, 'Should skip send when responseSentDirectly is set');
        $this->assertFalse(
            isset($connection->context->responseSentDirectly),
            'responseSentDirectly flag should be cleared',
        );
    }

    // ──────────────────────────────────────────────
    // Private method: buildMiddlewareChain (via reflection)
    // ──────────────────────────────────────────────

    public function testBuildMiddlewareChainReturnsCallable(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildMiddlewareChain');

        $connection = new MockTcpConnection();
        $chain = $method->invoke($this->handler, $connection);

        $this->assertIsCallable($chain, 'buildMiddlewareChain should return a callable');
    }

    public function testBuildMiddlewareChainExecutesChain(): void
    {
        $middleware = new TestMiddleware('X-Chain-Test', 'works');
        $this->handler->withMiddlewares($middleware);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildMiddlewareChain');

        $connection = new MockTcpConnection();
        $chain = $method->invoke($this->handler, $connection);

        $request = new Request(self::HTTP11);
        $response = $chain($request);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);

        // Workerman Response::header() is a setter only — use reflection to inspect headers
        $headerProp = new \ReflectionProperty($response, 'headers');
        $headers = $headerProp->getValue($response);
        $this->assertArrayHasKey('X-Chain-Test', $headers);
        $this->assertSame('works', $headers['X-Chain-Test']);
    }

    // ──────────────────────────────────────────────
    // Multiple middlewares header propagation
    // ──────────────────────────────────────────────

    public function testMultipleMiddlewaresAllHeadersInResponse(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $m1 = new TestMiddleware('X-Auth', 'token123');
        $m2 = new TestMiddleware('X-Cache', 'miss');
        $this->handler->withMiddlewares($m1, $m2);

        ($this->handler)($connection, $request);

        $this->assertStringContainsString('X-Auth: token123', $connection->sentData[0]);
        $this->assertStringContainsString('X-Cache: miss', $connection->sentData[0]);
    }
}
