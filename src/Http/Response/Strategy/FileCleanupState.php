<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http\Response\Strategy;

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
     */
    public function __construct(
        public array $pending = [],
        public mixed $previousOnClose = null,
        public mixed $previousOnBufferDrain = null,
        public bool $installed = false,
    ) {
    }
}
