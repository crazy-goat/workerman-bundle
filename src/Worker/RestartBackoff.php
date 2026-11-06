<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Worker;

use CrazyGoat\WorkermanBundle\Util\ProcessTerminator;
use Workerman\Worker;

/**
 * Exponential restart backoff shared by supervised processes and HTTP workers.
 *
 * State is kept per worker slot in `<runtime_dir>/restart/`. The counter grows
 * while a slot fails quickly, resets after a run at least `healthyAfter`
 * seconds long, and produces one "unhealthy" log line at `maxFailures`.
 */
final readonly class RestartBackoff
{
    private const SLEEP_SLICE_MICROSECONDS = 100_000;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /** @var \Closure(): int */
    private \Closure $now;

    private int $maxDelay;

    /**
     * @param \Closure(int): void|null $sleep Test seam: replaces the real interruptible sleep.
     * @param \Closure(): int|null     $now   Test seam: replaces time().
     */
    public function __construct(
        private string $runtimeDir,
        private int $delay = 0,
        int $maxDelay = 60,
        private int $maxFailures = 10,
        private int $healthyAfter = 30,
        ?\Closure $sleep = null,
        ?\Closure $now = null,
    ) {
        $this->maxDelay = max($maxDelay, $delay);
        $this->sleep = $sleep ?? $this->interruptibleSleep(...);
        $this->now = $now ?? time(...);
    }

    /**
     * @param mixed[] $restartConfig The resolved `workerman.restart` config.
     */
    public static function fromConfig(
        string $runtimeDir,
        array $restartConfig,
        ?int $delay = null,
        ?int $maxDelay = null,
    ): self {
        return new self(
            runtimeDir: $runtimeDir,
            delay: $delay ?? self::intValue($restartConfig['delay'] ?? 0),
            maxDelay: $maxDelay ?? self::intValue($restartConfig['max_delay'] ?? 60),
            maxFailures: self::intValue($restartConfig['max_failures'] ?? 10),
            healthyAfter: self::intValue($restartConfig['healthy_after'] ?? 30),
        );
    }

    /**
     * Wait before this worker slot starts, then record the attempt.
     *
     * The wait is split into short slices. Stop and reload signals are
     * temporarily handled directly so the child ends immediately on every
     * event loop (Select, ext-event, ...), not after the full delay.
     *
     * @param \Closure(string): void $log
     */
    public function beforeStart(string $key, string $kind, string $label, \Closure $log): void
    {
        if ($this->delay <= 0) {
            return;
        }

        $now = ($this->now)();
        $state = $this->read($key);
        $failures = $state['failures'];

        if ($state['startedAt'] > 0 && $now - $state['startedAt'] >= $this->healthyAfter) {
            $failures = 0;
            $state['unhealthyLogged'] = false;
        }

        $wait = $this->delayForFailures($failures);
        if ($wait > 0) {
            // Record the attempt before sleeping. If the child is killed
            // during the wait, startedAt stays 0, so the next start counts it
            // as a fast failure instead of mistaking the wait for a healthy run.
            $this->write($key, [
                'failures' => $failures + 1,
                'startedAt' => 0,
                'unhealthyLogged' => $state['unhealthyLogged'],
            ]);
            ($this->sleep)($wait);
        }

        $unhealthyLogged = $state['unhealthyLogged'];
        if ($this->maxFailures > 0 && $failures >= $this->maxFailures && !$unhealthyLogged) {
            $log(sprintf('%s "%s" is unhealthy: %d fast restarts', $kind, $label, $failures));
            $unhealthyLogged = true;
        }

        $this->write($key, [
            'failures' => $failures + 1,
            'startedAt' => ($this->now)(),
            'unhealthyLogged' => $unhealthyLogged,
        ]);
    }

    /**
     * Forget one worker slot, for example after an intentional reload.
     */
    public function forget(string $key): void
    {
        $path = $this->statePath($key, false);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Create the state directory before the master forks workers.
     *
     * A root master that drops workers to `user`/`group` must hand the
     * directory to them, otherwise the child cannot write its state file.
     */
    public static function prepare(string $runtimeDir, ?string $user, ?string $group): void
    {
        $directory = self::stateDirectory($runtimeDir);
        if ($directory === null) {
            return;
        }

        if ($user !== null && $user !== '') {
            @chown($directory, $user);
        }

        if ($group !== null && $group !== '') {
            @chgrp($directory, $group);
        }
    }

    /**
     * Forget all per-slot state, for example when the master starts again.
     */
    public static function reset(string $runtimeDir): void
    {
        $directory = self::stateDirectory($runtimeDir, false);
        if ($directory === null) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * The wait before a start, given the number of consecutive fast failures.
     */
    public function delayForFailures(int $failures): int
    {
        if ($failures <= 0 || $this->delay <= 0) {
            return 0;
        }

        $delay = $this->delay;
        for ($i = 1; $i < $failures && $delay < $this->maxDelay; $i++) {
            $delay *= 2;
        }

        return min($delay, $this->maxDelay);
    }

    private function interruptibleSleep(int $seconds): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_dispatch')) {
            usleep($seconds * 1_000_000);

            return;
        }

        $signals = [SIGINT, SIGTERM, SIGHUP, SIGTSTP, SIGQUIT, SIGUSR1, SIGUSR2];
        $previous = [];
        foreach ($signals as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function (): never {
                // SIGKILL on grpc hosts: a plain exit() can hang in the
                // extension's shutdown handler (see ProcessTerminator).
                ProcessTerminator::terminate(0);
            }, false);
        }

        try {
            $deadline = microtime(true) + $seconds;

            while (($remaining = $deadline - microtime(true)) > 0) {
                pcntl_signal_dispatch();

                $microseconds = max(1, (int) ($remaining * 1_000_000));
                usleep(min(self::SLEEP_SLICE_MICROSECONDS, $microseconds));
            }
        } finally {
            $this->restoreSignalHandlers($signals, $previous);
        }
    }

    /**
     * Re-register the stop and reload handlers through the active event loop.
     *
     * pcntl_signal() only restores the Select loop's wrapper. Event, Swoole
     * and Swow register signals outside the pcntl table, so restoring the
     * captured pcntl handler there would leave the child deaf to stop and
     * reload (async signals stay off in worker processes).
     *
     * @param list<int>                $signals
     * @param array<int, callable|int> $previous
     */
    private function restoreSignalHandlers(array $signals, array $previous): void
    {
        $event = Worker::$globalEvent;
        if ($event instanceof \Workerman\Events\EventInterface) {
            foreach ($signals as $signal) {
                $event->offSignal($signal);
                $event->onSignal($signal, Worker::signalHandler(...));
            }

            return;
        }

        foreach ($previous as $signal => $handler) {
            pcntl_signal($signal, $handler, false);
        }
    }

    /**
     * @return array{failures: int, startedAt: int, unhealthyLogged: bool}
     */
    private function read(string $key): array
    {
        $path = $this->statePath($key);
        if ($path === null) {
            return ['failures' => 0, 'startedAt' => 0, 'unhealthyLogged' => false];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return ['failures' => 0, 'startedAt' => 0, 'unhealthyLogged' => false];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return ['failures' => 0, 'startedAt' => 0, 'unhealthyLogged' => false];
        }

        return [
            'failures' => max(0, self::intValue($decoded['failures'] ?? 0)),
            'startedAt' => max(0, self::intValue($decoded['startedAt'] ?? 0)),
            'unhealthyLogged' => ($decoded['unhealthyLogged'] ?? false) === true,
        ];
    }

    /**
     * @param array{failures: int, startedAt: int, unhealthyLogged: bool} $state
     */
    private function write(string $key, array $state): void
    {
        $path = $this->statePath($key);
        if ($path === null) {
            return;
        }

        $temporary = $path . '.' . (int) getmypid() . '.tmp';
        if (@file_put_contents($temporary, json_encode($state, \JSON_THROW_ON_ERROR), \LOCK_EX) === false) {
            return;
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function statePath(string $key, bool $create = true): ?string
    {
        $directory = self::stateDirectory($this->runtimeDir, $create);
        if ($directory === null) {
            return null;
        }

        return $directory . '/' . hash('sha256', $key) . '.json';
    }

    private static function stateDirectory(string $runtimeDir, bool $create = true): ?string
    {
        if ($runtimeDir === '') {
            return null;
        }

        $directory = rtrim($runtimeDir, '/') . '/restart';
        if (!is_dir($directory) && !$create) {
            return null;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }

        return $directory;
    }

    private static function intValue(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
