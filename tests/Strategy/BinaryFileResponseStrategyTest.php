<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\Strategy\BinaryFileResponseStrategy;
use CrazyGoat\WorkermanBundle\Http\Response\Strategy\HeadResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Workerman\Connection\TcpConnection;

final class BinaryFileResponseStrategyTest extends TestCase
{
    private string $testFile;
    private TcpConnection $connection;

    protected function setUp(): void
    {
        $this->testFile = sys_get_temp_dir() . '/test_binary_file_' . uniqid() . '.txt';
        file_put_contents($this->testFile, 'Hello World from binary file!');
        $this->connection = new class extends TcpConnection {
            public function __construct()
            {
                // Bypass parent constructor — we only need the public properties.
            }
        };
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFile)) {
            unlink($this->testFile);
        }
    }

    public function testSupportsReturnsTrueForBinaryFileResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile);

        $this->assertTrue($strategy->supports($binaryResponse));
    }

    public function testSupportsReturnsFalseForRegularResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $regularResponse = new Response('Hello');

        $this->assertFalse($strategy->supports($regularResponse));
    }

    public function testConvertReturnsWorkermanResponseWithFile(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNotNull($workermanResponse->file);
    }

    public function testConvertHandlesFileWithCustomHeaders(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="report.pdf"',
        ]);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['application/pdf'],
            'Content-Disposition' => ['attachment; filename="report.pdf"'],
        ], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNotNull($workermanResponse->file);
    }

    public function testConvertHandlesNotFoundResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_NOT_FOUND);

        $workermanResponse = $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $this->assertSame(404, $workermanResponse->getStatusCode());
    }

    public function testConvertHandlesTempFileObject(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp file content');

        $binaryResponse = new BinaryFileResponse($this->testFile);

        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($binaryResponse, $tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertSame('Temp file content', $workermanResponse->rawBody());
    }

    public function testConvertHandlesRangeRequest(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_OK, [
            'Content-Range' => 'bytes 0-4/29',
        ]);

        $reflection = new \ReflectionClass($binaryResponse);
        $offsetProperty = $reflection->getProperty('offset');
        $offsetProperty->setValue($binaryResponse, 0);
        $maxlenProperty = $reflection->getProperty('maxlen');
        $maxlenProperty->setValue($binaryResponse, 5);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Range' => ['bytes 0-4/29'],
        ], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNotNull($workermanResponse->file);
    }

    public function testConvertHandlesDeleteFileAfterSendViaBufferDrain(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/delete_me_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Delete me after send!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $this->assertFileExists($tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertFileExists($tempFile);
        $this->assertNotNull($workermanResponse->file);

        // Simulate buffer drain (primary cleanup path)
        $onBufferDrain = $this->connection->onBufferDrain;
        $this->assertNotNull($onBufferDrain, 'onBufferDrain callback should be registered');
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        $this->assertFileDoesNotExist($tempFile);
    }

    public function testConvertHandlesDeleteFileAfterSendViaOnCloseFallback(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/delete_fallback_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Fallback delete!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ], $this->connection, '1.1');

        // Simulate connection close without buffer drain (early disconnect)
        $onCloseCallback = $this->connection->onClose;
        $this->assertNotNull($onCloseCallback, 'onClose fallback callback should be registered');
        assert(is_callable($onCloseCallback));
        $onCloseCallback($this->connection);

        $this->assertFileDoesNotExist($tempFile);
    }

    public function testConvertPreservesExistingOnCloseCallback(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/delete_chain_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Chain me!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $existingCalled = false;
        $this->connection->onClose = function (TcpConnection $conn) use (&$existingCalled): void {
            $existingCalled = true;
        };

        $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ], $this->connection, '1.1');

        // Trigger via onClose fallback
        $onCloseCallback = $this->connection->onClose;
        $onCloseCallback($this->connection);

        $this->assertTrue($existingCalled, 'Previous onClose callback must be invoked');
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testBufferDrainPreservesExistingOnCloseCallback(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/drain_preserve_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Drain preserve!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $existingOnClose = function (TcpConnection $conn): void {
        };
        $this->connection->onClose = $existingOnClose;

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        // Simulate buffer drain (primary path)
        $onBufferDrain = $this->connection->onBufferDrain;
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        // After buffer drain, onClose should be restored to the original
        $this->assertSame(
            $existingOnClose,
            $this->connection->onClose,
            'onClose must be restored to original after buffer drain fires',
        );
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testBufferDrainRestoresWorkerLevelOnCloseChain(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/drain_worker_base_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Drain worker base!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $workerOnCloseCalled = false;
        $this->connection->context = new \stdClass();
        $this->connection->onClose = function (TcpConnection $conn) use (&$workerOnCloseCalled): void {
            $workerOnCloseCalled = true;
            $conn->context = null;
        };

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $onBufferDrain = $this->connection->onBufferDrain;
        $this->assertNotNull($onBufferDrain);
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        $this->assertFileDoesNotExist($tempFile);
        $this->assertNotNull($this->connection->onClose, 'worker-level onClose should be restored after buffer drain');

        $restoredOnClose = $this->connection->onClose;
        assert(is_callable($restoredOnClose));
        $restoredOnClose($this->connection);

        $this->assertTrue($workerOnCloseCalled, 'restored worker-level onClose callback must still run');
        $this->assertNull($this->connection->context, 'restored worker-level onClose should still be able to clear context');
    }

    public function testBufferDrainPreservesExistingOnBufferDrainCallback(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/drain_chain_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Drain chain!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $existingDrainCalled = false;
        $existingOnBufferDrain = function (TcpConnection $conn) use (&$existingDrainCalled): void {
            $existingDrainCalled = true;
        };
        $this->connection->onBufferDrain = $existingOnBufferDrain;

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        // Simulate buffer drain
        $onBufferDrain = $this->connection->onBufferDrain;
        $onBufferDrain($this->connection);

        $this->assertTrue($existingDrainCalled, 'Previous onBufferDrain callback must be invoked');
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testBufferDrainSelfRemovesAfterFiring(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/drain_selfremove_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Self-remove!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $onBufferDrain = $this->connection->onBufferDrain;
        $this->assertNotNull($onBufferDrain);
        assert(is_callable($onBufferDrain));

        $onBufferDrain($this->connection);

        $this->assertNull(
            $this->connection->onBufferDrain,
            'onBufferDrain must self-remove after firing to avoid persisting on keep-alive connections',
        );
    }

    public function testOnCloseSelfRemovesAfterFiring(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/close_selfremove_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Self-remove close!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $onCloseCallback = $this->connection->onClose;
        $this->assertNotNull($onCloseCallback);
        assert(is_callable($onCloseCallback));

        $onCloseCallback($this->connection);

        $this->assertNull(
            $this->connection->onClose,
            'onClose must self-remove after firing',
        );
    }

    public function testBufferDrainRemovesOnCloseToo(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/drain_removes_close_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Drain removes close!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        // Fire buffer drain
        $onBufferDrain = $this->connection->onBufferDrain;
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        $this->assertNull($this->connection->onBufferDrain);
        $this->assertNull(
            $this->connection->onClose,
            'onClose fallback must be removed when buffer drain fires first',
        );
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testOnCloseRemovesBufferDrainToo(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/close_removes_drain_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Close removes drain!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        // Fire onClose (early disconnect)
        $onCloseCallback = $this->connection->onClose;
        assert(is_callable($onCloseCallback));
        $onCloseCallback($this->connection);

        $this->assertNull($this->connection->onClose);
        $this->assertNull(
            $this->connection->onBufferDrain,
            'onBufferDrain must be removed when onClose fires first',
        );
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testOnCloseFallbackChainsWorkerLevelOnClose(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/close_worker_base_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Close worker base!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $workerOnCloseCalled = false;
        $this->connection->context = new \stdClass();
        $this->connection->onClose = function (TcpConnection $conn) use (&$workerOnCloseCalled): void {
            $workerOnCloseCalled = true;
            $conn->context = null;
        };

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $onCloseCallback = $this->connection->onClose;
        $onCloseCallback($this->connection);

        $this->assertTrue($workerOnCloseCalled, 'early-disconnect cleanup must chain to the worker-level onClose callback');
        $this->assertNull($this->connection->context, 'worker-level onClose should still run during early disconnect cleanup');
        $this->assertNull($this->connection->onBufferDrain, 'buffer-drain callback must be removed when onClose fires first');
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testNoDoubleDeleteWhenBothDrainAndCloseFire(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $strategy = new BinaryFileResponseStrategy(logger: $logger);

        $tempFile = sys_get_temp_dir() . '/no_double_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'No double delete!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        // Fire buffer drain (deletes file)
        $onBufferDrain = $this->connection->onBufferDrain;
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        $this->assertFileDoesNotExist($tempFile);

        // onClose was already restored to null, so no second cleanup runs
        $this->assertNull($this->connection->onClose);
    }

    public function testConvertWorksForNormalBinaryFileResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_OK);

        $workermanResponse = $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNotNull($workermanResponse->file);
    }

    public function testConvertHandlesFileDeletedAfterConstruction(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/vanishing_file_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'I will disappear!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);

        unlink($tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $this->assertSame(404, $workermanResponse->getStatusCode());
    }

    public function testConvertHandlesDeleteFileAfterSendWithMissingFile(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/delete_missing_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Delete me!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        unlink($tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ], $this->connection, '1.1');

        $this->assertSame(404, $workermanResponse->getStatusCode());
    }

    public function testConvertLogsWarningWhenUnlinkFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to delete temporary file after send',
                $this->callback(fn(array $context): bool => isset($context['path']) && is_string($context['path'])),
            );

        $strategy = new BinaryFileResponseStrategy(logger: $logger);

        $dir = sys_get_temp_dir() . '/unlink_test_' . uniqid();
        mkdir($dir, 0777);
        $tempFile = $dir . '/file.txt';
        file_put_contents($tempFile, 'should fail to unlink');
        chmod($dir, 0555);

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        $onCloseCallback = $this->connection->onClose;
        assert(is_callable($onCloseCallback));

        // Suppress PHP warning from unlink() on read-only directory
        set_error_handler(static fn(): true => true);
        try {
            $onCloseCallback($this->connection);
        } finally {
            restore_error_handler();
        }

        // Restore permissions for cleanup
        chmod($dir, 0777);
        @unlink($tempFile);
        rmdir($dir);
    }

    public function testConvertDoesNotLogWhenUnlinkSucceeds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $strategy = new BinaryFileResponseStrategy(logger: $logger);

        $tempFile = sys_get_temp_dir() . '/unlink_success_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'should unlink fine');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $strategy->convert($binaryResponse, [], $this->connection, '1.1');

        // Trigger via buffer drain (primary path)
        $onBufferDrain = $this->connection->onBufferDrain;
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        $this->assertFileDoesNotExist($tempFile);
    }

    public function testDeleteFileAfterSendDoesNotCreateReferenceCycles(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/gc_probe_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'gc probe');

        $deleteFlag = new \ReflectionProperty(BinaryFileResponse::class, 'deleteFileAfterSend');
        $convert = static function () use ($strategy, $tempFile, $deleteFlag): void {
            $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
            $deleteFlag->setValue($binaryResponse, true);

            $connection = new class extends TcpConnection {
                public function __construct()
                {
                    // Bypass parent constructor — we only need the public properties.
                }
            };

            $strategy->convert($binaryResponse, [], $connection, '1.1');
        };

        try {
            gc_disable();

            // Warmup: absorb first-time class loading / allocator setup.
            $convert();
            $baselineMemory = memory_get_usage();
            $baselineRoots = gc_status()['roots'];

            for ($i = 0; $i < 3000; $i++) {
                $convert();
            }

            // With no cycles, reference counting alone reclaims everything:
            // the GC root buffer must stay flat and memory must not grow
            // linearly (~2.4 KB per download in the old by-ref-capture code).
            $this->assertSame($baselineRoots, gc_status()['roots'], 'no new GC roots may accumulate per download');
            $this->assertLessThan($baselineMemory + 131072, memory_get_usage(), 'memory must not grow linearly per download');
        } finally {
            gc_enable();
            gc_collect_cycles();
            @unlink($tempFile);
        }
    }

    public function testMultiplePendingDownloadsDoNotNestCallbacks(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFiles = [];
        try {
            $firstDrain = null;
            $firstClose = null;
            for ($i = 0; $i < 5; $i++) {
                $tempFile = sys_get_temp_dir() . '/pending_batch_' . uniqid() . '.txt';
                file_put_contents($tempFile, 'pending ' . $i);
                $tempFiles[] = $tempFile;

                $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
                $reflection = new \ReflectionClass($binaryResponse);
                $property = $reflection->getProperty('deleteFileAfterSend');
                $property->setValue($binaryResponse, true);

                $strategy->convert($binaryResponse, [], $this->connection, '1.1');

                if ($i === 0) {
                    $firstDrain = $this->connection->onBufferDrain;
                    $firstClose = $this->connection->onClose;
                } else {
                    // A second pending download must not wrap the handlers
                    // again — one shared pair per connection, not a chain of
                    // depth K.
                    $this->assertSame($firstDrain, $this->connection->onBufferDrain);
                    $this->assertSame($firstClose, $this->connection->onClose);
                }
            }

            $this->assertNotNull($firstDrain);
            $this->assertNotNull($firstClose);
            assert(is_callable($firstDrain));
            $firstDrain($this->connection);

            // One drain deleted every pending temp file exactly once.
            foreach ($tempFiles as $tempFile) {
                $this->assertFileDoesNotExist($tempFile);
            }
            $this->assertNull($this->connection->onBufferDrain, 'handlers must self-remove after the batch drain');
            $this->assertNull($this->connection->onClose, 'onClose fallback must be removed after the batch drain');
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_file($tempFile)) {
                    unlink($tempFile);
                }
            }
        }
    }

    public function testStaleHandlerFromPreviousDownloadIsInert(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $firstFile = sys_get_temp_dir() . '/stale_first_' . uniqid() . '.txt';
        $secondFile = sys_get_temp_dir() . '/stale_second_' . uniqid() . '.txt';
        file_put_contents($firstFile, 'first');
        file_put_contents($secondFile, 'second');

        $reflection = new \ReflectionClass(BinaryFileResponse::class);
        $deleteFlag = $reflection->getProperty('deleteFileAfterSend');

        $responses = [new BinaryFileResponse($firstFile, Response::HTTP_OK), new BinaryFileResponse($secondFile, Response::HTTP_OK)];
        foreach ($responses as $response) {
            $deleteFlag->setValue($response, true);
        }

        // First download drains and self-removes.
        $strategy->convert($responses[0], [], $this->connection, '1.1');
        $staleDrain = $this->connection->onBufferDrain;
        assert(is_callable($staleDrain));
        $staleDrain($this->connection);
        $this->assertFileDoesNotExist($firstFile);

        // Second download on the same keep-alive connection installs a fresh pair.
        $strategy->convert($responses[1], [], $this->connection, '1.1');
        $currentDrain = $this->connection->onBufferDrain;
        $this->assertNotNull($currentDrain);

        // Re-invoking the stale (already-fired) handler must be a no-op: it
        // must not delete the newer download's file nor clobber its handler.
        $staleDrain($this->connection);
        $this->assertSame($currentDrain, $this->connection->onBufferDrain, 'stale handler must not clobber the active one');
        $this->assertFileExists($secondFile, 'stale handler must not delete a newer download\'s pending file');

        assert(is_callable($currentDrain));
        $currentDrain($this->connection);
        $this->assertFileDoesNotExist($secondFile);
    }

    public function testCleanupStateDetachesFromContextAfterFiring(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/detach_state_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'detach me');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $this->assertNull($this->connection->context);
        $strategy->convert($binaryResponse, [], $this->connection, '1.1');
        $this->assertInstanceOf(\stdClass::class, $this->connection->context);
        $this->assertTrue(
            isset($this->connection->context->pendingCleanup),
            'cleanup state must be attached while a download is pending',
        );

        $onBufferDrain = $this->connection->onBufferDrain;
        assert(is_callable($onBufferDrain));
        $onBufferDrain($this->connection);

        $this->assertFalse(
            isset($this->connection->context->pendingCleanup),
            'spent cleanup state must be detached from keep-alive connections',
        );
        $this->assertFileDoesNotExist($tempFile);
    }

    public function testCloseAfterMultiplePendingDownloadsDeletesAllFiles(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFiles = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $tempFile = sys_get_temp_dir() . '/pending_close_' . uniqid() . '.txt';
                file_put_contents($tempFile, 'pending close ' . $i);
                $tempFiles[] = $tempFile;

                $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
                $reflection = new \ReflectionClass($binaryResponse);
                $property = $reflection->getProperty('deleteFileAfterSend');
                $property->setValue($binaryResponse, true);

                $strategy->convert($binaryResponse, [], $this->connection, '1.1');
            }

            // Early disconnect before any drain: the close fallback must
            // delete every pending file exactly once.
            $onClose = $this->connection->onClose;
            $this->assertNotNull($onClose);
            assert(is_callable($onClose));
            $onClose($this->connection);

            foreach ($tempFiles as $tempFile) {
                $this->assertFileDoesNotExist($tempFile);
            }
            $this->assertNull($this->connection->onClose, 'onClose must self-remove after firing');
            $this->assertNull($this->connection->onBufferDrain, 'buffer-drain callback must be removed when onClose fires first');
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (is_file($tempFile)) {
                    unlink($tempFile);
                }
            }
        }
    }

    /**
     * A HEAD request must not stream the file body (RFC 9110 §9.3.2). The
     * strategy emits a bodyless HeadResponse carrying the file size as
     * Content-Length and never calls withFile() (issue #683).
     */
    public function testHeadRequestEmitsBodylessResponseWithFileSizeContentLength(): void
    {
        $strategy = new BinaryFileResponseStrategy();
        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $fileSize = (int) filesize($this->testFile);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
            'Content-Length' => (string) $fileSize,
        ], $this->connection, '1.1', 'HEAD');

        $this->assertInstanceOf(HeadResponse::class, $workermanResponse);
        $this->assertSame(200, $workermanResponse->getStatusCode());
        $this->assertNull($workermanResponse->file, 'HEAD must not attach a file (no withFile())');

        $wire = (string) $workermanResponse;
        $this->assertSame(1, substr_count($wire, 'Content-Length:'), 'HEAD must emit exactly one Content-Length');
        $this->assertStringContainsString('Content-Length: ' . $fileSize, $wire, 'HEAD Content-Length must be the file size');
        $this->assertStringContainsString('Accept-Ranges: bytes', $wire, 'HEAD must carry the same Accept-Ranges as the GET file path (RFC 9110 §9.3.2)');
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? '', 'HEAD must not emit a body');
    }

    /**
     * A HEAD request for a temp-file BinaryFileResponse must not read the
     * temp file into memory (the GET path buffers it via withBody()) — only
     * the size is needed for the bodyless HeadResponse (issue #683).
     */
    public function testHeadRequestWithTempFileDoesNotReadBodyAndEmitsTempSize(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite('Temp file content');

        $binaryResponse = new BinaryFileResponse($this->testFile);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($binaryResponse, $tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Length' => '16',
        ], $this->connection, '1.1', 'HEAD');

        $this->assertInstanceOf(HeadResponse::class, $workermanResponse);
        $this->assertNull($workermanResponse->file);
        $this->assertSame('', $workermanResponse->rawBody(), 'HEAD must not read the temp file into memory');

        $wire = (string) $workermanResponse;
        $this->assertStringContainsString('Content-Length: 16', $wire);
        $this->assertStringNotContainsString('Accept-Ranges', $wire, 'Temp files get no Accept-Ranges on the GET path either (withBody(), not withFile())');
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? '', 'HEAD must not emit a body');
    }

    /**
     * A HEAD request on a deleteFileAfterSend file must delete the file
     * immediately: the GET path's onBufferDrain cleanup would not fire for a
     * bodyless response, so a deferred delete would leak on keep-alive
     * connections. No async cleanup callbacks are installed (issue #683).
     */
    public function testHeadRequestWithDeleteFileAfterSendDeletesImmediately(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/head_delete_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'Delete me on HEAD!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('deleteFileAfterSend');
        $property->setValue($binaryResponse, true);

        $this->assertFileExists($tempFile);

        $fileSize = (int) filesize($tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Length' => (string) $fileSize,
        ], $this->connection, '1.1', 'HEAD');

        $this->assertInstanceOf(HeadResponse::class, $workermanResponse);
        $this->assertFileDoesNotExist($tempFile, 'HEAD + deleteFileAfterSend must delete the file immediately');
        $this->assertNull($this->connection->onBufferDrain, 'HEAD must not install the async buffer-drain cleanup');
        $this->assertNull($this->connection->onClose, 'HEAD must not install the async onClose cleanup');
    }

    /**
     * A HEAD request for a file that vanished before convert mirrors the GET
     * path's 404 (Workerman's withFile() turns an absent file into a 404):
     * a 404 HeadResponse with no body (issue #683).
     */
    public function testHeadRequestWithMissingFileReturns404HeadResponse(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $tempFile = sys_get_temp_dir() . '/head_missing_' . uniqid() . '.txt';
        file_put_contents($tempFile, 'I will vanish!');

        $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
        unlink($tempFile);

        $workermanResponse = $strategy->convert($binaryResponse, [], $this->connection, '1.1', 'HEAD');

        $this->assertInstanceOf(HeadResponse::class, $workermanResponse);
        $this->assertSame(404, $workermanResponse->getStatusCode());
        $this->assertNull($workermanResponse->file);

        $wire = (string) $workermanResponse;
        $this->assertSame(1, substr_count($wire, 'Content-Length:'));
        $this->assertStringContainsString('Content-Length: 0', $wire);
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? '', 'HEAD 404 must not emit a body');
    }

    /**
     * When the preserved Content-Length header is absent (prepare() did not
     * set it — e.g. the file vanished and was restored, or a caller bypassed
     * prepare()), the HEAD path falls back to the actual file size so the
     * emitted length matches what the GET path would frame (issue #683).
     */
    public function testHeadRequestFallsBackToFileSizeWhenContentLengthHeaderAbsent(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $binaryResponse = new BinaryFileResponse($this->testFile, Response::HTTP_OK, [
            'Content-Type' => 'text/plain',
        ]);

        $fileSize = (int) filesize($this->testFile);

        // No Content-Length in $headers (simulates prepare() not setting it).
        $workermanResponse = $strategy->convert($binaryResponse, [
            'Content-Type' => ['text/plain'],
        ], $this->connection, '1.1', 'HEAD');

        $this->assertInstanceOf(HeadResponse::class, $workermanResponse);
        $wire = (string) $workermanResponse;
        $this->assertStringContainsString('Content-Length: ' . $fileSize, $wire, 'Fallback must compute the file size');
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? '', 'HEAD must not emit a body');
    }

    /**
     * When the preserved Content-Length header is absent for a temp-file
     * response, the HEAD path falls back to the temp file's fstat size
     * (issue #683).
     */
    public function testHeadRequestWithTempFileFallsBackToFstatWhenContentLengthAbsent(): void
    {
        $strategy = new BinaryFileResponseStrategy();

        $content = 'Temp fallback content';
        $tempFile = new \SplTempFileObject();
        $tempFile->fwrite($content);

        $binaryResponse = new BinaryFileResponse($this->testFile);
        $reflection = new \ReflectionClass($binaryResponse);
        $property = $reflection->getProperty('tempFileObject');
        $property->setValue($binaryResponse, $tempFile);

        // No Content-Length in $headers — fallback reads the temp file fstat size.
        $workermanResponse = $strategy->convert($binaryResponse, [], $this->connection, '1.1', 'HEAD');

        $this->assertInstanceOf(HeadResponse::class, $workermanResponse);
        $wire = (string) $workermanResponse;
        $this->assertStringContainsString('Content-Length: ' . strlen($content), $wire, 'Fallback must read the temp file fstat size');
        $this->assertSame('', explode("\r\n\r\n", $wire, 2)[1] ?? '', 'HEAD must not emit a body');
    }

    /**
     * A HEAD request on a deleteFileAfterSend file whose unlink fails must log
     * a warning, mirroring the GET path's scheduleFileCleanup behaviour
     * (issue #683).
     */
    public function testHeadRequestDeleteFileAfterSendLogsWarningWhenUnlinkFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to delete temporary file after send',
                $this->callback(fn(array $context): bool => isset($context['path']) && is_string($context['path'])),
            );

        $strategy = new BinaryFileResponseStrategy(logger: $logger);

        $dir = sys_get_temp_dir() . '/head_unlink_test_' . uniqid();
        mkdir($dir, 0777);
        $tempFile = $dir . '/file.txt';
        file_put_contents($tempFile, 'should fail to unlink on HEAD');
        chmod($dir, 0555);

        try {
            $binaryResponse = new BinaryFileResponse($tempFile, Response::HTTP_OK);
            $reflection = new \ReflectionClass($binaryResponse);
            $property = $reflection->getProperty('deleteFileAfterSend');
            $property->setValue($binaryResponse, true);

            // Suppress PHP warning from unlink() on the read-only directory.
            set_error_handler(static fn(): true => true);
            try {
                $strategy->convert($binaryResponse, [
                    'Content-Length' => (string) filesize($tempFile),
                ], $this->connection, '1.1', 'HEAD');
            } finally {
                restore_error_handler();
            }
        } finally {
            chmod($dir, 0777);
            @unlink($tempFile);
            rmdir($dir);
        }
    }
}
