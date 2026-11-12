<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * @internal
 */
final class PcreLimitGuard
{
    private string|false|null $previousBacktrack = null;
    private string|false|null $previousRecursion = null;
    private bool $active = false;

    public function enter(): void
    {
        if ($this->active) {
            return;
        }

        $this->previousBacktrack = ini_set('pcre.backtrack_limit', (string) ExcludePattern::BACKTRACK_LIMIT);
        $this->previousRecursion = ini_set('pcre.recursion_limit', (string) ExcludePattern::RECURSION_LIMIT);
        $this->active = true;
    }

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
