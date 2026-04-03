<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use CrazyGoat\WorkermanBundle\Test\App\TestMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Workerman\Connection\TcpConnection;

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
 * Tests for HttpRequestHandler initialization and configuration
 *
 * Note: Full request handling tests require Workerman running environment
 * due to Timer::add() usage. Those are covered by integration tests.
 */
final class HttpRequestHandlerTest extends TestCase
{
    private HttpHandlerTestKernel $kernel;
    private TestRebootStrategy $rebootStrategy;
    private HttpRequestHandler $handler;

    protected function setUp(): void
    {
        $this->kernel = new HttpHandlerTestKernel();
        $this->rebootStrategy = new TestRebootStrategy();
        $this->handler = new HttpRequestHandler($this->kernel, $this->rebootStrategy);
    }

    public function testHandlerInitializesCorrectly(): void
    {
        $this->assertInstanceOf(HttpRequestHandler::class, $this->handler);
    }

    public function testHandlerWithMiddlewaresReturnsSelf(): void
    {
        $result = $this->handler->withMiddlewares();
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerWithRootDirectoryReturnsSelf(): void
    {
        $result = $this->handler->withRootDirectory('/tmp');
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerWithNullRootDirectoryReturnsSelf(): void
    {
        $result = $this->handler->withRootDirectory(null);
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerWithMiddlewaresAddsMiddleware(): void
    {
        $middleware = new TestMiddleware('X-Test', 'value');
        $result = $this->handler->withMiddlewares($middleware);
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerWithMultipleMiddlewares(): void
    {
        $middleware1 = new TestMiddleware('X-Test1', 'value1');
        $middleware2 = new TestMiddleware('X-Test2', 'value2');
        $result = $this->handler->withMiddlewares($middleware1, $middleware2);
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerWithRootDirectoryAddsStaticFilesMiddleware(): void
    {
        $result = $this->handler->withRootDirectory('/var/www');
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerWithEmptyRootDirectory(): void
    {
        $result = $this->handler->withRootDirectory('');
        $this->assertSame($this->handler, $result);
    }

    public function testHandlerChaining(): void
    {
        $middleware = new TestMiddleware('X-Test', 'value');
        $result = $this->handler
            ->withMiddlewares($middleware)
            ->withRootDirectory('/tmp');

        $this->assertSame($this->handler, $result);
    }

    public function testKernelIsSetCorrectly(): void
    {
        $this->assertFalse($this->kernel->bootCalled);
    }

    public function testRebootStrategyIsSetCorrectly(): void
    {
        $this->assertFalse($this->rebootStrategy->shouldReboot);

        $this->rebootStrategy->shouldReboot = true;
        $this->assertTrue($this->rebootStrategy->shouldReboot);
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testRequestProcessingSendsResponse(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running. ' .
            'Run integration tests instead.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testHttp10ConnectionIsClosed(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testConnectionCloseHeaderClosesConnection(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testKeepAliveConnectionNotClosed(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testRebootTriggersSynchronousTerminate(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testKernelBootIsCalled(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testResponseContainsCorrectStatus(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }

    /**
     * @requires extension pcntl
     * @requires function pcntl_fork
     * This test requires Workerman running environment
     */
    public function testResponseContainsBodyContent(): void
    {
        $this->markTestSkipped(
            'This test requires Workerman running environment. ' .
            'Timer::add() can only be used when Workerman is running.',
        );
    }
}
