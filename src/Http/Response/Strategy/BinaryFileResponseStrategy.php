<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use CrazyGoat\WorkermanBundle\Http\Response\RequestMethodAwareResponseConverterStrategyInterface;
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
final readonly class BinaryFileResponseStrategy implements RequestMethodAwareResponseConverterStrategyInterface
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

    public function convert(SymfonyResponse $response, array $headers, TcpConnection $connection, string $protocolVersion, string $requestMethod = 'GET', bool $shouldClose = false): WorkermanResponse
    {
        /** @var BinaryFileResponse $response */
        // A HEAD request must not stream the file body (RFC 9110 §9.3.2).
        // BinaryFileResponse::setContent(null) — which Symfony's prepare() calls
        // for HEAD — is a no-op, so unlike StreamedResponse the file is still
        // attached and withFile() would send the bytes via Http::encode().
        // Emit a bodyless HeadResponse carrying the file size as Content-Length
        // instead (issue #683).
        if (strcasecmp($requestMethod, 'HEAD') === 0) {
            return $this->convertHead($response, $headers);
        }

        // $protocolVersion and $shouldClose are intentionally unused: this
        // strategy returns a regular WorkermanResponse (with or without
        // withFile()); the status line and Connection header are handled by
        // Workerman and by HttpRequestHandler::sendResponse(), which stamps
        // Connection: close centrally for non-directly-sent responses
        // (issue #621).
        //
        // ResponseConverter preserves the application-provided Content-Length
        // for HEAD requests (issue #643), but the file path must never carry
        // it: Http::encode() merges its own file Content-Length via
        // array_merge_recursive, which would emit a duplicate header.
        unset($headers['Content-Length']);

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
     * Build a bodyless HEAD response for a BinaryFileResponse.
     *
     * Symfony's prepare() sets Content-Length to the file size for HEAD (Range
     * handling is GET-only, so HEAD always carries the full size) and
     * ResponseConverter preserves it for HEAD (issue #643). The response carries
     * that length over an empty body and never calls withFile(), which would
     * stream the bytes via Http::encode() (RFC 9110 §9.3.2 — issue #683).
     *
     * @param array<string, string|list<string|null>> $headers
     */
    private function convertHead(BinaryFileResponse $response, array $headers): HeadResponse
    {
        $tempFileObject = $this->reflector->getTempFileObject($response);

        if (!$tempFileObject instanceof \SplTempFileObject) {
            $file = $response->getFile();
            // Mirror the GET path, where Workerman's withFile() turns an absent
            // file into a 404. HEAD carries no body, so emit a 404 HeadResponse
            // with Content-Length: 0.
            if (!is_file($file->getPathname())) {
                unset($headers['Content-Length']);

                return new HeadResponse(404, $headers, 0);
            }
        }

        $contentLength = $this->resolveHeadContentLength($response, $headers, $tempFileObject);
        unset($headers['Content-Length']);

        if (!$tempFileObject instanceof \SplTempFileObject) {
            // Mirror the GET path, where Workerman's encode() emits
            // "Accept-Ranges: bytes" for file responses — RFC 9110 §9.3.2
            // requires HEAD to carry the same header fields as the GET would.
            // Temp files are served via withBody() and get no Accept-Ranges
            // on the GET path either, so only real files set it here.
            $headers['Accept-Ranges'] = 'bytes';
        }

        // deleteFileAfterSend on HEAD: the file body is never sent, so the
        // onBufferDrain cleanup used by the GET path would not fire reliably.
        // Delete synchronously, matching Symfony's BinaryFileResponse (which
        // unlinks in sendContent()'s finally even for HEAD) and avoiding a
        // leak on keep-alive connections. Temp files are in-memory and are
        // never unlinked (mirrors Symfony's `null === $tempFileObject` guard).
        $this->deleteFileAfterHead($response, $tempFileObject);

        return new HeadResponse($response->getStatusCode(), $headers, $contentLength);
    }

    /**
     * Resolve the Content-Length a HEAD response should carry: the value
     * Symfony's prepare() set (the full file size for HEAD), falling back to
     * the actual file/temp size when prepare() left no header (e.g. the file
     * vanished between construction and prepare).
     *
     * @param array<string, string|list<string|null>> $headers
     */
    private function resolveHeadContentLength(BinaryFileResponse $response, array $headers, ?\SplTempFileObject $tempFileObject): int
    {
        if (isset($headers['Content-Length']) && is_string($headers['Content-Length']) && ctype_digit($headers['Content-Length'])) {
            return (int) $headers['Content-Length'];
        }

        if ($tempFileObject instanceof \SplTempFileObject) {
            $stat = $tempFileObject->fstat();

            return is_array($stat) && isset($stat['size']) ? $stat['size'] : 0;
        }

        $size = $response->getFile()->getSize();

        return is_int($size) ? $size : 0;
    }

    /**
     * Delete a deleteFileAfterSend file immediately for a HEAD request.
     */
    private function deleteFileAfterHead(BinaryFileResponse $response, ?\SplTempFileObject $tempFileObject): void
    {
        if ($tempFileObject instanceof \SplTempFileObject) {
            return;
        }

        if ($this->reflector->getDeleteFileAfterSend($response) !== true) {
            return;
        }

        $filePath = $response->getFile()->getPathname();
        if (!is_file($filePath)) {
            return;
        }

        if (!unlink($filePath)) {
            $this->logger->warning('Failed to delete temporary file after send', [
                'path' => $filePath,
                'error' => error_get_last()['message'] ?? 'Unknown error',
            ]);
        }
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
