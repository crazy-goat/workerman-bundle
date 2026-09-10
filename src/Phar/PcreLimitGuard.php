<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * Establishes the bounded PCRE backtrack/recursion limits for the duration
 * of a filtering pass and restores the prior values afterwards.
 *
 * `ExcludePattern::matches()` used to raise and restore
 * `pcre.backtrack_limit` / `pcre.recursion_limit` on *every* call — four
 * `ini_set()` calls per pattern per file (issue #568). The limits are
 * constants and identical for every call, so the save/restore was pure
 * churn (~62% of `matches()` cost). This guard sets them once for the
 * whole pass; `matches()` now runs a bare `preg_match` inside the already
 * lowered limits.
 *
 * The per-call `@preg_match` + `false`-result safety net stays in
 * `ExcludePattern::matches()`: it is what turns a tripped backtrack limit
 * into "no match" instead of a hang (the ReDoS guard from #334). Only the
 * ini save/restore moved out.
 *
 * Usage:
 *
 *     $guard = new PcreLimitGuard();
 *     $guard->enter();
 *     try {
 *         $phar->buildFromIterator($filtered, $this->projectDir);
 *         // ...
 *     } finally {
 *         $guard->exit();
 *     }
 *
 * `exit()` is idempotent and safe to call even if `enter()` was not reached
 * (e.g. the guard was constructed but the pass threw before entry).
 *
 * @internal
 */
final class PcreLimitGuard
{
    private string|false|null $previousBacktrack = null;
    private string|false|null $previousRecursion = null;
    private bool $active = false;

    /**
     * Lower the PCRE limits for the current process. Captures the previous
     * values so {@see exit()} can restore them. Safe to call once per
     * instance; a second call without an intervening {@see exit()} is a
     * no-op.
     */
    public function enter(): void
    {
        if ($this->active) {
            return;
        }

        $this->previousBacktrack = ini_set('pcre.backtrack_limit', (string) ExcludePattern::BACKTRACK_LIMIT);
        $this->previousRecursion = ini_set('pcre.recursion_limit', (string) ExcludePattern::RECURSION_LIMIT);
        $this->active = true;
    }

    /**
     * Restore the PCRE limits captured by {@see enter()}. Idempotent: a call
     * when the guard is not active is a no-op, so this is safe to invoke in
     * a `finally` block even if `enter()` threw or was never reached.
     */
    public function exit(): void
    {
        if (!$this->active) {
            return;
        }

        if (is_string($this->previousBacktrack)) {
            ini_set('pcre.backtrack_limit', $this->previousBacktrack);
        }
        if (is_string($this->previousRecursion)) {
            ini_set('pcre.recursion_limit', $this->previousRecursion);
        }

        $this->active = false;
        $this->previousBacktrack = null;
        $this->previousRecursion = null;
    }
}
