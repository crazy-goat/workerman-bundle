<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Exception thrown when attempting to interact with a server that is not
 * running, or whose master process identity cannot be verified.
 *
 * The no-argument constructor preserves backward compatibility with callers
 * that expect the generic "Workerman is not running." message. Named static
 * constructors build cause-specific messages so the operator can distinguish
 * "not running" from "running but unverifiable":
 *
 *  - {@see self::noPidFile()} — no pid file found (or empty/unreadable).
 *  - {@see self::processDead()} — pid file points to a PID that is not alive.
 *  - {@see self::unverifiable()} — the PID is alive but its identity cannot
 *    be confirmed: the fingerprint sidecar is missing (pre-0.25.0 start or
 *    daemon-start window) or the fingerprint does not match (possible PID
 *    reuse or a process owned by a different user).
 */
final class ServerNotRunningException extends ServerException
{
    public function __construct(string $message = 'Workerman is not running.')
    {
        parent::__construct($message);
    }

    /**
     * No pid file found (or empty/unreadable): the server was never started
     * or was stopped cleanly.
     */
    public static function noPidFile(): self
    {
        return new self('Workerman is not running (no pid file found).');
    }

    /**
     * The pid file points to a PID that is no longer alive (crash, OOM kill,
     * or a clean stop whose pid file was not removed).
     */
    public static function processDead(int $pid): self
    {
        return new self(\sprintf('Workerman is not running (master process %d is not alive).', $pid));
    }

    /**
     * The PID is alive but its identity cannot be verified against the
     * recorded fingerprint (fingerprint mismatch — possible PID reuse or
     * a process owned by a different user), or no fingerprint sidecar
     * exists (pre-0.25.0 start or the daemon-start window before the
     * master has written its fingerprint).
     *
     * @param bool $hasFingerprint true when a fingerprint sidecar was found
     *                              but its contents did not match the
     *                              candidate PID; false when no sidecar
     *                              exists at all.
     */
    public static function unverifiable(int $pid, bool $hasFingerprint): self
    {
        if ($hasFingerprint) {
            return new self(\sprintf(
                'Cannot verify master process %d: its identity does not match the recorded fingerprint (possible PID reuse or different user). Stop it manually if it is still running.',
                $pid,
            ));
        }

        return new self(\sprintf(
            'Cannot verify master process %d: no fingerprint sidecar was found. If it was started by a pre-0.25.0 version of the bundle, stop it manually (e.g. kill %d) and remove the stale pid file.',
            $pid,
            $pid,
        ));
    }
}
