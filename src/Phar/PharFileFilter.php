<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Phar;

/**
 * Filters files for PHAR inclusion based on exclusion rules.
 *
 * Applies built-in exclusion patterns (tests, var, build, .git, etc.),
 * custom regex exclude patterns, and exact file exclusions.
 *
 * @internal
 */
final readonly class PharFileFilter
{
    /**
     * @param ExcludePattern[] $excludePatterns
     * @param string[]         $excludeFiles
     */
    public function __construct(
        private string $projectDir,
        private bool $includeTests,
        private array $excludePatterns,
        private array $excludeFiles,
    ) {
    }

    public function shouldInclude(\SplFileInfo $file): bool
    {
        $relativePath = $this->relativePath($file);

        if ($relativePath === '') {
            return false;
        }

        if (!$this->includeTests && PharBuilder::isExcluded($relativePath)) {
            return false;
        }

        foreach ($this->excludePatterns as $pattern) {
            if ($pattern->matches($relativePath)) {
                return false;
            }
        }

        foreach ($this->excludeFiles as $excluded) {
            if ($excluded === '' || $excluded === '0') {
                continue;
            }
            if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                return false;
            }
        }

        return true;
    }

    private function relativePath(\SplFileInfo $file): string
    {
        $projectDir = $this->projectDir;

        return ltrim(
            str_replace([$projectDir . '/', $projectDir], '', $file->getPathname()),
            '/',
        );
    }
}
