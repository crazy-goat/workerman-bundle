<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Util;

/**
 * Terminates the current process in a way that cannot hang on extension
 * shutdown handlers.
 *
 * The grpc extension (loaded globally on some hosts, e.g. Homebrew PHP on
 * macOS) installs a shutdown handler whose cleanup deadlocks in forked
 * children: `grpc_shutdown()` waits on a condition variable that never
 * fires once the extension's background threads were lost to a fork. The
 * bundle forks children for supervised processes and scheduler tasks, and
 * hosts with grpc must run with `GRPC_ENABLE_FORK_SUPPORT=1` (see
 * README), so on such machines a plain `exit()` in a worker or task child
 * never returns — the child stays alive forever and the supervisor can
 * never respawn it.
 *
 * When the grpc extension is loaded, the process is instead killed with
 * SIGKILL, which bypasses all PHP module shutdown handlers. Trade-off:
 * destructors and `register_shutdown_function()` callbacks are skipped —
 * acceptable for supervised one-shot children that already finished their
 * work (any logging or cleanup directly preceding the call is unaffected,
 * since `Worker::log()` writes synchronously).
 *
 * @see docs/troubleshooting.md "gRPC Extension and Fork Safety"
 */
final class ProcessTerminator
{
    /**
     * @param int      $code     exit code for the normal (non-grpc) path
     * @param bool|null $hardExit force the decision for tests:
     *                           true = SIGKILL, false = exit(), null = auto
     *                           (SIGKILL when the grpc extension is loaded)
     */
    public static function terminate(int $code, ?bool $hardExit = null): never
    {
        if ($hardExit ?? \extension_loaded('grpc')) {
            // SIGKILL cannot be caught, so PHP module shutdown (and the
            // grpc deadlock) never runs.
            \posix_kill(\posix_getpid(), \SIGKILL);

            // Unreachable when SIGKILL was delivered; safety net only.
            exit($code);
        }

        exit($code);
    }
}
