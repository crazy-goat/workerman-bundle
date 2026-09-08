<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle;

/**
 * A single-read snapshot of a Linux process's identity fields.
 *
 * Read once from `/proc/{pid}/stat` (state character + start time) and
 * `/proc/{pid}/status` (UID) so that {@see ProcessInspector::matchesFingerprint()}
 * can reason over one snapshot instead of re-reading /proc up to five times
 * per verification.
 *
 * Fail-closed semantics: a field that could not be read is `null` (UID) or
 * `0` (start time) / `null` (state); the caller treats every unreadable
 * field as "verification must refuse".
 */
final readonly class ProcessSnapshot
{
    public function __construct(
        public ?string $state,
        public int $startTime,
        public ?int $uid,
    ) {
    }

    /**
     * Whether the process is alive based on the snapshot's state field.
     *
     * A state of `'Z'` means the process is a zombie (not alive). A null
     * state (unreadable /proc) is treated as not alive so the caller fails
     * closed.
     */
    public function isAlive(): bool
    {
        return $this->state !== null && $this->state !== 'Z';
    }
}
