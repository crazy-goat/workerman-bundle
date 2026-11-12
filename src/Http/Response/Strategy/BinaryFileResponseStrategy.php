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
 * @see BinaryFileResponse
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
        if (strcasecmp($requestMethod, 'HEAD') === 0) {
            return $this->convertHead($response, $headers);
        }

        if ($this->reflector->getMaxlen($response) === 0) {
            return $this->convertBodyless($response, $headers);
        }

        unset($headers['Content-Length']);

        $workermanResponse = new WorkermanResponse(
            $response->getStatusCode(),
            $headers,
        );

        $tempFileObject = $this->reflector->getTempFileObject($response);
        if ($tempFileObject instanceof \SplTempFileObject) {
            // A Range request sets offset/maxlen on the response via prepare()
            // (issue #1115): Symfony's own sendContent() seeks to offset and
            // reads only maxlen bytes. The temp-file branch used to ignore both
            // and send the whole file with a 206 status and a Content-Range for
            // a sub-range, so honour them here exactly like the real-file
            // branch does via withFile(). maxlen -1 means "to the end of the
            // file" (Symfony's default); maxlen 0 never reaches here — the
            // bodyless path above returns first (issue #948).
            $offset = max(0, $this->reflector->getOffset($response) ?? 0);
            $maxlen = $this->reflector->getMaxlen($response);
            $remaining = ($maxlen !== null && $maxlen > 0) ? $maxlen : null;

            $tempFileObject->rewind();
            if ($offset > 0) {
                $tempFileObject->fseek($offset);
            }

            $content = '';
            while (!$tempFileObject->eof() && ($remaining === null || $remaining > 0)) {
                $chunk = $tempFileObject->fread($remaining === null ? 8192 : min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $content .= $chunk;
                if ($remaining !== null) {
                    $remaining -= \strlen($chunk);
                }
            }
            $workermanResponse->withBody($content);

            return $workermanResponse;
        }

        $file = $response->getFile();
        $offset = $this->reflector->getOffset($response);
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
            if (!is_file($file->getPathname())) {
                unset($headers['Content-Length']);

                return new HeadResponse(404, $headers, 0);
            }
        }

        $contentLength = $this->resolveHeadContentLength($response, $headers, $tempFileObject);
        unset($headers['Content-Length']);

        if (!$tempFileObject instanceof \SplTempFileObject) {
            $headers['Accept-Ranges'] = 'bytes';
        }

        $this->deleteFileAfterBodylessSend($response, $tempFileObject);

        return new HeadResponse($response->getStatusCode(), $headers, $contentLength);
    }

    /**
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

    private function resolvePreparedContentLength(BinaryFileResponse $response): int
    {
        $contentLength = $response->headers->get('Content-Length');

        return is_string($contentLength) && ctype_digit($contentLength) ? (int) $contentLength : 0;
    }

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

            $connection->onBufferDrain = static function (TcpConnection $conn) use ($state, $cleanup): void {
                if (!$state->installed) {
                    return;
                }
                $state->installed = false;
                $conn->onBufferDrain = $state->previousOnBufferDrain;
                $conn->onClose = $state->previousOnClose;

                $cleanup();

                if (is_callable($state->previousOnBufferDrain)) {
                    ($state->previousOnBufferDrain)($conn);
                }
                $state->pending = [];
                if ($conn->context instanceof \stdClass) {
                    unset($conn->context->pendingCleanup);
                }
            };

            $connection->onClose = static function (TcpConnection $conn) use ($state, $cleanup): void {
                if (!$state->installed) {
                    return;
                }
                $state->installed = false;
                $conn->onClose = $state->previousOnClose;
                $conn->onBufferDrain = $state->previousOnBufferDrain;

                $cleanup();

                if (is_callable($state->previousOnClose)) {
                    ($state->previousOnClose)($conn);
                }
                $state->pending = [];
                if ($conn->context instanceof \stdClass) {
                    unset($conn->context->pendingCleanup);
                }
            };

            $state->drainHandler = \WeakReference::create($connection->onBufferDrain);
            $state->closeHandler = \WeakReference::create($connection->onClose);
        }

        $state->pending[] = $filePath;
    }
}
