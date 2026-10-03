<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use Psr\Log\LoggerInterface;
use Workerman\Connection\TcpConnection;

/**
 * Per-connection state shared by the onBufferDrain and onClose handlers
 * installed by BinaryFileResponseStrategy::scheduleFileCleanup().
 *
 * Both handlers capture this object instead of referencing each other, so
 * the closure pair forms no reference cycle and reference counting alone
 * frees it after the connection is gone (issue #573).
 */
final class FileCleanupState
{
    /**
     * @param list<string> $pending               temp-file paths awaiting deletion
     * @param mixed        $previousOnClose       connection onClose before our handler was installed
     * @param mixed        $previousOnBufferDrain connection onBufferDrain before our handler was installed
     * @param bool         $installed             whether our handlers are still attached to the connection
     * @param ?\WeakReference<\Closure> $drainHandler our onBufferDrain handler (weak, so there is no reference cycle)
     * @param ?\WeakReference<\Closure> $closeHandler our onClose handler (weak, so there is no reference cycle)
     */
    public function __construct(
        public array $pending = [],
        public mixed $previousOnClose = null,
        public mixed $previousOnBufferDrain = null,
        public bool $installed = false,
        public ?\WeakReference $drainHandler = null,
        public ?\WeakReference $closeHandler = null,
    ) {
    }

    /**
     * Delete the pending files.
     */
    public function deletePending(LoggerInterface $logger): void
    {
        foreach ($this->pending as $pendingPath) {
            if (is_file($pendingPath) && !unlink($pendingPath)) {
                $logger->warning('Failed to delete temporary file after send', [
                    'path' => $pendingPath,
                    'error' => error_get_last()['message'] ?? 'Unknown error',
                ]);
            }
        }
    }

    /**
     * Delete the pending files now and detach from the connection.
     *
     * HttpRequestHandler calls this right after the response was encoded and
     * sent. At that point Workerman has read the file (under 2 MB) or has
     * opened it and streams from the open handle (2 MB or more), so the
     * file can be deleted without waiting for the buffer to drain or for the
     * connection to close (issue #906).
     *
     * A handler is only restored when it is still ours: for a big file
     * Workerman has already replaced onBufferDrain with its own handler.
     */
    public function release(TcpConnection $connection, LoggerInterface $logger): void
    {
        if (!$this->installed) {
            return;
        }
        $this->installed = false;

        if ($this->drainHandler?->get() !== null && $connection->onBufferDrain === $this->drainHandler->get()) {
            $connection->onBufferDrain = $this->previousOnBufferDrain;
        }
        if ($this->closeHandler?->get() !== null && $connection->onClose === $this->closeHandler->get()) {
            $connection->onClose = $this->previousOnClose;
        }

        $this->deletePending($logger);
        $this->pending = [];

        if ($connection->context instanceof \stdClass) {
            unset($connection->context->pendingCleanup);
        }
    }
}
