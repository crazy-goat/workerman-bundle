<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Test\App\TestMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use Workerman\Timer;

class MockTcpConnection extends TcpConnection
{
    /** @var list<string> */
    public array $sentData = [];
    public bool $closed = false;

    public function __construct()
    {
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

final class TestRebootStrategy implements RebootStrategyInterface
{
    public bool $shouldReboot = false;

    public function shouldReboot(): bool
    {
        return $this->shouldReboot;
    }
}

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

#[Group('http')]
final class HttpRequestHandlerTest extends TestCase
{
    private HttpHandlerTestKernel $kernel;
    private TestRebootStrategy $rebootStrategy;
    private HttpRequestHandler $handler;
    private ResponseConverter $responseConverter;
    private TestTimerEvent $timerEvent;

    /** @var string */
    private const HTTP11 = "GET / HTTP/1.1\r\nHost: test\r\n\r\n";

    /** @var string */
    private const HTTP10 = "GET / HTTP/1.0\r\nHost: test\r\n\r\n";

    protected function setUp(): void
    {
        $this->kernel = new HttpHandlerTestKernel();
        $this->rebootStrategy = new TestRebootStrategy();
        $this->responseConverter = new ResponseConverter([new DefaultResponseStrategy()]);

        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $this->handler = new HttpRequestHandler($controller, $this->rebootStrategy, new NullLogger());

        $this->timerEvent = new TestTimerEvent();
        Timer::init($this->timerEvent);
    }

    protected function tearDown(): void
    {
        Timer::delAll();
    }

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

    public function testInvokeWithMultipleMiddlewaresAllHeadersInResponse(): void
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
            'Middleware A should add its header',
        );
        $this->assertStringContainsString(
            'X-Order-B: second',
            $connection->sentData[0],
            'Middleware B should add its header',
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

    public function testInvokeHttp10ClosesConnection(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP10);

        ($this->handler)($connection, $request);

        $this->assertTrue($connection->closed, 'HTTP/1.0 request should close the connection');
        $this->assertStringStartsWith("HTTP/1.0 200 OK\r\n", $connection->sentData[0]);
        $this->assertStringContainsString("Connection: close\r\n", $connection->sentData[0]);
    }

    public function testLargeResponseIsSentInOneTransportWrite(): void
    {
        $body = str_repeat('a', 1024 * 1024);
        $kernel = new HttpHandlerTestKernel(new SymfonyResponse($body));
        $controller = new SymfonyController($kernel, new ResponseConverter([new DefaultResponseStrategy()]));
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy, new NullLogger());
        $connection = new MockTcpConnection();

        $handler($connection, new Request(self::HTTP11));

        $this->assertCount(1, $connection->sentData);
        $this->assertStringEndsWith("\r\n{$body}", $connection->sentData[0]);
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

    public function testInvokeCallsTerminateSynchronously(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

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

        $this->assertTrue($this->kernel->terminateCalled, 'Terminate called before close');
        $this->assertTrue($connection->closed, 'Connection closed after terminate');
    }

    public function testInvokeNoTimerAllocations(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertCount(
            0,
            $this->timerEvent->delayed,
            'No deferred timers should be scheduled — terminate is synchronous',
        );
    }

    public function testInvokeRebootPathCalledOnEveryRequest(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        ($this->handler)($connection, $request);

        $this->assertTrue(
            $this->kernel->terminateCalled,
            'Kernel terminate should be called on every request (no more timer deferral)',
        );
    }

    public function testInvokeRebootPathSendsExactlyOneSignal(): void
    {
        $this->rebootStrategy->shouldReboot = true;

        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $signals = $this->countReloadSignals(function () use ($connection, $request): void {
            ($this->handler)($connection, $request);
        });

        $this->assertSame(
            1,
            $signals,
            'A shouldReboot() verdict must reach Utils::reload() exactly once per request',
        );
        $this->assertTrue($this->kernel->terminateCalled, 'Terminate still runs on the reboot path');
    }

    public function testInvokeWithoutRebootVerdictSendsNoSignal(): void
    {
        $this->rebootStrategy->shouldReboot = false;

        $connection = new MockTcpConnection();
        $request = new Request(self::HTTP11);

        $signals = $this->countReloadSignals(function () use ($connection, $request): void {
            ($this->handler)($connection, $request);
        });

        $this->assertSame(0, $signals, 'A false shouldReboot() verdict must not send SIGUSR1');
    }

    public function testInvokeErrorPathStillSendsTheReloadSignal(): void
    {
        $this->rebootStrategy->shouldReboot = true;
        $this->handler->withMiddlewares($this->throwingMiddleware(new \RuntimeException('middleware boom')));

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $signals = $this->countReloadSignals(function () use ($connection, $request): void {
            ($this->handler)($connection, $request);
        });

        $this->assertNotEmpty($connection->sentData, 'The error path must still send a response');
        $this->assertStringContainsString('500', $connection->sentData[0]);
        $this->assertSame(
            1,
            $signals,
            'The reboot check must run after a handled throwable, so the signal is not lost',
        );
    }

    /**
     * @param callable(): void $callback
     */
    private function countReloadSignals(callable $callback): int
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix') || !\defined('SIGUSR1')) {
            self::markTestSkipped('pcntl, posix and the SIGUSR1 constant are required.');
        }

