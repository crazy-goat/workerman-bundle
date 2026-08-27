<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Reboot\Strategy;

/**
 * Determines whether the current worker process should be gracefully reloaded.
 *
 * After each HTTP request is fully handled (including kernel termination and
 * response send), HttpRequestHandler consults the reboot strategy. If
 * shouldReboot() returns true, the worker sends SIGUSR1 to itself via
 * Utils::reload(), triggering a graceful restart. The next request is picked
 * up by a fresh worker process.
 *
 * Implementations can track any state across requests: memory usage, job count,
 * elapsed time, exception frequency, or any custom metric. The strategy is
 * instantiated once per worker and reused across all requests it handles.
 *
 * Lifecycle: per-worker (singleton within the worker process). Methods are
 * called synchronously after each request response is sent.
 *
 * @see MemoryRebootStrategy Reboots when memory_get_usage() exceeds a limit.
 * @see MaxJobsRebootStrategy Reboots after a configurable number of requests.
 * @see ExceptionRebootStrategy Reboots after non-allowed kernel exceptions.
 * @see AlwaysRebootStrategy Reboots after every request (for debugging).
 * @see StackRebootStrategy Composes multiple strategies via OR logic.
 */
interface RebootStrategyInterface
{
    /**
     * Whether the current worker should be reloaded after this request.
     *
     * Called synchronously after the response is sent and kernel termination
     * has completed. The implementation may use any internal state accumulated
     * during the request lifecycle.
     *
     * Return true to trigger a graceful worker restart via SIGUSR1.
     * The worker finishes handling its current request before reloading.
     *
     * @return bool true if the worker should be gracefully restarted.
     */
    public function shouldReboot(): bool;
}
