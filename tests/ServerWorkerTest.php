<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Exception\InvalidMiddlewareException;
use CrazyGoat\WorkermanBundle\Http\MiddlewareDispatchInterface;
use CrazyGoat\WorkermanBundle\Http\StaticFileHandlerInterface;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use CrazyGoat\WorkermanBundle\Worker\ServerWorker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => '/nonexistent/cert.pem',
                'local_pk' => $this->tempDir . '/key.pem',
            ],
        );
    }

    public function testUnreadableKeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');

        new ServerWorker(
            $this->createKernelFactory(),
            null,
            null,
            [
                'name' => 'test-server',
                'listen' => 'https://0.0.0.0:8443',
                'local_cert' => $this->tempDir . '/cert.pem',
                'local_pk' => '/nonexistent/key.pem',
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
        $this->assertSame($handler, $worker->onMessage);
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

    private function findWorkerByName(string $name): ?Worker
    {
        foreach (Worker::getAllWorkers() as $worker) {
            if ($worker->name === $name) {
                return $worker;
            }
        }

        return null;
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