        $count = 0;
        $previousHandler = \pcntl_signal_get_handler(\SIGUSR1);
        \pcntl_signal(\SIGUSR1, static function () use (&$count): void {
            ++$count;
        });

        try {
            $callback();
            \pcntl_signal_dispatch();
        } finally {
            \pcntl_signal(\SIGUSR1, $previousHandler);
        }

        return $count;
    }

    public function testDoTerminateCallsControllerTerminateIfNeeded(): void
    {
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

    public function testSendResponseSendsToConnection(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('sendResponse');

        $connection = new MockTcpConnection();
        $response = new \Workerman\Protocols\Http\Response(200, [], 'Body content');
        $request = new Request(self::HTTP11);

        $method->invoke($this->handler, $connection, $response, $request);

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
        $request = new Request(self::HTTP11);

        $method->invoke($this->handler, $connection, $response, $request);

        $this->assertCount(0, $connection->sentData, 'Should skip send when responseSentDirectly is set');
        $this->assertFalse(
            isset($connection->context->responseSentDirectly),
            'responseSentDirectly flag should be cleared',
        );
    }

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

    public function testStaticFilesStillServedWhenMiddlewaresAreSetAfterRootDirectory(): void
    {
        $root = sys_get_temp_dir() . '/workerman-static-order-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root . '/asset.txt', 'static content');

        try {
            $this->handler->withRootDirectory($root);
            $this->handler->withMiddlewares(new TestMiddleware('X-Test', 'value'));

            $connection = new MockTcpConnection();
            ($this->handler)($connection, new Request("GET /asset.txt HTTP/1.1\r\nHost: test\r\n\r\n"));

            $this->assertStringContainsString('static content', $connection->sentData[0]);
            $this->assertStringContainsString('X-Test: value', $connection->sentData[0]);
        } finally {
            @unlink($root . '/asset.txt');
            @rmdir($root);
        }
    }

    public function testDeprecatedStaticFilesConfigAllowlistStillFiltersExtensions(): void
    {
        $root = sys_get_temp_dir() . '/workerman-static-files-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        file_put_contents($root . '/asset.txt', 'allowlisted content');
        file_put_contents($root . '/secret.css', 'disallowed content');

        try {
            $this->handler->withStaticFileConfig(['allowed_extensions' => ['txt']]);
            $this->handler->withRootDirectory($root);

            $connection = new MockTcpConnection();
            ($this->handler)($connection, new Request("GET /asset.txt HTTP/1.1\r\nHost: test\r\n\r\n"));

            $this->assertStringContainsString('allowlisted content', $connection->sentData[0]);

            $connection = new MockTcpConnection();
            ($this->handler)($connection, new Request("GET /secret.css HTTP/1.1\r\nHost: test\r\n\r\n"));

            $this->assertStringContainsString('404 Not Found', $connection->sentData[0]);
            $this->assertStringNotContainsString('disallowed content', $connection->sentData[0]);
        } finally {
            @unlink($root . '/asset.txt');
            @unlink($root . '/secret.css');
            @rmdir($root);
        }
    }

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

    public function testControlByteInHeaderValueReturns400(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request("GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n");

        ($this->handler)($connection, $request);

        $this->assertNotEmpty($connection->sentData, 'A 400 response must be sent, not silent worker death');
        $this->assertStringContainsString('400', $connection->sentData[0], 'Control byte in header must yield 400');
        $this->assertStringContainsString('Bad Request', $connection->sentData[0]);
    }

    public function testMalformedMultipartUploadReturns400(): void
    {
        $connection = new MockTcpConnection();
        $request = new Request("POST /upload HTTP/1.1\r\nHost: test\r\nContent-Type: multipart/form-data; boundary=x\r\nConnection: close\r\n\r\n");

        $tmpFile = \tempnam(\sys_get_temp_dir(), 'wmb577_');
        $this->assertNotFalse($tmpFile);
        \file_put_contents($tmpFile, 'x');

        try {
            $reflection = new \ReflectionClass($request);
            $dataProperty = $reflection->getProperty('data');
            $dataProperty->setValue($request, [
                'files' => [
                    'malformed_file' => [
                        'name' => 'test.txt',
                        'tmp_name' => $tmpFile,
                    ],
                ],
            ]);

            ($this->handler)($connection, $request);
        } finally {
            @\unlink($tmpFile);
        }

        $this->assertNotEmpty($connection->sentData, 'Malformed upload must produce a response, not kill the worker');
        $this->assertStringContainsString('400', $connection->sentData[0], 'FileUploadValidationException must yield 400');
        $this->assertStringContainsString('Bad Request', $connection->sentData[0]);
    }

    public function testThrowingMiddlewareReturns500(): void
    {
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

    /**
     * @return array<string, array{int}>
     */
    public static function deleteFileAfterSendSizeProvider(): array
    {
        return [
            'small file' => [25],
            'file of 3 MB (streamed by Workerman)' => [3 * 1024 * 1024],
        ];
    }

    #[DataProvider('deleteFileAfterSendSizeProvider')]
    public function testDeleteFileAfterSendFileIsDeletedWhileConnectionStaysOpen(int $size): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wmb-906-');
        $this->assertNotFalse($path);
        file_put_contents($path, str_repeat('x', $size));

        $connection = new MockTcpConnection();
        $connection->context = new \stdClass();
        $originalOnClose = static function (): void {
        };
        $connection->onClose = $originalOnClose;
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n");

        $this->handler->withMiddlewares(new class ($path, $connection) implements MiddlewareInterface {
            public function __construct(private readonly string $path, private readonly MockTcpConnection $connection)
            {
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                $symfonyResponse = new \Symfony\Component\HttpFoundation\BinaryFileResponse($this->path);
                $symfonyResponse->deleteFileAfterSend(true);

                return (new BinaryFileResponseStrategy())->convert($symfonyResponse, [], $this->connection, '1.1');
            }
        });

        try {
            ($this->handler)($connection, $request);

            $this->assertFileDoesNotExist($path, 'The file must be deleted after the send, not when the connection closes');
            $this->assertFalse($connection->closed, 'The connection stays open');
            $this->assertGreaterThanOrEqual($size, strlen(implode('', $connection->sentData)), 'The whole file must be sent');
            $this->assertFalse(isset($connection->context->pendingCleanup), 'The cleanup state must not stay on a keep-alive connection');

            $this->assertSame($originalOnClose, $connection->onClose, 'Our onClose handler is gone and the old one is back');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testErrorAfterResponseHeadWasSentClosesConnectionWithoutSecondResponse(): void
    {
        $connection = new MockTcpConnection();
        $context = new \stdClass();
        $connection->context = $context;
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n");

        $this->handler->withMiddlewares(new class ($connection, $context) implements MiddlewareInterface {
            public function __construct(private readonly MockTcpConnection $connection, private readonly \stdClass $context)
            {
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                $this->connection->send("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n", true);
                $this->connection->send("5\r\nhello\r\n", true);
                $this->context->responseSentDirectly = true;

                throw new \RuntimeException('callback boom');
            }
        });

        ($this->handler)($connection, $request);

        $this->assertCount(2, $connection->sentData, 'No second response may be sent');
        $this->assertStringNotContainsString('500', implode('', $connection->sentData));
        $this->assertTrue($connection->closed, 'The connection must be closed');
        $this->assertFalse(isset($context->responseSentDirectly), 'The flag must not leak to the next request');
    }

    public function testMiddlewareThrowingInvalidArgumentExceptionIsServerFaultNotClientError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');
        $logger->expects($this->once())->method('error');

        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy, $logger);
        $handler->withMiddlewares(
            $this->throwingMiddleware(new \InvalidArgumentException('middleware bad config')),
        );

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $handler($connection, $request);

        $this->assertStringContainsString('500', $connection->sentData[0], 'Middleware \\InvalidArgumentException must be a 500 server fault, not 400');
    }

    public function testResponseWithNoMatchingStrategyReturns500(): void
    {
        $emptyConverter = new ResponseConverter([]);
        $weirdResponse = new class extends SymfonyResponse {
        };

        $kernel = new HttpHandlerTestKernel($weirdResponse);
        $controller = new SymfonyController($kernel, $emptyConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy, new NullLogger());

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $handler($connection, $request);

        $this->assertNotEmpty($connection->sentData);
        $this->assertStringContainsString('500', $connection->sentData[0], 'No-strategy response is a server fault (500)');
    }

    public function testDoTerminateStillRunsWhenPipelineThrows(): void
    {
        $strategy = $this->createMock(RebootStrategyInterface::class);
        $strategy->expects($this->once())->method('shouldReboot')->willReturn(false);

        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $strategy, new NullLogger());
        $handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('boom')),
        );

        $connection = new MockTcpConnection();
        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $handler($connection, $request);

        $this->assertNotEmpty($connection->sentData, 'Handler must send an error response on the failure path');
        $this->assertStringContainsString('500', $connection->sentData[0]);
    }

    public function testClientErrorIsLoggedAtDebugNotError(): void
    {
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
        $controller = new SymfonyController($this->kernel, $this->responseConverter);
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
        $controller = new SymfonyController($this->kernel, $this->responseConverter);
        $handler = new HttpRequestHandler($controller, $this->rebootStrategy);

        $logFile = tempnam(sys_get_temp_dir(), 'test_handler_silent_');
        $this->assertNotFalse($logFile);
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
        $connection = new MockTcpConnection();
        $request1 = new Request("GET / HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\n\r\n");
        $request2 = new Request(self::HTTP11);

        ($this->handler)($connection, $request1);
        ($this->handler)($connection, $request2);

        $this->assertCount(2, $connection->sentData, 'Both requests must get a response on the keep-alive connection');
        $this->assertStringContainsString('400', $connection->sentData[0]);
        $this->assertStringContainsString('200', $connection->sentData[1], 'Second request must succeed normally');
        $this->assertFalse($connection->closed, 'Keep-alive connection must not be closed after a 400');
    }

    public function testSoakTest10kMalformedRequestsDoesNotThrow(): void
    {
        $connection = new MockTcpConnection();
        $raw = "GET /boom HTTP/1.1\r\nHost: x\r\nX-A: \x01\r\nConnection: close\r\n\r\n";

        for ($i = 0; $i < 10000; ++$i) {
            ($this->handler)($connection, new Request($raw));
        }

        $this->assertCount(10000, $connection->sentData, 'Every malformed request must produce a response');
        foreach ($connection->sentData as $i => $data) {
            $this->assertStringContainsString('400', $data, "Request #{$i} must yield 400");
        }
    }

    public function testFailureToSendErrorResponseDoesNotEscapeHandler(): void
    {
        $connection = new class extends MockTcpConnection {
            public bool $errorSendThrowing = false;

            public function send(mixed $sendBuffer, bool $raw = false): bool
            {
                if ($this->errorSendThrowing) {
                    throw new \RuntimeException('send buffer exploded');
                }

                return parent::send($sendBuffer, $raw);
            }
        };

        $this->handler->withMiddlewares(
            $this->throwingMiddleware(new \RuntimeException('middleware boom')),
        );
        $connection->errorSendThrowing = true;

        $request = new Request("GET / HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");

        $threw = false;
        try {
            ($this->handler)($connection, $request);
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertFalse(
            $threw,
            'Handler must not escape when sendResponse() throws during error response (blocker from review)',
        );
        $this->assertTrue($connection->closed, 'Connection must be closed when error-response send fails');
    }

    public function testHandlerIsSafeForReuseAcrossTwoDifferentRequests(): void
    {
        /** @var list<string> $invocations */
        $invocations = [];
        $countingMiddleware = new class ($invocations) implements MiddlewareInterface {
            /** @var list<string> */
            public array $invocations;

            /** @param list<string> $invocations */
            public function __construct(array &$invocations)
            {
                $this->invocations = &$invocations;
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                $this->invocations[] = 'counting';
                return $next($request);
            }
        };

        $this->handler->withMiddlewares($countingMiddleware);

        $connection1 = new MockTcpConnection();
        $request1 = new Request(self::HTTP11);
        ($this->handler)($connection1, $request1);

        self::assertSame(['counting'], $invocations, 'First request should dispatch the middleware exactly once');
        self::assertStringContainsString('200', $connection1->sentData[0]);

        $connection2 = new MockTcpConnection();
        $request2 = new Request("GET /other HTTP/1.1\r\nHost: test\r\n\r\n");
        ($this->handler)($connection2, $request2);

        self::assertSame(['counting', 'counting'], $invocations, 'Second request should dispatch the middleware exactly once more');
        self::assertStringContainsString('200', $connection2->sentData[0], 'Second request via the same handler must get a fresh 200 response');
    }

    public function testShortCircuitMiddlewareDoesNotLeakStateToNextRequest(): void
    {
        $shortCircuitResponse = new WorkermanResponse(200, ['X-Short' => 'true'], 'Short');
        $shortCircuit = new class ($shortCircuitResponse) implements MiddlewareInterface {
            public function __construct(private readonly WorkermanResponse $response)
            {
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                return $this->response;
            }
        };

        $passThrough = new TestMiddleware('X-Pass', 'pass-value');

        $this->handler->withMiddlewares($shortCircuit, $passThrough);

        $connection1 = new MockTcpConnection();
        ($this->handler)($connection1, new Request(self::HTTP11));

        self::assertStringContainsString('X-Short: true', $connection1->sentData[0]);
        self::assertStringNotContainsString('X-Pass: pass-value', $connection1->sentData[0]);

        $connection2 = new MockTcpConnection();
        ($this->handler)($connection2, new Request(self::HTTP11));

        self::assertStringContainsString('X-Short: true', $connection2->sentData[0]);
        self::assertStringNotContainsString('X-Pass: pass-value', $connection2->sentData[0]);
    }
}
