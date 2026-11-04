<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\EarlyHints;
use CrazyGoat\WorkermanBundle\Http\HttpRequestHandler;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverter;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\DefaultResponseStrategy;
use CrazyGoat\WorkermanBundle\Middleware\SymfonyController;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;

/**
 * Tests for issue #1018: AbstractController::sendEarlyHints() writes a
 * 103 Early Hints interim response to the connection ahead of the final
 * response.
 *
 * The controller below calls the real sendEarlyHints(); the bundle's
 * headers_send() polyfill routes the 103 through EarlyHints, which writes
 * the raw bytes to the captured connection.
 */
final class EarlyHintsTest extends TestCase
{
    protected function tearDown(): void
    {
        EarlyHints::disarm();
    }

    public function testSendEarlyHintsWrites103BeforeFinalResponse(): void
    {
        $connection = $this->createConnection();
        $handler = $this->createHandler();

        $handler($connection, new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n"));

        $this->assertCount(2, $connection->sentData);
        $this->assertStringStartsWith("HTTP/1.1 103 Early Hints\r\n", $connection->sentData[0]);
        $this->assertStringContainsString('Link: </style.css>; rel=preload', $connection->sentData[0]);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $connection->sentData[1]);
        $this->assertStringContainsString('final body', $connection->sentData[1]);
        $this->assertFalse($connection->closed, 'Keep-alive connection must stay open');
    }

    public function testKeepAliveStillWorksAfterEarlyHints(): void
    {
        $connection = $this->createConnection();
        $handler = $this->createHandler();

        $handler($connection, new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n"));
        $handler($connection, new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n"));

        $this->assertCount(4, $connection->sentData);
        $this->assertStringStartsWith("HTTP/1.1 103 Early Hints\r\n", $connection->sentData[2]);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $connection->sentData[3]);
        $this->assertStringContainsString('final body', $connection->sentData[3]);
        $this->assertFalse($connection->closed, 'Keep-alive connection must stay open');
    }

    public function testHttp10RequestGetsNo103(): void
    {
        $connection = $this->createConnection();
        $handler = $this->createHandler();

        $handler($connection, new Request("GET / HTTP/1.0\r\nHost: test\r\n\r\n"));

        $this->assertCount(1, $connection->sentData);
        $this->assertStringStartsWith('HTTP/1.0 200 OK', $connection->sentData[0]);
        $this->assertStringContainsString('final body', $connection->sentData[0]);
    }

    public function testNo103WhenControllerDoesNotSendEarlyHints(): void
    {
        $connection = $this->createConnection();
        $kernel = new EarlyHintsTestKernel(static fn(): SymfonyResponse => new SymfonyResponse('plain'));
        $handler = $this->createHandlerWithKernel($kernel);

        $handler($connection, new Request("GET / HTTP/1.1\r\nHost: test\r\n\r\n"));

        $this->assertCount(1, $connection->sentData);
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $connection->sentData[0]);
    }

    public function testSendWithoutArmedConnectionIsNoop(): void
    {
        EarlyHints::send(103);

        $this->assertSame([], headers_list(), 'Staged headers must not leak without an armed connection');
    }

    private function createContainer(): Container
    {
        $container = new Container();
        $container->set('web_link.http_header_serializer', new class {
            /** @param list<mixed> $links */
            public function serialize(array $links): string
            {
                return '</style.css>; rel=preload';
            }
        });

        return $container;
    }

    private function createConnection(): EarlyHintsCaptureConnection
    {
        $connection = new EarlyHintsCaptureConnection();
        $connection->context = new \stdClass();

        return $connection;
    }

    private function createHandler(): HttpRequestHandler
    {
        $controller = new EarlyHintsController();
        $controller->setContainer($this->createContainer());

        return $this->createHandlerWithKernel(new EarlyHintsTestKernel(
            static fn(): SymfonyResponse => $controller->earlyHintsAction(),
        ));
    }

    private function createHandlerWithKernel(KernelInterface $kernel): HttpRequestHandler
    {
        $controller = new SymfonyController($kernel, new ResponseConverter([new DefaultResponseStrategy()]));

        return new HttpRequestHandler($controller, new EarlyHintsTestRebootStrategy(), new NullLogger());
    }
}

/**
 * Test controller that calls the real AbstractController::sendEarlyHints().
 */
final class EarlyHintsController extends AbstractController
{
    public function earlyHintsAction(): SymfonyResponse
    {
        // The stub serializer ignores the link objects; what matters is that
        // sendEarlyHints() -> sendHeaders(103) -> headers_send() fires.
        $response = $this->sendEarlyHints([new \stdClass()]);
        $response->setContent('final body');

        return $response;
    }
}

/**
 * Minimal kernel stub whose handle() delegates to a controller callable.
 */
final class EarlyHintsTestKernel implements KernelInterface
{
    /** @var callable(): SymfonyResponse */
    private $action;

    /** @param callable(): SymfonyResponse $action */
    public function __construct(callable $action)
    {
        $this->action = $action;
    }

    public function handle(SymfonyRequest $request, int $type = 1, bool $catch = true): SymfonyResponse
    {
        return ($this->action)();
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

    public function getContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
    {
        throw new \RuntimeException('Not implemented');
    }

    public function getStartTime(): float
    {
        return 0.0;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir();
    }
}

final class EarlyHintsTestRebootStrategy implements RebootStrategyInterface
{
    public function shouldReboot(): bool
    {
        return false;
    }
}

/**
 * TcpConnection double that captures raw sends without a socket.
 */
class EarlyHintsCaptureConnection extends TcpConnection
{
    /** @var list<string> */
    public array $sentData = [];
    public bool $closed = false;

    public function __construct()
    {
        // Bypass parent constructor — no socket needed.
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
