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

        // A response Symfony prepared as bodyless must not carry the file.
        // prepare() sets maxlen = 0 in three cases: a HEAD request (handled
        // above), an informational or empty status (1xx, 204, 304 — RFC 9110
        // §15.4.5 and §15.3.5 allow no body there) and the X-Sendfile /
        // X-Accel-Redirect hand-off, where the front-end server sends the file.
        // Workerman reads a length of 0 as "the whole file", so withFile()
        // shipped the entire file with a 304 or 204 status (issue #948);
        // Symfony's own sendContent() returns before it touches the file when
        // maxlen is 0.
        if ($this->reflector->getMaxlen($response) === 0) {
            return $this->convertBodyless($response, $headers);
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
        // Symfony uses maxlen -1 for "to the end of the file". Workerman has no such
        // value: it uses 0 and treats any other non-zero length as a range (issue #902).
        // maxlen 0 never reaches here — the bodyless path above returns first, because
        // Workerman would read it as "the whole file" (issue #948).
        $maxlen = max(0, $this->reflector->getMaxlen($response) ?? 0);
        $deleteFileAfterSend = $this->reflector->getDeleteFileAfterSend($response);

        if ($deleteFileAfterSend === true) {
            $filePath = $file->getPathname();

            $workermanResponse->withFile($filePath, $offset ?? 0, $maxlen);
            $this->scheduleFileCleanup($filePath, $connection);

            return $workermanResponse;
        }

        $workermanResponse->withFile(
            $file->getPathname(),
            $offset ?? 0,
            $maxlen,
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

        if ($this->reflector->getMaxlen($response) === 0) {
            $statusCode = $response->getStatusCode();
            unset($headers['Content-Length']);

            if ($statusCode >= 200 && $statusCode < 300 && !$tempFileObject instanceof \SplTempFileObject) {
                $file = $response->getFile();
                // Mirror the unprepared path below: Workerman's withFile()
                // turns an absent file into a 404, so a prepared HEAD 200 for
                // a file that vanished after prepare() must 404 as well
                // (issue #948 review R2-02).
                if (!is_file($file->getPathname())) {
                    return new HeadResponse(404, $headers, 0);
                }
            }

            $contentLength = $this->resolvePreparedContentLength($response);

            if (($statusCode === 200 || $statusCode === 206) && !$tempFileObject instanceof \SplTempFileObject) {
                $headers['Accept-Ranges'] = 'bytes';
            }

            $this->deleteFileAfterBodylessSend($response, $tempFileObject);

            return new HeadResponse($response->getStatusCode(), $headers, $contentLength);
        }

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

        // deleteFileAfterSend on a bodyless response: the file is never read
        // or buffered, so the onBufferDrain cleanup used by the GET path
        // would not fire reliably. Delete synchronously, matching Symfony's
        // BinaryFileResponse (which unlinks in sendContent()'s finally even
        // when maxlen is 0, as it is for every bodyless response) and avoiding
        // a leak on keep-alive connections. Temp files are in-memory and are
        // never unlinked (mirrors Symfony's `null === $tempFileObject` guard).
        $this->deleteFileAfterBodylessSend($response, $tempFileObject);

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
     * Build a bodyless response for a BinaryFileResponse that Symfony prepared
     * as bodyless (an informational or empty status, or the X-Sendfile /
     * X-Accel-Redirect hand-off).
     *
     * This is the same shape the HEAD path and DefaultResponseStrategy use for
     * a response with no body: the headers Symfony prepared, no file attached
     * and no Content-Length of our own. The length is the one prepare() left in
     * the response — it keeps the file size for the sendfile hand-off, where the
     * front-end server sends the body and a reply without a length would be
     * close-delimited, and it removes it for 1xx/204/304, where the transport
     * computes 0 exactly as it does for every other bodyless response this
     * bundle emits.
     *
     * The file is never opened. withFile() exists to make the transport read
     * the file, and everything it derives (Content-Length, Accept-Ranges, the
     * implicit 404 for a vanished file) comes from a body this response must not
     * send. The prepared status is kept: a 304 has to repeat the cached
     * response's header fields, not turn into a fresh error (issue #948).
     *
     * @param array<string, string|list<string|null>> $headers
     */
    private function convertBodyless(BinaryFileResponse $response, array $headers): HeadResponse
    {
        $contentLength = $this->resolvePreparedContentLength($response);
        unset($headers['Content-Length']);

        $statusCode = $response->getStatusCode();
        if (($statusCode === 200 || $statusCode === 206) && !$this->reflector->getTempFileObject($response) instanceof \SplTempFileObject) {
            $headers['Accept-Ranges'] = 'bytes';
        }

        $this->deleteFileAfterBodylessSend($response, $this->reflector->getTempFileObject($response));

        return new HeadResponse($response->getStatusCode(), $headers, $contentLength);
    }

    /**
     * The Content-Length a bodyless response carries: the value Symfony's
     * prepare() left in the response headers, or 0 when prepare() removed it
     * (the informational and empty statuses).
     *
     * ResponseConverter strips Content-Length for every request except HEAD
     * (issue #579), so the prepared value has to be read from the response
     * itself and not from the normalised header array.
     */
    private function resolvePreparedContentLength(BinaryFileResponse $response): int
    {
        $contentLength = $response->headers->get('Content-Length');

        return is_string($contentLength) && ctype_digit($contentLength) ? (int) $contentLength : 0;
    }

    /**
     * Delete a deleteFileAfterSend file for a bodyless response (a HEAD
     * request, or a response Symfony prepared as bodyless).
     */
    private function deleteFileAfterBodylessSend(BinaryFileResponse $response, ?\SplTempFileObject $tempFileObject): void
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
     * do not persist across keep-alive requests. HttpRequestHandler normally
     * deletes the file earlier, right after the send (FileCleanupState::release(),
     * issue #906); these callbacks are the fallback.
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
                $state->deletePending($logger);
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

            // Weak references: the state must not hold the handlers, or the
            // state and the handlers would form a cycle (issue #573).
            // FileCleanupState::release() uses them to detach our handlers.
            $state->drainHandler = \WeakReference::create($connection->onBufferDrain);
            $state->closeHandler = \WeakReference::create($connection->onClose);
        }

        $state->pending[] = $filePath;
    }
}
