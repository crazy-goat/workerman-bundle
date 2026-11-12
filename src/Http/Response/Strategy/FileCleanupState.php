<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

use Psr\Log\LoggerInterface;
use Workerman\Connection\TcpConnection;

final class FileCleanupState
{
    /**
     * @param list<string> $pending
     * @param ?\WeakReference<\Closure> $drainHandler
     * @param ?\WeakReference<\Closure> $closeHandler
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
