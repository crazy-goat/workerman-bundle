<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bootstrap gate mined from `.pi-subagents/artifacts/` review history (issue
 * #686 phase 4): "markdown structural defects" (broken cross-directory links,
 * wrong anchors, list-breaking fences) recurred across ~6 review artifacts.
 * This is the automated check the rule of two calls for on a second
 * occurrence — see `docs/workflow.md`, step 4c.
 *
 * For every tracked `.md` file: every internal `[text](target)` link resolves
 * (case-sensitively — this repo runs CI on Linux, where a case-insensitive
 * macOS checkout would otherwise hide a broken path, see `tests/Fixtures` vs.
 * `tests/fixtures` in the LintScopeTest follow-up issue) relative to the file
 * that contains it; a `#anchor` on an internal link resolves against the
 * target file's GitHub-slugified headings; and fenced code blocks are
 * balanced, and excluded from both checks so a `[…](…)` inside an example
 * snippet is never mistaken for a real link.
 *
 * @coversNothing
 */
final class MarkdownLinkTest extends TestCase
{
    // GitHub's slugger keeps letters and digits from any script, not just
    // ASCII (`\p{L}`/`\p{N}` under the `u` modifier) — only punctuation is
    // dropped. An ASCII-only class would strip an accented letter instead of
    // preserving it, e.g. turning "Café Meeting" into "caf-meeting" rather
    // than "café-meeting".
    private const GITHUB_SLUG_DISALLOWED_CHARS = '/[^\p{L}\p{N} _\-]/u';

    /**
     * @return array<string, array{0: string}>
     */
    public static function trackedMarkdownFileProvider(): array
    {
        $projectDir = self::rootDir();
        $files = self::trackedMarkdownFiles($projectDir);

        $cases = [];
        foreach ($files as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    #[DataProvider('trackedMarkdownFileProvider')]
    public function testFencesAreBalanced(string $relativePath): void
    {
        $lines = explode("\n", $this->rawContents($relativePath));
        $fenceLines = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line) === 1) {
                $fenceLines++;
            }
        }

