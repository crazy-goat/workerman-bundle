<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

final class ServerNotRunningException extends ServerException
{
    public function __construct(string $message = 'Workerman is not running.')
    {
        parent::__construct($message);
    }

    public static function noPidFile(): self
    {
        return new self('Workerman is not running (no pid file found).');
    }

    public static function processDead(int $pid): self
    {
        return new self(\sprintf('Workerman is not running (master process %d is not alive).', $pid));
    }

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
