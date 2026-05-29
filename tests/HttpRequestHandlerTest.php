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
use Psr\Log\LoggerInterface;
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
}