        self::assertSame(
            0,
            $fenceLines % 2,
            sprintf('%s has an odd number (%d) of fence delimiter lines — a code block was never closed', $relativePath, $fenceLines),
        );
    }

    #[DataProvider('trackedMarkdownFileProvider')]
    public function testInternalLinksResolve(string $relativePath): void
    {
        $root = self::rootDir();
        $prose = $this->stripFencedBlocks($this->rawContents($relativePath));

        foreach ($this->extractLinks($prose) as $link) {
            $target = trim($link);

            if ($target === '' || $this->isExternal($target)) {
                continue;
            }

            $hashPos = strpos($target, '#');
            $path = $hashPos === false ? $target : substr($target, 0, $hashPos);
            $anchor = $hashPos === false ? null : substr($target, $hashPos + 1);
            $baseDir = dirname($relativePath);
            $baseDir = $baseDir === '.' ? '' : $baseDir;

            // "[x](#anchor)" — same-file anchor, nothing to resolve on disk.
            if ($path === '') {
                self::assertNotNull($anchor, sprintf('%s: link target "%s" is empty', $relativePath, $target));
                $this->assertAnchorExists($root, $relativePath, $relativePath, $anchor);

                continue;
            }

            $resolvedRelative = $this->resolveCaseSensitive($root, $baseDir, $path);

            self::assertNotNull(
                $resolvedRelative,
                sprintf('%s: link target "%s" does not resolve to a tracked path (case-sensitive)', $relativePath, $target),
            );

            if ($anchor !== null && $anchor !== '' && is_file($root . '/' . $resolvedRelative)) {
                $this->assertAnchorExists($root, $relativePath, $resolvedRelative, $anchor);
            }
        }
    }

    /**
     * Dormant today — no tracked `.md` file has a non-ASCII heading — but a
     * latent false positive the moment one appears (e.g. an author's name).
     * GitHub preserves non-ASCII letters in its heading anchors instead of
     * stripping them, so the slugger must too.
     */
    public function testGithubSlugPreservesNonAsciiLetters(): void
    {
        self::assertSame('café-meeting', $this->invokeGithubSlug('Café Meeting'));
        self::assertSame('café-meeting', $this->invokeHeadingSlugs("# Café Meeting\n")[0] ?? null);
    }

    private function invokeGithubSlug(string $heading): string
    {
        $method = new \ReflectionMethod($this, 'githubSlug');

        return (string) $method->invoke($this, $heading);
    }

    /**
     * @return list<string>
     */
    private function invokeHeadingSlugs(string $content): array
    {
        $method = new \ReflectionMethod($this, 'headingSlugs');

        /** @var list<string> $slugs */
        $slugs = $method->invoke($this, $content);

        return $slugs;
    }

    private function assertAnchorExists(string $root, string $sourceFile, string $targetRelative, string $anchor): void
    {
        $targetFile = $root . '/' . $targetRelative;

        if (!is_file($targetFile)) {
            // A directory link with an anchor makes no sense; the file-
            // resolution assertion above already covers missing targets.
            return;
        }

        $slugs = $this->headingSlugs($this->stripFencedBlocks((string) file_get_contents($targetFile)));

        self::assertContains(
            $anchor,
            $slugs,
            sprintf(
                '%s: anchor "#%s" does not match any heading in %s (available: %s)',
                $sourceFile,
                $anchor,
                $targetRelative,
                implode(', ', $slugs) !== '' ? implode(', ', $slugs) : '(no headings)',
            ),
        );
    }

    private function rawContents(string $relativePath): string
    {
        $contents = file_get_contents(self::rootDir() . '/' . $relativePath);
        self::assertIsString($contents, 'unable to read ' . $relativePath);

        return $contents;
    }

    /**
     * Removes fenced code blocks (``` or ~~~, three or more) so links and
     * headings inside example snippets are never evaluated as real ones.
     */
    private function stripFencedBlocks(string $content): string
    {
        $lines = explode("\n", $content);
        $out = [];
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line) === 1) {
                $inFence = !$inFence;

                continue;
            }

            if (!$inFence) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string> raw link targets, images and external anchors
     *                       already excluded by the caller
     */
    private function extractLinks(string $content): array
    {
        if (preg_match_all('/(?<!!)\[[^\]\n]*\]\(([^)\n]+)\)/', $content, $matches) === false) {
            return [];
        }

        return $matches[1];
    }

    private function isExternal(string $target): bool
    {
        return preg_match('#^(https?://|mailto:|tel:)#i', $target) === 1;
    }

    /**
     * Resolves `$path` (relative to `$baseDir`, both relative to `$root`)
     * against the real directory entries, case-sensitively — `is_file()`
     * alone would pass a wrong-case path on a case-insensitive filesystem
     * (macOS/APFS) and only fail on Linux CI.
     *
     * @return string|null the resolved path relative to $root, or null when
     *                      any path segment does not exist under that exact case
     */
    private function resolveCaseSensitive(string $root, string $baseDir, string $path): ?string
    {
        $path = rtrim($path, '/');
        $segments = explode('/', $baseDir . '/' . $path);
        $current = $root;
        $resolvedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($resolvedSegments === []) {
                    return null;
                }

                array_pop($resolvedSegments);
                $current = $root . ($resolvedSegments === [] ? '' : '/' . implode('/', $resolvedSegments));

                continue;
            }

            $entries = @scandir($current);

            if ($entries === false || !in_array($segment, $entries, true)) {
                return null;
            }

            $resolvedSegments[] = $segment;
            $current = $root . '/' . implode('/', $resolvedSegments);
        }

        return implode('/', $resolvedSegments);
    }

    /**
     * GitHub's heading-anchor algorithm: link text resolved, lower-cased,
     * anything but letters/digits/spaces/hyphens/underscores dropped, spaces
     * turned into hyphens, duplicates suffixed `-1`, `-2`, ... in document
     * order.
     *
     * @return list<string>
     */
    private function headingSlugs(string $content): array
    {
        $slugs = [];
        $seen = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $line, $matches) !== 1) {
                continue;
            }

            $slug = $this->githubSlug($matches[1]);
            $count = $seen[$slug] ?? 0;
            $seen[$slug] = $count + 1;
            $slugs[] = $count === 0 ? $slug : $slug . '-' . $count;
        }

        return $slugs;
    }

    private function githubSlug(string $heading): string
    {
        // "[text](url)" inside a heading contributes only its text.
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        // mb_strtolower (not strtolower, which only touches A-Z) so a
        // non-ASCII uppercase letter is lower-cased instead of left alone.
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace(self::GITHUB_SLUG_DISALLOWED_CHARS, '', $text);
        $text = trim($text);

        return (string) preg_replace('/\s+/', '-', $text);
    }

    /**
     * @return list<string>
     */
    private static function trackedMarkdownFiles(string $root): array
    {
        $output = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files ' . escapeshellarg('*.md'), $output, $exitCode);
        self::assertSame(0, $exitCode, 'git ls-files failed');

        return array_values(array_filter($output, static fn(string $line): bool => trim($line) !== ''));
    }

    private static function rootDir(): string
    {
        $root = realpath(__DIR__ . '/..');
        self::assertNotFalse($root, 'cannot determine project root');

        return $root;
    }
}
