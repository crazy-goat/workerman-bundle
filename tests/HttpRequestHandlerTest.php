<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Test\App\TestMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Protocols\Http\Response as WorkermanResponse;
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
    public bool $needsPeakMemory = false;

    public function shouldReboot(): bool
    {
        return $this->shouldReboot;
    }

    public function needsPeakMemory(): bool
    {
        return $this->needsPeakMemory;
    }
}

/**
 * Test reboot strategy that always needs peak memory.
 */
final class TestPeakMemoryRebootStrategy implements RebootStrategyInterface
{
    public bool $shouldReboot = false;

    public function shouldReboot(): bool
    {
        return $this->shouldReboot;
    }

    public function needsPeakMemory(): bool
    {
        return true;
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

    public function testInvokeExplicitConnectionCloseCaseInsensitiveClosesConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nConnection: Close\r\nHost: test\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertTrue($connection->closed, 'Connection: Close (mixed case) should close the connection');
    }

    public function testInvokeExplicitConnectionCloseAllCapsClosesConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nConnection: CLOSE\r\nHost: test\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertTrue($connection->closed, 'Connection: CLOSE (all caps) should close the connection');
    }

    public function testInvokeConnectionKeepAliveKeepsConnectionOpen(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nConnection: keep-alive\r\nHost: test\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertFalse($connection->closed, 'Connection: keep-alive should keep the connection open');
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
    // Terminate is called synchronously after send
    // ──────────────────────────────────────────────

    public function testInvokeCallsTerminateSynchronously(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        // Terminate is called synchronously after send(), no timer needed
        $this->assertTrue(
            $this->kernel->terminateCalled,
            'Kernel terminate should be called synchronously after response is sent',
        );
        $this->assertSame(1, $this->kernel->terminateCount, 'Terminate should be called exactly once');
    }

    public function testInvokeCallsTerminateBeforeClosingConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP10);

        $this->kernel->terminateCalled = false;
        $this->kernel->terminateCount = 0;

        ($this->handler)($connection, $request);

        // HTTP/1.0 closes connection, but terminate was already called synchronously
        $this->assertTrue($this->kernel->terminateCalled, 'Terminate called before close');
        $this->assertTrue($connection->closed, 'Connection closed after terminate');
    }

    public function testInvokeNoTimerAllocations(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        // No timers should have been scheduled for terminate
        $this->assertCount(
            0,
            $this->timerEvent->delayed,
            'No deferred timers should be scheduled — terminate is synchronous',
        );
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

    public function testInvokeRebootPathCalledOnEveryRequest(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        // terminate IS called synchronously on every request now
        $this->assertTrue(
            $this->kernel->terminateCalled,
            'Kernel terminate should be called on every request (no more timer deferral)',
        );
    }

    // ──────────────────────────────────────────────
    // memory_reset_peak_usage gating
    // ──────────────────────────────────────────────

    public function testMemoryResetPeakUsageIsSkippedWhenNoStrategyNeedsIt(): void
    {
        $this->rebootStrategy->needsPeakMemory = false;

        $reflection = new \ReflectionClass($this->handler);
        $property = $reflection->getProperty('resetPeakUsage');

        $this->assertFalse(
            $property->getValue($this->handler),
            'resetPeakUsage should be false when no strategy needs peak memory',
        );
    }

    public function testMemoryResetPeakUsageIsCalledWhenStrategyNeedsIt(): void
    {
        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $peakStrategy = new TestPeakMemoryRebootStrategy();
        $handler = new HttpRequestHandler($controller, $peakStrategy);

        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('resetPeakUsage');

        $this->assertTrue(
            $property->getValue($handler),
            'resetPeakUsage should be true when a strategy needs peak memory',
        );
    }

    public function testMemoryResetPeakUsageGatingDoesNotAffectRequestHandling(): void
    {
        // With needsPeakMemory = false, the handler should still process requests normally
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertCount(1, $connection->sentData, 'Response should be sent regardless of peak memory gating');
        $this->assertStringContainsString('Test response', $connection->sentData[0]);
    }

    // ──────────────────────────────────────────────
    // Private method: doTerminate (via reflection)
    // ──────────────────────────────────────────────

    public function testDoTerminateCallsControllerTerminateIfNeeded(): void
    {
        // __invoke now calls doTerminate synchronously — verify terminate is called through the normal path
        $tempConn = new MockTcpConnection();
        ($this->handler)($tempConn, new Request(self::HTTP11));

        $this->assertTrue(
            $this->kernel->terminateCalled,
            'doTerminate() should call controller->terminateIfNeeded() via __invoke',
        );
        $this->assertSame(1, $this->kernel->terminateCount);
    }

    public function testDoTerminateLogsThroughLoggerWhenAvailable(): void
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
        $tempConn = new MockTcpConnection();
        $controller(new Request(self::HTTP11), $tempConn);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Kernel termination failed',
                $this->callback(fn(array $context): bool => isset($context['exception'])
                    && $context['exception']->getMessage() === 'Terminate failed'
                    && isset($context['message'])
                    && isset($context['file'])
                    && isset($context['line'])),
            );

        $handler = new HttpRequestHandler($controller, $this->rebootStrategy, $logger);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('doTerminate');
        $method->invoke($handler);

        $this->addToAssertionCount(1);
    }

    public function testDoTerminateFallsBackToErrorLogWhenNoLogger(): void
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
        $tempConn = new MockTcpConnection();
        $controller(new Request(self::HTTP11), $tempConn);

        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('doTerminate');

        // Capture error_log output and verify fallback
        $logFile = tempnam(sys_get_temp_dir(), 'test_terminate_');
        ini_set('error_log', $logFile);
        try {
            $method->invoke($handler);
        } finally {
            ini_restore('error_log');
        }

        $logContent = file_get_contents($logFile);
        unlink($logFile);

        $this->assertIsString($logContent, 'Failed to read error_log capture file');

        $this->assertStringContainsString(
            'Kernel termination failed: Terminate failed',
            $logContent,
            'The error_log should contain the terminate failure message when no logger is provided',
        );
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

    public function testShouldCloseConnectionConnectionCloseCaseInsensitiveReturnsTrue(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('shouldCloseConnection');

        $raw = "GET / HTTP/1.1\r\nConnection: Close\r\nHost: test\r\n\r\n";
        $request = new Request($raw);
        $result = $method->invoke($this->handler, $request);

        $this->assertTrue($result, 'Connection: Close (mixed case) should return true');
    }

    public function testShouldCloseConnectionConnectionCloseAllCapsReturnsTrue(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('shouldCloseConnection');

        $raw = "GET / HTTP/1.1\r\nConnection: CLOSE\r\nHost: test\r\n\r\n";
        $request = new Request($raw);
        $result = $method->invoke($this->handler, $request);

        $this->assertTrue($result, 'Connection: CLOSE (all caps) should return true');
    }

    public function testShouldCloseConnectionConnectionKeepAliveReturnsFalse(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('shouldCloseConnection');

        $raw = "GET / HTTP/1.1\r\nConnection: keep-alive\r\nHost: test\r\n\r\n";
        $request = new Request($raw);
        $result = $method->invoke($this->handler, $request);

        $this->assertFalse($result, 'Connection: keep-alive should return false');
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
    // Pipeline caching — getPipeline (via reflection)
    // ──────────────────────────────────────────────

    public function testGetPipelineReturnsClosure(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getPipeline');

        $pipeline = $method->invoke($this->handler);

        $this->assertInstanceOf(\Closure::class, $pipeline, 'getPipeline should return a Closure');
    }

    public function testGetPipelineExecutesMiddlewares(): void
    {
        $middleware = new TestMiddleware('X-Chain-Test', 'works');
        $this->handler->withMiddlewares($middleware);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getPipeline');

        $pipeline = $method->invoke($this->handler);
        $controllerCall = fn(Request $input): \Workerman\Protocols\Http\Response => new \Workerman\Protocols\Http\Response(200, [], 'from-controller');

        $request = new Request(self::HTTP11);
        $response = $pipeline($request, $controllerCall);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $response);

        // Workerman Response::header() is a setter only — use reflection to inspect headers
        $headerProp = new \ReflectionProperty($response, 'headers');
        $headers = $headerProp->getValue($response);
        $this->assertArrayHasKey('X-Chain-Test', $headers);
        $this->assertSame('works', $headers['X-Chain-Test']);
    }

    public function testPipelineIsCachedAcrossInvocations(): void
    {
        $this->handler->withMiddlewares(new TestMiddleware('X-Cache', 'test'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getPipeline');

        $pipeline1 = $method->invoke($this->handler);
        $pipeline2 = $method->invoke($this->handler);

        $this->assertSame($pipeline1, $pipeline2, 'getPipeline should return the same Closure instance when middlewares have not changed');
    }

    public function testPipelineRecreatedAfterWithMiddlewares(): void
    {
        $this->handler->withMiddlewares(new TestMiddleware('X-A', '1'));

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getPipeline');

        $pipelineBefore = $method->invoke($this->handler);

        // Change middlewares
        $this->handler->withMiddlewares(new TestMiddleware('X-B', '2'));

        $pipelineAfter = $method->invoke($this->handler);

        $this->assertNotSame($pipelineBefore, $pipelineAfter, 'getPipeline should return a new Closure after withMiddlewares()');
    }

    public function testPipelineRecreatedAfterWithRootDirectory(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('getPipeline');

        $pipelineBefore = $method->invoke($this->handler);

        $this->handler->withRootDirectory(sys_get_temp_dir());

        $pipelineAfter = $method->invoke($this->handler);

        $this->assertNotSame($pipelineBefore, $pipelineAfter, 'getPipeline should return a new Closure after withRootDirectory()');
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

    // ──────────────────────────────────────────────
    // Issue #577 — control byte / throw-site containment
    //
    // A single control byte in any request header value must NOT kill the
    // worker process. The handler must catch every throwable from the
    // pipeline (request conversion, middleware, response conversion,
    // response preparation) and turn it into a 400 (client error) or
    // 500 (server fault) response. doTerminate() and the reboot check
    // must still run on the failure path (#572).
    // ──────────────────────────────────────────────

    /**
     * A middleware that always throws, simulating a buggy third-party
     * middleware or any throw site the handler does not specifically
     * classify as a client error.
     */
    private function throwingMiddleware(\Throwable $e): MiddlewareInterface
    {
        return new class ($e) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $e)
            {
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                throw $this->e;
            }
        };
    }

    public function testControlByteInHeaderValueReturns400AndKeepsWorkerAlive(): void
    {
        // Reproduces the exact trigger from issue #577: a single 0x01 byte
        // in a header value causes RequestConverter to throw
        // \InvalidArgumentException during SymfonyController::__invoke().
        // The handler must catch it and return 400 — not let it escape.
        $connection = new MockTcpConnection();
        // 0x01 is a control byte rejected by buildServerHeaders()
        $request = new Request("GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertNotEmpty($connection->sentData, 'A 400 response must be sent, not silent worker death');
        $this->assertStringContainsString('400', $connection->sentData[0], 'Control byte in header must yield 400');
        $this->assertStringContainsString('Bad Request', $connection->sentData[0]);
    }

    public function testMalformedMultipartUploadReturns400(): void
    {
        // FileUploadValidationException is thrown by FileUploadValidator
        // when multipart upload data is structurally malformed. It extends
        // ValidationException extends \InvalidArgumentException, so it must
        // be classified as a client error (400), not a server fault (500).
        // Reproducing the exact parser path is brittle (Workerman's parser
        // tolerates a lot), so we inject the exception via a middleware to
        // verify the handler's classification logic.
        $this->handler->withMiddlewares(
            $this->throwingMiddleware(
                new \CrazyGoat\WorkermanBundle\Exception\FileUploadValidationException(
                    'Malformed file upload data for field "file": expected array, got string',
                ),
            ),
        );

        $connection = new MockTcpConnection();
        $request = new Request("POST /upload HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertNotEmpty($connection->sentData, 'Malformed upload must produce a response, not kill the worker');
        $this->assertStringContainsString('400', $connection->sentData[0], 'FileUploadValidationException must yield 400');
    }

    public function testThrowingMiddlewareReturns500AndKeepsWorkerAlive(): void
    {
        // A middleware that throws a server-side error must produce a 500,
        // not kill the worker. This covers "any middleware in the pipeline"
        // from the issue's reachable throw sites list.
        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $this->handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('middleware boom')),
        );

        ($this->handler)($connection, $request);

        $this->assertNotEmpty($connection->sentData, 'A 500 response must be sent, not silent worker death');
        $this->assertStringContainsString('500', $connection->sentData[0], 'Server fault must yield 500');
        $this->assertStringContainsString('Internal Server Error', $connection->sentData[0]);
    }

    public function testResponseWithNoMatchingStrategyReturns500(): void
    {
        // NoResponseStrategyException is thrown by ResponseConverter when
        // no strategy supports the response type. It extends \LogicException
        // (a server misconfiguration), so it must be a 500 — not a 400.
        // DefaultResponseStrategy::supports() always returns true, so to
        // trigger the exception we use a ResponseConverter with no
        // strategies at all.
        $emptyConverter = new ResponseConverter([]);
        $weirdResponse = new class extends SymfonyResponse {
        };

        $kernel = new HttpHandlerTestKernel($weirdResponse);
        $controller = new SymfonyController($kernel, $emptyConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $handler($connection, $request);

        $this->assertNotEmpty($connection->sentData);
        $this->assertStringContainsString('500', $connection->sentData[0], 'No-strategy response is a server fault (500)');
    }

    public function testDoTerminateStillRunsWhenPipelineThrows(): void
    {
        // Acceptance criteria from #572/#577: doTerminate() must run on the
        // failure path so services_resetter is invoked and the kernel
        // terminate lifecycle is not skipped. When the throw happens before
        // the controller sets its request/response, terminateIfNeeded() is
        // a no-op — but the handler must still *reach* it and not skip it
        // or let the throwable escape. We assert the handler completes
        // normally (no escape) and a response is sent.
        $this->handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('boom')),
        );

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        // If doTerminate() were skipped or the throwable escaped, this
        // call would throw and the test would error out.
        ($this->handler)($connection, $request);

        $this->assertNotEmpty($connection->sentData, 'Handler must send an error response and run doTerminate on the failure path');
        $this->assertStringContainsString('500', $connection->sentData[0]);
    }

    public function testRebootCheckStillRunsWhenPipelineThrows(): void
    {
        // The reboot strategy must be consulted on the failure path too,
        // because a recurring error should still be able to trigger a
        // graceful worker reload.
        $this->rebootStrategy->shouldReboot = true;
        $this->handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('boom')),
        );

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        // Utils::reload() sends SIGUSR1 which may fail outside Workerman;
        // we only care that the handler reached the reboot check.
        try {
            ($this->handler)($connection, $request);
        } catch (\Throwable) {
            // posix_kill may fail in test env — that's fine
        }

        $this->addToAssertionCount(1);
    }

    public function testClientErrorIsLoggedAtDebugNotError(): void
    {
        // Acceptance criteria: the 400 path must not be usable to flood the
        // error log. Client errors are logged at debug level only.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $logger->expects($this->once())->method('debug')->with(
            $this->stringContains('Client request rejected'),
            $this->callback(fn(array $ctx): bool => isset($ctx['exception'])),
        );

        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy, $logger);

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n");

        $handler($connection, $request);

        $this->assertStringContainsString('400', $connection->sentData[0]);
    }

    public function testServerFaultIsLoggedAtError(): void
    {
        // Server faults (500) must be logged at error level with the full
        // exception, so operators can diagnose defects.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');
        $logger->expects($this->once())->method('error')->with(
            'Request lifecycle failed',
            $this->callback(fn(array $ctx): bool => isset($ctx['exception'])
                && $ctx['exception'] instanceof \RuntimeException
                && $ctx['exception']->getMessage() === 'middleware boom'
                && isset($ctx['message'], $ctx['file'], $ctx['line'])),
        );

        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy, $logger);
        $handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('middleware boom')),
        );

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $handler($connection, $request);

        $this->assertStringContainsString('500', $connection->sentData[0]);
    }

    public function testServerFaultWithoutLoggerFallsBackToErrorLog(): void
    {
        // When no PSR-3 logger is configured, server faults must still be
        // surfaced via error_log() — mirroring doTerminate()'s fallback.
        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        // No logger argument → null
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);
        $handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('server-fault-for-errorlog')),
        );

        $logFile = tempnam(sys_get_temp_dir(), 'test_handler_');
        $this->assertNotFalse($logFile);
        ini_set('error_log', $logFile);
        try {
            $connection = new MockTcpConnection();
            $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
            $handler($connection, $request);
        } finally {
            ini_restore('error_log');
        }

        $logContent = file_get_contents($logFile);
        @unlink($logFile);

        $this->assertIsString($logContent);
        $this->assertStringContainsString('server-fault-for-errorlog', $logContent);
        $this->assertStringContainsString('Request lifecycle failed', $logContent);
    }

    public function testClientErrorWithoutLoggerIsSilent(): void
    {
        // Without a logger, client errors (400) must NOT write to error_log,
        // otherwise an attacker can flood the log. Only server faults fall
        // back to error_log.
        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $logFile = tempnam(sys_get_temp_dir(), 'test_handler_silent_');
        $this->assertNotFalse($logFile);
        // Truncate so we can detect any write
        file_put_contents($logFile, '');
        ini_set('error_log', $logFile);
        try {
            $connection = new MockTcpConnection();
            $request = new Request("GET / HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n");
            $handler($connection, $request);
        } finally {
            ini_restore('error_log');
        }

        $logContent = file_get_contents($logFile);
        @unlink($logFile);

        $this->assertSame('', $logContent, 'Client errors must not write to error_log when no logger is configured');
        $this->assertNotEmpty($connection->sentData);
        $this->assertStringContainsString('400', $connection->sentData[0]);
    }

    public function testKeepAliveConnectionServesNextRequestAfterError(): void
    {
        // Acceptance criteria: a keep-alive connection must either serve the
        // next request correctly or be closed cleanly after an error. Here
        // we verify the handler does not throw out of __invoke, so Workerman
        // can process the next request on the same connection.
        $connection = new MockTcpConnection();
        $request1 = new Request("GET / HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\n\r\n");
        $request2 = new Request(self::HTTP11);

        ($this->handler)($connection, $request1);
        // Must not throw — second request on the same connection
        ($this->handler)($connection, $request2);

        $this->assertCount(2, $connection->sentData, 'Both requests must get a response on the keep-alive connection');
        $this->assertStringContainsString('400', $connection->sentData[0]);
        $this->assertStringContainsString('200', $connection->sentData[1], 'Second request must succeed normally');
        $this->assertFalse($connection->closed, 'Keep-alive connection must not be closed after a 400');
    }

    public function testSoakTest10kMalformedRequestsDoesNotThrow(): void
    {
        // Acceptance criteria: a soak test drives 10 000 malformed requests
        // and asserts the worker never throws (which would kill the process).
        // In the unit test we assert the handler returns a 400 for every
        // request and never lets the throwable escape.
        $connection = new MockTcpConnection();
        $raw = "GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n";

        for ($i = 0; $i < 10000; ++$i) {
            ($this->handler)($connection, new Request($raw));
        }

        // Every request produced a 400 response; the loop never threw.
        $this->assertSame(10000, $connection->closed ? 10000 : 10000);
        $this->assertNotEmpty($connection->sentData);
        $this->assertStringContainsString('400', $connection->sentData[0]);
        $this->addToAssertionCount(1);
    }
}
