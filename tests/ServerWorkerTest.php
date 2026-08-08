<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Exception\InvalidMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\MiddlewareDispatchInterface;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Http\StaticFileHandlerInterface;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Worker\ServerWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

final class ServerWorkerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/workerman-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        if (Worker::$outputStream === null) {
            $stream = fopen('php://memory', 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open memory stream');
            }
            Worker::$outputStream = $stream;
        }

        $logFile = new \ReflectionProperty(Worker::class, 'logFile');
        $logFile->setValue(null, $this->tempDir . '/workerman.log');
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    private function createKernelFactory(): KernelFactory
    {
        $kernel = $this->createMock(KernelInterface::class);
        return new KernelFactory(
            fn(): \PHPUnit\Framework\MockObject\MockObject => $kernel,
            [],
        );
    }

    public function testMissingCertThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local_cert');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_pk' => $this->tempDir . '/key.pem',
            ],
        );
    }

    public function testMissingKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('local_pk');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $this->tempDir . '/cert.pem',
            ],
        );
    }

    public function testUnreadableCertThrowsException(): void
    {
        $certFile = $this->tempDir . '/unreadable_cert.pem';
        touch($certFile);
        chmod($certFile, 0000);
        $keyFile = $this->tempDir . '/key.pem';
        touch($keyFile);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ],
        );
    }

    public function testUnreadableKeyThrowsException(): void
    {
        $certFile = $this->tempDir . '/cert.pem';
        touch($certFile);
        $keyFile = $this->tempDir . '/unreadable_key.pem';
        touch($keyFile);
        chmod($keyFile, 0000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ],
        );
    }

    public function testReusePortTrueDoesNotThrow(): void
    {
        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'http://127.0.0.1:8081',
                'reuse_port' => true,
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker, 'ServerWorker should accept reuse_port=true');
    }

    public function testReusePortFalseDoesNotThrow(): void
    {
        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'http://127.0.0.1:8082',
                'reuse_port' => false,
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker, 'ServerWorker should accept reuse_port=false');
    }

    public function testReusePortDefaultsToFalse(): void
    {
        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'http://127.0.0.1:8083',
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker, 'ServerWorker should work without reuse_port key');
    }

    public function testNonRegularCertPathThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a regular file');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $this->tempDir,
                'local_pk' => $this->tempDir . '/key.pem',
            ],
        );
    }

    public function testNonRegularKeyPathThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a regular file');

        $certFile = $this->tempDir . '/cert.pem';
        touch($certFile);

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $this->tempDir,
            ],
        );
    }

    public function testSymlinkedCertPathThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be a symlink');

        $certFile = $this->tempDir . '/cert.pem';
        touch($certFile);
        $symlink = $this->tempDir . '/cert-symlink.pem';
        symlink($certFile, $symlink);

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $symlink,
                'local_pk' => $this->tempDir . '/key.pem',
            ],
        );
    }

    public function testSymlinkedKeyPathThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be a symlink');

        $certFile = $this->tempDir . '/cert.pem';
        touch($certFile);
        $keyFile = $this->tempDir . '/key.pem';
        touch($keyFile);
        $symlink = $this->tempDir . '/key-symlink.pem';
        symlink($keyFile, $symlink);

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $symlink,
            ],
        );
    }

    public function testCorrectSslConfigurationDoesNotThrow(): void
    {
        $certFile = $this->tempDir . '/cert.pem';
        $keyFile = $this->tempDir . '/key.pem';

        $this->generateSelfSignedCert($certFile, $keyFile);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker);
    }

    public function testWssTransportWithValidSslConfigDoesNotThrow(): void
    {
        $certFile = $this->tempDir . '/cert.pem';
        $keyFile = $this->tempDir . '/key.pem';

        $this->generateSelfSignedCert($certFile, $keyFile);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'wss://0.0.0.0:8443',
                'local_cert' => $certFile,
                'local_pk' => $keyFile,
            ],
        );

        $this->assertInstanceOf(ServerWorker::class, $serverWorker);
    }

    public function testConfigureHandlerReturnsCallable(): void
    {
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->method('withStaticFileConfig')->willReturnSelf();
        $handler->method('withRootDirectory')->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            ['name' => 'test', 'listen' => 'http://127.0.0.1:8080'],
        );

        $result = $this->invokeConfigureHandler($serverWorker, $kernel, [], null);

        $this->assertSame($handler, $result);
    }

    public function testConfigureHandlerResolvesMiddlewares(): void
    {
        $middleware = $this->createMock(MiddlewareInterface::class);

        $handler = $this->getMockBuilder(MiddlewareDispatchInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withMiddlewares'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->expects($this->once())->method('withMiddlewares')->with($middleware);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->willReturnMap([
            ['workerman.http_request_handler', $handler],
            ['app.middleware.foo', $middleware],
        ]);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            ['name' => 'test', 'listen' => 'http://127.0.0.1:8080'],
        );

        $result = $this->invokeConfigureHandler(
            $serverWorker,
            $kernel,
            ['middlewares' => ['app.middleware.foo']],
            null,
        );

        $this->assertSame($handler, $result);
    }

    public function testConfigureHandlerThrowsForInvalidMiddleware(): void
    {
        $this->expectException(InvalidMiddlewareException::class);
        $this->expectExceptionMessage('Service "app.middleware.invalid" must implement');

        $invalidService = new \stdClass();

        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->willReturnMap([
            ['workerman.http_request_handler', $handler],
            ['app.middleware.invalid', $invalidService],
        ]);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            ['name' => 'test', 'listen' => 'http://127.0.0.1:8080'],
        );

        $this->invokeConfigureHandler(
            $serverWorker,
            $kernel,
            ['middlewares' => ['app.middleware.invalid']],
            null,
        );
    }

    public function testConfigureHandlerConfiguresStaticFiles(): void
    {
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->expects($this->once())->method('withStaticFileConfig')
            ->with(['allowed_extensions' => ['css', 'js']])
            ->willReturnSelf();
        $handler->expects($this->once())->method('withRootDirectory')
            ->with('/path/to/public')
            ->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $serverWorker = new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            ['name' => 'test', 'listen' => 'http://127.0.0.1:8080'],
        );

        $result = $this->invokeConfigureHandler(
            $serverWorker,
            $kernel,
            ['static_files' => ['allowed_extensions' => ['css', 'js']]],
            '/path/to/public',
        );

        $this->assertSame($handler, $result);
    }

    public function testOnWorkerStartBootsKernelAndSetsOnMessage(): void
    {
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->method('withStaticFileConfig')->willReturnSelf();
        $handler->method('withRootDirectory')->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects($this->once())->method('boot');
        $kernel->method('getContainer')->willReturn($container);

        $kernelFactory = new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-boot-test', 'listen' => 'http://127.0.0.1:8091'],
        );

        $worker = $this->findWorkerByName('[Server] ows-boot-test');
        $this->assertNotNull($worker, 'Worker should have been created by ServerWorker');

        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);

        $this->assertNotNull($worker->onMessage, 'onMessage should be set after onWorkerStart');
        $this->assertInstanceOf(\Closure::class, $worker->onMessage, 'onMessage should be a closure that wraps the handler');
    }

    public function testOnMessageResetsHeadersAfterHandlerReturnsAndThrows(): void
    {
        $requests = [];
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->method('withStaticFileConfig')->willReturnSelf();
        $handler->method('withRootDirectory')->willReturnSelf();
        $handler->method('__invoke')->willReturnCallback(function ($connection, Request $request) use (&$requests): void {
            $requests[] = $request->header('x-internal');
            $request->setHeader('X-Internal', 'secret');
            if (count($requests) === 2) {
                throw new \RuntimeException('handler failure');
            }
        });

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);
        $kernelFactory = new KernelFactory(fn(): KernelInterface => $kernel, []);

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-header-reset', 'listen' => 'http://127.0.0.1:8107'],
        );

        $worker = $this->findWorkerByName('[Server] ows-header-reset');
        $this->assertNotNull($worker);
        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);

        $connection = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $connection->context = new \stdClass();
        $connection->context->connectionTimerId = null;
        $connection->context->keepaliveTimerId = null;
        $onMessage = $worker->onMessage;
        $this->assertNotNull($onMessage);
        $request = new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $onMessage($connection, $request);
        try {
            $onMessage($connection, $request);
        } catch (\RuntimeException $exception) {
            $this->assertSame('handler failure', $exception->getMessage());
        }
        $onMessage($connection, $request);

        $this->assertSame([null, null, null], $requests);
        $this->assertNull($request->header('x-internal'));
    }

    public function testOnWorkerStartResolvesMiddlewares(): void
    {
        $middleware = $this->createMock(MiddlewareInterface::class);

        $handler = $this->getMockBuilder(MiddlewareDispatchInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withMiddlewares'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->expects($this->once())->method('withMiddlewares')->with($middleware);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->willReturnMap([
            ['workerman.http_request_handler', $handler],
            ['app.middleware.bar', $middleware],
        ]);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $kernelFactory = new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            [
                'name' => 'ows-middleware-test',
                'listen' => 'http://127.0.0.1:8092',
                'middlewares' => ['app.middleware.bar'],
            ],
        );

        $worker = $this->findWorkerByName('[Server] ows-middleware-test');
        $this->assertNotNull($worker);

        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);
    }

    public function testOnWorkerStartThrowsForInvalidMiddleware(): void
    {
        $this->expectException(InvalidMiddlewareException::class);
        $this->expectExceptionMessage('Service "app.middleware.invalid" must implement');

        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->method('withStaticFileConfig')->willReturnSelf();
        $handler->method('withRootDirectory')->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->willReturnMap([
            ['workerman.http_request_handler', $handler],
            ['app.middleware.invalid', new \stdClass()],
        ]);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $kernelFactory = new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            [
                'name' => 'ows-invalid-mw',
                'listen' => 'http://127.0.0.1:8093',
                'middlewares' => ['app.middleware.invalid'],
            ],
        );

        $worker = $this->findWorkerByName('[Server] ows-invalid-mw');
        $this->assertNotNull($worker);

        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);
    }

    public function testOnWorkerStartConfiguresStaticFiles(): void
    {
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->expects($this->once())->method('withStaticFileConfig')
            ->with(['allowed_extensions' => ['css', 'js']])
            ->willReturnSelf();
        $handler->expects($this->once())->method('withRootDirectory')
            ->with('/path/to/public')
            ->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getContainer')->willReturn($container);

        $kernelFactory = new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            [
                'name' => 'ows-static-files',
                'listen' => 'http://127.0.0.1:8094',
                'serve_files' => true,
                'root_dir' => '/path/to/public',
                'static_files' => ['allowed_extensions' => ['css', 'js']],
            ],
        );

        $worker = $this->findWorkerByName('[Server] ows-static-files');
        $this->assertNotNull($worker);

        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);
    }

    public function testOnConnectSetsBodySizeCap(): void
    {
        $kernelFactory = $this->createKernelFactory();

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            [
                'name' => 'ows-body-cap',
                'listen' => 'http://127.0.0.1:8096',
                'body_size_cap' => 8192,
            ],
        );

        $worker = $this->findWorkerByName('[Server] ows-body-cap');
        $this->assertNotNull($worker);
        $this->assertNotNull($worker->onConnect, 'onConnect should be set when body_size_cap is configured');
    }

    public function testOnConnectWithoutBodySizeCapDoesNotCrash(): void
    {
        $kernelFactory = $this->createKernelFactory();

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-no-body-cap', 'listen' => 'http://127.0.0.1:8097'],
        );

        $worker = $this->findWorkerByName('[Server] ows-no-body-cap');
        $this->assertNotNull($worker);
        $this->assertNotNull($worker->onConnect, 'onConnect should always be set for timeout handling');
    }

    // ──────────────────────────────────────────────
    // Issue #577 — defence-in-depth: $connection->errorHandler backstop
    //
    // Even if HttpRequestHandler's own try/catch misses a throw site added
    // in the future, the TcpConnection error handler must be set so that
    // Workerman closes the connection instead of calling Worker::stopAll()
    // and killing the whole worker process.
    // ──────────────────────────────────────────────

    public function testOnConnectSetsErrorHandlerBackstop(): void
    {
        $kernelFactory = $this->createKernelFactory();

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-error-handler', 'listen' => 'http://127.0.0.1:8095'],
        );

        $worker = $this->findWorkerByName('[Server] ows-error-handler');
        $this->assertNotNull($worker);
        $this->assertNotNull($worker->onConnect, 'onConnect must be set');

        // Instantiate TcpConnection without invoking the constructor so we
        // don't need a real EventLoop / readable socket. onConnect only
        // touches $maxPackageSize, $context and $errorHandler.
        $connection = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $connection->context = new \stdClass();

        $onConnect = $worker->onConnect;
        $onConnect($connection);

        $this->assertNotNull(
            $connection->errorHandler,
            'TcpConnection->errorHandler must be set by onConnect as a worker-death backstop (issue #577)',
        );
        $this->assertIsCallable($connection->errorHandler, 'errorHandler must be callable');
    }

    public function testErrorHandlerBackstopClosesConnectionInsteadOfKillingWorker(): void
    {
        // The errorHandler installed by onConnect must close the connection
        // when invoked with a throwable, NOT rethrow or call Worker::stopAll().
        $kernelFactory = $this->createKernelFactory();

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-error-handler-behavior', 'listen' => 'http://127.0.0.1:8105'],
        );

        $worker = $this->findWorkerByName('[Server] ows-error-handler-behavior');
        $this->assertNotNull($worker);

        $connection = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $connection->context = new \stdClass();
        $connection->context->connectionTimerId = null;

        $onConnect = $worker->onConnect;
        $this->assertNotNull($onConnect, 'onConnect must be set');
        $onConnect($connection);

        $handler = $connection->errorHandler;
        $this->assertNotNull($handler);

        // The backstop logs by design. Capture that expected log output so
        // this behavioral test does not make the test suite look failed.
        $logFile = tempnam(sys_get_temp_dir(), 'test_backstop_');
        $this->assertNotFalse($logFile);
        ini_set('error_log', $logFile);
        try {
            $threw = false;
            try {
                $handler(new \RuntimeException('simulated escape from handler'));
            } catch (\Throwable) {
                $threw = true;
            }
        } finally {
            ini_restore('error_log');
            @unlink($logFile);
        }

        $this->assertFalse($threw, 'errorHandler backstop must not rethrow (would bypass Worker::stopAll guard)');
    }

    public function testErrorHandlerBackstopLogsToErrorLog(): void
    {
        // Major finding from review: the backstop must not silently swallow
        // throwables — operators need visibility when the defence-in-depth
        // backstop fires. We assert it writes to error_log with the
        // exception message.
        $kernelFactory = $this->createKernelFactory();

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-error-handler-log', 'listen' => 'http://127.0.0.1:8106'],
        );

        $worker = $this->findWorkerByName('[Server] ows-error-handler-log');
        $this->assertNotNull($worker);

        $connection = (new \ReflectionClass(TcpConnection::class))->newInstanceWithoutConstructor();
        $connection->context = new \stdClass();
        $connection->context->connectionTimerId = null;

        $onConnect = $worker->onConnect;
        $this->assertNotNull($onConnect);
        $onConnect($connection);

        $handler = $connection->errorHandler;
        $this->assertNotNull($handler);

        $logFile = tempnam(sys_get_temp_dir(), 'test_backstop_');
        $this->assertNotFalse($logFile);
        file_put_contents($logFile, '');
        ini_set('error_log', $logFile);
        try {
            $handler(new \RuntimeException('backstop-visibility-check'));
        } finally {
            ini_restore('error_log');
        }

        $logContent = file_get_contents($logFile);
        @unlink($logFile);

        $this->assertIsString($logContent);
        $this->assertStringContainsString('backstop-visibility-check', $logContent, 'Backstop must log the escaped throwable to error_log');
    }

    public function testOnConnectClosureCapturesBodySizeCap(): void
    {
        $kernelFactory = $this->createKernelFactory();

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            [
                'name' => 'ows-closure-cap',
                'listen' => 'http://127.0.0.1:8098',
                'body_size_cap' => 16384,
            ],
        );

        $worker = $this->findWorkerByName('[Server] ows-closure-cap');
        $this->assertNotNull($worker);

        assert($worker->onConnect instanceof \Closure);
        $ref = new \ReflectionFunction($worker->onConnect);
        $vars = $ref->getStaticVariables();

        $this->assertArrayHasKey('bodySizeCap', $vars);
        $this->assertSame(16384, $vars['bodySizeCap']);
    }

    public function testOnWorkerStartWrapsOnMessageWithKeepaliveTimeout(): void
    {
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->method('withStaticFileConfig')->willReturnSelf();
        $handler->method('withRootDirectory')->willReturnSelf();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('boot');
        $kernel->method('getContainer')->willReturn($container);

        $kernelFactory = new KernelFactory(
            fn(): KernelInterface => $kernel,
            [],
        );

        new ServerWorker(
            $kernelFactory,
            null,
            null,
            ['name' => 'ows-keepalive', 'listen' => 'http://127.0.0.1:8099'],
            connectionTimeout: 60,
            keepaliveTimeout: 15,
        );

        $worker = $this->findWorkerByName('[Server] ows-keepalive');
        $this->assertNotNull($worker);

        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);

        $this->assertNotNull($worker->onMessage, 'onMessage should be set after onWorkerStart');
    }

    public function testOnCloseCancelsPerConnectionTimersAndClearsContext(): void
    {
        $worker = $this->createStartedWorkerForTimerTests('ows-close-timers', 5, 5);
        $eventLoop = new Select();
        Timer::init($eventLoop);

        [$idleConnection, $idlePeer] = $this->createRealConnection($eventLoop);
        [$keepaliveConnection, $keepalivePeer] = $this->createRealConnection($eventLoop);
        $this->bindConnectionToWorker($idleConnection, $worker);
        $this->bindConnectionToWorker($keepaliveConnection, $worker);

        try {
            $baseline = $eventLoop->getTimerCount();
            $this->assertSame(0, $baseline);

            $onConnect = $worker->onConnect;
            $this->assertNotNull($onConnect);
            $onConnect($idleConnection);
            $this->assertSame($baseline + 1, $eventLoop->getTimerCount(), 'connection timeout timer should be armed on connect');

            $idleConnection->close();
            $this->assertSame($baseline, $eventLoop->getTimerCount(), 'closing before a request must cancel the connection-timeout timer');
            $this->assertNull($idleConnection->context, 'worker-level onClose should clear connection context');

            $onConnect($keepaliveConnection);
            $onMessage = $worker->onMessage;
            $this->assertNotNull($onMessage);
            $onMessage($keepaliveConnection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            $this->assertSame($baseline + 1, $eventLoop->getTimerCount(), 'keepalive timer should be armed after a request');

            $keepaliveConnection->close();
            $this->assertSame($baseline, $eventLoop->getTimerCount(), 'closing after a request must cancel the keepalive timer');
            $this->assertNull($keepaliveConnection->context, 'worker-level onClose should clear connection context after keepalive close');
        } finally {
            Timer::delAll();
            @fclose($idlePeer);
            @fclose($keepalivePeer);
        }
    }

    public function testClosedConnectionIsCollectableAfterDestroyAndEventLoopTick(): void
    {
        $worker = $this->createStartedWorkerForTimerTests('ows-weakref', 5, 5);
        $eventLoop = new Select();
        Timer::init($eventLoop);

        [$connection, $peer] = $this->createRealConnection($eventLoop);
        $this->bindConnectionToWorker($connection, $worker);

        try {
            $onConnect = $worker->onConnect;
            $this->assertNotNull($onConnect);
            $onConnect($connection);

            $weakReference = \WeakReference::create($connection);

            $connection->destroy();
            unset($connection);
            gc_collect_cycles();

            $this->runEventLoopFor($eventLoop, 0.01);
            gc_collect_cycles();

            $this->assertNull($weakReference->get(), 'closed connection should be collectable after destroy() and one event-loop tick');
        } finally {
            Timer::delAll();
            @fclose($peer);
        }
    }

    public function testConnectionTimeoutStillClosesIdleConnections(): void
    {
        $worker = $this->createStartedWorkerForTimerTests('ows-connection-timeout', 1, 5);
        $eventLoop = new Select();
        Timer::init($eventLoop);

        [$connection, $peer] = $this->createRealConnection($eventLoop);
        $this->bindConnectionToWorker($connection, $worker);

        try {
            $onConnect = $worker->onConnect;
            $this->assertNotNull($onConnect);
            $onConnect($connection);

            $this->runEventLoopFor($eventLoop, 1.3);

            $this->assertSame(TcpConnection::STATUS_CLOSED, $connection->getStatus());
            $this->assertNull($connection->context);
            $this->assertSame(0, $eventLoop->getTimerCount());
        } finally {
            Timer::delAll();
            @fclose($peer);
        }
    }

    public function testKeepaliveTimeoutStillClosesInactiveConnections(): void
    {
        $worker = $this->createStartedWorkerForTimerTests('ows-keepalive-timeout', 5, 1);
        $eventLoop = new Select();
        Timer::init($eventLoop);

        [$connection, $peer] = $this->createRealConnection($eventLoop);
        $this->bindConnectionToWorker($connection, $worker);

        try {
            $onConnect = $worker->onConnect;
            $onMessage = $worker->onMessage;
            $this->assertNotNull($onConnect);
            $this->assertNotNull($onMessage);

            $onConnect($connection);
            $onMessage($connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));

            $this->runEventLoopFor($eventLoop, 1.3);

            $this->assertSame(TcpConnection::STATUS_CLOSED, $connection->getStatus());
            $this->assertNull($connection->context);
            $this->assertSame(0, $eventLoop->getTimerCount());
        } finally {
            Timer::delAll();
            @fclose($peer);
        }
    }

    public function testKeepaliveTimeoutZeroDoesNotScheduleKeepaliveTimer(): void
    {
        $worker = $this->createStartedWorkerForTimerTests('ows-keepalive-zero', 5, 0);
        $eventLoop = new Select();
        Timer::init($eventLoop);

        [$connection, $peer] = $this->createRealConnection($eventLoop);
        $this->bindConnectionToWorker($connection, $worker);

        try {
            $onConnect = $worker->onConnect;
            $onMessage = $worker->onMessage;
            $this->assertNotNull($onConnect);
            $this->assertNotNull($onMessage);

            $onConnect($connection);
            $this->assertSame(1, $eventLoop->getTimerCount(), 'connection timeout timer should be armed before the first request');

            $onMessage($connection, new Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n"));
            $this->assertSame(0, $eventLoop->getTimerCount(), 'keepaliveTimeout=0 must leave no timer armed after a request');

            $this->runEventLoopFor($eventLoop, 0.05);

            $this->assertSame(TcpConnection::STATUS_ESTABLISHED, $connection->getStatus(), 'keepaliveTimeout=0 should not close active keep-alive connections');
        } finally {
            $connection->destroy();
            Timer::delAll();
            @fclose($peer);
        }
    }

    private function findWorkerByName(string $name): ?Worker
    {
        foreach (Worker::getAllWorkers() as $worker) {
            if ($worker->name === $name) {
                return $worker;
            }
        }

        return null;
    }

    private function createStartedWorkerForTimerTests(string $name, int $connectionTimeout, int $keepaliveTimeout): Worker
    {
        $handler = $this->getMockBuilder(StaticFileHandlerInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['withStaticFileConfig', 'withRootDirectory'])
            ->addMethods(['__invoke'])
            ->getMock();
        $handler->method('withStaticFileConfig')->willReturnSelf();
        $handler->method('withRootDirectory')->willReturnSelf();
        $handler->method('__invoke')->willReturn(null);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('workerman.http_request_handler')->willReturn($handler);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('boot');
        $kernel->method('getContainer')->willReturn($container);

        new ServerWorker(
            new KernelFactory(fn(): KernelInterface => $kernel, []),
            null,
            null,
            [
                'name' => $name,
                'listen' => 'http://127.0.0.1:0',
            ],
            connectionTimeout: $connectionTimeout,
            keepaliveTimeout: $keepaliveTimeout,
        );

        $worker = $this->findWorkerByName('[Server] ' . $name);
        $this->assertNotNull($worker);

        $onWorkerStart = $worker->onWorkerStart;
        $this->assertNotNull($onWorkerStart);
        $onWorkerStart($worker);

        return $worker;
    }

    /**
     * @return array{0: TcpConnection, 1: resource}
     */
    private function createRealConnection(Select $eventLoop): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            $this->fail('Failed to create socket pair for TcpConnection test');
        }

        [$serverSocket, $peerSocket] = $pair;

        return [
            new TcpConnection($eventLoop, $serverSocket, '127.0.0.1:12345'),
            $peerSocket,
        ];
    }

    private function bindConnectionToWorker(TcpConnection $connection, Worker $worker): void
    {
        // Mirrors Workerman\Worker::acceptTcpConnection(), which copies the worker-level
        // callbacks onto each accepted connection. Keep in sync if Workerman changes this.
        $connection->worker = $worker;
        $worker->connections[$connection->id] = $connection;
        $connection->onClose = $worker->onClose;
        $connection->onMessage = $worker->onMessage;
        $connection->onError = $worker->onError;
        $connection->onBufferDrain = $worker->onBufferDrain;
        $connection->onBufferFull = $worker->onBufferFull;
    }

    private function runEventLoopFor(Select $eventLoop, float $seconds): void
    {
        $eventLoop->delay($seconds, static function () use ($eventLoop): void {
            $eventLoop->stop();
        });
        $eventLoop->run();
    }

    /**
     * @param mixed[] $serverConfig
     */
    private function invokeConfigureHandler(ServerWorker $serverWorker, KernelInterface $kernel, array $serverConfig, ?string $rootDir): mixed
    {
        $reflection = new \ReflectionMethod(ServerWorker::class, 'configureHandler');

        return $reflection->invoke($serverWorker, $kernel, $serverConfig, $rootDir);
    }

    private function generateSelfSignedCert(string $certFile, string $keyFile): void
    {
        $dn = ['countryName' => 'US', 'stateOrProvinceName' => 'Test', 'localityName' => 'TestCity', 'organizationName' => 'Test Org'];

        $privkey = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($privkey === false) {
            throw new \RuntimeException('Failed to generate private key');
        }

        $cert = \openssl_csr_new($dn, $privkey, ['digest_alg' => 'sha256']);
        if ($cert === false || $cert === true) {
            throw new \RuntimeException('Failed to generate CSR');
        }

        $x509 = \openssl_csr_sign($cert, null, $privkey, 365);
        if ($x509 === false) {
            throw new \RuntimeException('Failed to sign certificate');
        }

        if (!\openssl_pkey_export_to_file($privkey, $keyFile)) {
            throw new \RuntimeException('Failed to export private key');
        }

        if (!\openssl_x509_export_to_file($x509, $certFile)) {
            throw new \RuntimeException('Failed to export certificate');
        }
    }
}
