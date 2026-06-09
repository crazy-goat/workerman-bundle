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

    /**
     * Whether this strategy depends on memory_get_peak_usage() being reset.
     *
     * Called once at HttpRequestHandler construction time. When every strategy
     * returns false, HttpRequestHandler skips the memory_reset_peak_usage()
     * call entirely, saving a syscall on the hot path for every request.
     *
     * Strategies that read memory_get_peak_usage() in shouldReboot() (e.g.,
     * MemoryRebootStrategy) should return true here. All other strategies
     * should return false.
     *
     * @return bool true if memory_reset_peak_usage() must be called before
     *              every request for this strategy to work correctly.
     */
    public function needsPeakMemory(): bool;
}
