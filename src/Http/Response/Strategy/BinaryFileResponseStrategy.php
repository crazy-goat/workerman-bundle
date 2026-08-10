<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\ResponseConverterStrategyInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Strategy for converting Symfony BinaryFileResponse to Workerman Response.
 *
 * This handles file downloads properly by using Workerman's native withFile()
 * method, which efficiently streams files without loading them into memory.
 *
 * @see BinaryFileResponse         Depends on private fields: tempFileObject, offset, maxlen, deleteFileAfterSend
 * @see BinaryFileResponseReflector
 */
final readonly class BinaryFileResponseStrategy implements ResponseConverterStrategyInterface
{
    public function __construct(
        private BinaryFileResponseReflector $reflector = new BinaryFileResponseReflector(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(SymfonyResponse $response): bool
    {
        return $response instanceof BinaryFileResponse;
    }

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion): WorkermanResponse
    {
        // $protocolVersion is intentionally unused: this strategy returns a
        // regular WorkermanResponse (with or without withFile()); the status
        // line and Connection header are handled by Workerman and by
        // HttpRequestHandler::sendResponse().
        /** @var BinaryFileResponse $response */
        $workermanResponse = new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
        );

        $tempFileObject = $this->reflector->getTempFileObject($response);
        if ($tempFileObject instanceof \SplTempFileObject) {
            $tempFileObject->rewind();
            $content = '';
            while (!$tempFileObject->eof()) {
                $content .= $tempFileObject->fread(8192);
            }
            $workermanResponse->withBody($content);

            return $workermanResponse;
        }

        $file = $response->getFile();
        $offset = $this->reflector->getOffset($response);
        $maxlen = $this->reflector->getMaxlen($response);
        $deleteFileAfterSend = $this->reflector->getDeleteFileAfterSend($response);

        if ($deleteFileAfterSend === true) {
            $filePath = $file->getPathname();

            $workermanResponse->withFile($filePath, $offset ?? 0, $maxlen ?? 0);
            $this->scheduleFileCleanup($filePath, $connection);

            return $workermanResponse;
        }

        $workermanResponse->withFile(
            $file->getPathname(),
            $offset ?? 0,
            $maxlen ?? 0,
        );

        return $workermanResponse;
    }

    /**
     * Schedule file deletion using onBufferDrain (fires when the send buffer
     * is empty — i.e. the file has been fully sent) with an onClose fallback
     * for early disconnects. Both callbacks self-remove after firing so they
     * do not persist across keep-alive requests.
     */
    private function scheduleFileCleanup(string $filePath, TcpConnection $connection): void
    {
        $state = null;
        if ($connection->context instanceof \stdClass
            && isset($connection->context->pendingCleanup)
            && $connection->context->pendingCleanup instanceof FileCleanupState
            && $connection->context->pendingCleanup->installed
        ) {
            $state = $connection->context->pendingCleanup;
        }

        if (!$state instanceof FileCleanupState) {
            $state = new FileCleanupState(
                previousOnClose: is_callable($connection->onClose) ? $connection->onClose : null,
                previousOnBufferDrain: is_callable($connection->onBufferDrain) ? $connection->onBufferDrain : null,
                installed: true,
            );
            $connection->context ??= new \stdClass();
            $connection->context->pendingCleanup = $state;

            $logger = $this->logger;
            $cleanup = static function () use ($state, $logger): void {
                foreach ($state->pending as $pendingPath) {
                    if (is_file($pendingPath) && !unlink($pendingPath)) {
                        $logger->warning('Failed to delete temporary file after send', [
                            'path' => $pendingPath,
                            'error' => error_get_last()['message'] ?? 'Unknown error',
                        ]);
                    }
                }
            };

            // Both handlers capture the shared state object instead of each
            // other (the old mutual by-reference capture created a closure
            // reference cycle on every download — issue #573).
            $connection->onBufferDrain = static function (TcpConnection $conn) use ($state, $cleanup): void {
                if (!$state->installed) {
                    return;
                }
                $state->installed = false;
                // Self-remove: this callback must not fire on subsequent
                // requests over the same keep-alive connection.
                $conn->onBufferDrain = $state->previousOnBufferDrain;
                // Restore the original onClose now that the pending files
                // are deleted.
                $conn->onClose = $state->previousOnClose;

                $cleanup();

                // Chain to any previous onBufferDrain callback.
                if (is_callable($state->previousOnBufferDrain)) {
                    ($state->previousOnBufferDrain)($conn);
                }
                $state->pending = [];
                // Detach the spent cleanup state: keep-alive connections must
                // not carry it for their lifetime.
                if ($conn->context instanceof \stdClass) {
                    unset($conn->context->pendingCleanup);
                }
            };

            $connection->onClose = static function (TcpConnection $conn) use ($state, $cleanup): void {
                if (!$state->installed) {
                    return;
                }
                $state->installed = false;
                // Self-remove: prevent double-firing if both drain and close
                // trigger.
                $conn->onClose = $state->previousOnClose;
                $conn->onBufferDrain = $state->previousOnBufferDrain;

                $cleanup();

                // Chain to any previous onClose callback.
                if (is_callable($state->previousOnClose)) {
                    ($state->previousOnClose)($conn);
                }
                $state->pending = [];
                // Detach the spent cleanup state: keep-alive connections must
                // not carry it for their lifetime.
                if ($conn->context instanceof \stdClass) {
                    unset($conn->context->pendingCleanup);
                }
            };
        }

        $state->pending[] = $filePath;
    }
}
