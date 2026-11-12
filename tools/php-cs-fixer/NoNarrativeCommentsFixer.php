<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tools\PhpCsFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\DocBlock\TypeExpression;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class NoNarrativeCommentsFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    public const NAME = 'CrazyGoat/no_narrative_comments';

    private const DIRECTIVE_REGEX = '~^(?://|#|/\*+)[\s*]*(?:@phpstan-ignore|@codeCoverageIgnore|phpcs:|@psalm-suppress)~i';

    private const TYPED_TAGS = ['param', 'param-out', 'return', 'throws', 'var'];

    private const NAMED_TAGS = ['param', 'param-out', 'var'];

    private const BARE_TAGS = ['deprecated', 'internal', 'codecoverageignore', 'codecoverageignorestart', 'codecoverageignoreend'];

    private const VERBATIM_TAG_REGEX = '~^(?:phpstan-|psalm-|template)~i';

    private const VARIABLE_REGEX = '\h+(?:&\h*)?(?:\.\.\.\h*)?\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Comments may not narrate: docblocks keep only bare type tags, line and block comments are removed unless they are tool directives.',
            [new CodeSample("<?php\n/**\n * Adds numbers.\n *\n * @param int \$a the first number\n */\nfunction add(int \$a) {\n    // add them\n    return \$a; // @phpstan-ignore return.type\n}\n")],
        );
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens<\PhpCsFixer\Tokenizer\Token> $tokens
     */
    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_COMMENT, \T_DOC_COMMENT]);
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens<\PhpCsFixer\Tokenizer\Token> $tokens
     */
    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $counter = \count($tokens);
        for ($index = 0; $index < $counter; ++$index) {
            $token = $this->tokenAt($tokens, $index);
            if ($token->isGivenKind(\T_COMMENT)) {
                if (preg_match(self::DIRECTIVE_REGEX, $token->getContent()) !== 1) {
                    $this->removeComment($tokens, $index);
                }

                continue;
            }

            if (!$token->isGivenKind(\T_DOC_COMMENT)) {
                continue;
            }

            if (TestAnnotationTags::find($token->getContent()) !== null) {
                continue;
            }

            $stripped = $this->stripDocBlock($token->getContent());
            if ($stripped === null) {
                $this->removeComment($tokens, $index);
            } elseif ($stripped !== $token->getContent()) {
                $tokens->offsetSet($index, new Token([\T_DOC_COMMENT, $stripped]));
            }
        }
    }

    private function stripDocBlock(string $content): ?string
    {
        if (preg_match('~^/\*\*(.*)\*/$~s', $content, $match) !== 1) {
            return $content;
        }

        $inner = $match[1];
        if (!str_contains($inner, "\n")) {
            $parts = preg_split('~\h+(?=@[A-Za-z])~', trim($inner)) ?: [trim($inner)];
            $kept = $this->keptLines(array_map(static fn(string $part): array => ['', $part], $parts));
            if ($kept === []) {
                return null;
            }

            return '/** ' . implode(' ', array_map(static fn(array $line): string => $line[1], $kept)) . ' */';
        }

        $rawLines = explode("\n", $inner);
        $closing = array_pop($rawLines);
        if (trim($closing) !== '') {
            $rawLines[] = $closing;
            $closing = preg_replace('~\S.*$~', '', $closing) ?? '';
        }

        $lines = [];
        foreach ($rawLines as $position => $rawLine) {
            if ($position === 0) {
                $lines[] = ['', trim($rawLine)];

                continue;
            }
            if (preg_match('~^(\h*\*(?!/)\h?|\h*)(.*)$~', $rawLine, $parts) === 1) {
                $lines[] = [$parts[1], rtrim($parts[2])];
            }
        }

        $kept = $this->keptLines($lines);
        if ($kept === []) {
            return null;
        }

        return $this->renderMultiLine($kept, rtrim($closing, "\r"));
    }

    /**
     * @param list<array{string, string}> $kept
     */
    private function renderMultiLine(array $kept, string $closing): string
    {
        $defaultPrefix = $closing . '* ';
        $out = '/**';
        foreach ($kept as [$prefix, $text]) {
            $out .= "\n" . ($prefix === '' ? $defaultPrefix : $prefix) . $text;
        }

        return $out . "\n" . $closing . '*/';
    }

    /**
     * @param list<array{string, string}> $lines
     * @return list<array{string, string}>
     */
    private function keptLines(array $lines): array
    {
        $groups = [];
        $current = null;
        foreach ($lines as $line) {
            $text = ltrim($line[1]);
            if ($text === '') {
                $current = null;

                continue;
            }
            if (preg_match('~^@[A-Za-z]~', $text) === 1 || str_starts_with($text, 'phpcs:')) {
                $groups[] = [[$line[0] . substr($line[1], 0, \strlen($line[1]) - \strlen($text)), $text]];
                $current = array_key_last($groups);

                continue;
            }
            if ($current !== null) {
                $groups[$current][] = $line;
            }
        }

        $kept = [];
        foreach ($groups as $group) {
            foreach ($this->keptGroup($group) as $line) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    /**
     * @param non-empty-list<array{string, string}> $group
     * @return list<array{string, string}>
     */
    private function keptGroup(array $group): array
    {
        $first = $group[0][1];
        if (str_starts_with($first, 'phpcs:')) {
            return [$group[0]];
        }

        preg_match('~^@([A-Za-z][\w-]*)~', $first, $match);
        $tag = $match[1] ?? '';
        $lowerTag = strtolower($tag);

        if (preg_match(self::VERBATIM_TAG_REGEX, $tag) === 1) {
            return $group;
        }

        if (\in_array($lowerTag, self::BARE_TAGS, true)) {
            return [[$group[0][0], '@' . $tag]];
        }

        if ($lowerTag === 'see') {
            if (preg_match('~^@see\h+(\S+)~i', $first, $see) !== 1) {
                return [];
            }

            return [[$group[0][0], '@' . $tag . ' ' . $see[1]]];
        }

        if (!\in_array($lowerTag, self::TYPED_TAGS, true)) {
            return [];
        }

        $text = implode("\n", array_map(static fn(array $line): string => $line[1], $group));
        $length = $this->typedTagLength($text, $tag, \in_array($lowerTag, self::NAMED_TAGS, true));
        if ($length === null) {
            throw new \UnexpectedValueException(\sprintf(
                '%s: cannot parse the type of "%s"; fix the type, or use the @phpstan-%s form, which is kept verbatim.',
                self::NAME,
                strtok($first, "\n"),
                $lowerTag,
            ));
        }

        $keptLines = explode("\n", rtrim(substr($text, 0, $length)));
        $result = [];
        foreach ($keptLines as $position => $keptLine) {
            $result[] = [$group[$position][0], rtrim($keptLine)];
        }

        return $result;
    }

    private function typedTagLength(string $text, string $tag, bool $named): ?int
    {
        $quotedTag = preg_quote($tag, '~');
        if (preg_match('~^@' . $quotedTag . '\h+' . TypeExpression::REGEX_TYPES . '~', $text, $match) === 1) {
            $length = \strlen($match[0]);
            if ($named && preg_match('~\G' . self::VARIABLE_REGEX . '~', $text, $variable, 0, $length) === 1) {
                $length += \strlen($variable[0]);
            }
            if ($length === \strlen($text) || preg_match('~\G\s~', $text, $unused, 0, $length) === 1) {
                return $length;
            }
        }

        if ($named && preg_match('~^@' . $quotedTag . self::VARIABLE_REGEX . '(?=\s|$)~', $text, $match) === 1) {
            return \strlen($match[0]);
        }

        if (preg_match('~^@' . $quotedTag . '(?=\s|$)~', $text) === 1 && trim(substr($text, \strlen($tag) + 1)) === '') {
            return \strlen($tag) + 1;
        }

        return null;
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens<\PhpCsFixer\Tokenizer\Token> $tokens
     */
    private function removeComment(Tokens $tokens, int $index): void
    {
        $prevIndex = $tokens->getNonEmptySibling($index, -1);
        $nextIndex = $tokens->getNonEmptySibling($index, 1);
        $prev = $prevIndex === null ? null : $this->tokenAt($tokens, $prevIndex);
        $next = $nextIndex === null ? null : $this->tokenAt($tokens, $nextIndex);

        $prevWhitespace = $prev instanceof Token && $prev->isWhitespace() ? $prev->getContent() : null;
        $nextWhitespace = $next instanceof Token && $next->isWhitespace() ? $next->getContent() : null;

        $beforePrevIndex = $prevWhitespace !== null && $prevIndex !== null ? $tokens->getNonEmptySibling($prevIndex, -1) : $prevIndex;
        $beforePrev = $beforePrevIndex === null ? null : $this->tokenAt($tokens, $beforePrevIndex);
        $beforePrevEndsLine = $beforePrev instanceof Token && str_ends_with($beforePrev->getContent(), "\n");

        $startsLine = ($prevWhitespace !== null && str_contains($prevWhitespace, "\n"))
            || $beforePrevEndsLine
            || !$beforePrev instanceof Token;
        $endsLine = !$next instanceof Token || ($nextWhitespace !== null && str_contains($nextWhitespace, "\n"));

        $tokens->clearAt($index);

        if ($startsLine && $endsLine) {
            $this->removeLine($tokens, $prevIndex, $prevWhitespace, $nextIndex, $nextWhitespace, $beforePrev, $beforePrevEndsLine);

            return;
        }

        if ($prevWhitespace !== null && !str_contains($prevWhitespace, "\n") && $prevIndex !== null
            && ($nextWhitespace !== null || !$next instanceof Token || $this->joinsSafely($beforePrev, $next))) {
            $tokens->clearAt($prevIndex);

            return;
        }

        if ($nextWhitespace !== null && !str_contains($nextWhitespace, "\n") && $nextIndex !== null) {
            $afterNextIndex = $tokens->getNonEmptySibling($nextIndex, 1);
            $afterNext = $afterNextIndex === null ? null : $this->tokenAt($tokens, $afterNextIndex);
            if ($prevWhitespace !== null || $this->joinsSafely($prev, $afterNext)) {
                $tokens->clearAt($nextIndex);
            }

            return;
        }

        if ($prev instanceof Token && $next instanceof Token && $prevWhitespace === null && $nextWhitespace === null
            && !$this->joinsSafely($prev, $next)) {
            $tokens->offsetSet($index, new Token([\T_WHITESPACE, ' ']));
        }
    }

    private function joinsSafely(?Token $left, ?Token $right): bool
    {
        if (!$left instanceof Token || !$right instanceof Token) {
            return true;
        }

        $joined = token_get_all('<?php ' . $left->getContent() . $right->getContent());
        array_shift($joined);
        $contents = array_map(static fn(array|string $token): string => \is_array($token) ? $token[1] : $token, $joined);

        return $contents === [$left->getContent(), $right->getContent()];
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens<\PhpCsFixer\Tokenizer\Token> $tokens
     */
    private function removeLine(
        Tokens $tokens,
        ?int $prevIndex,
        ?string $prevWhitespace,
        ?int $nextIndex,
        ?string $nextWhitespace,
        ?Token $beforePrev,
        bool $beforePrevEndsLine,
    ): void {
        $lineEnding = $this->whitespacesConfig->getLineEnding();

        if ($nextWhitespace === null || $nextIndex === null) {
            if ($prevWhitespace !== null && $prevIndex !== null) {
                $trimmed = rtrim($prevWhitespace, " \t");
                if ($trimmed === '') {
                    $tokens->clearAt($prevIndex);
                } else {
                    $tokens->offsetSet($prevIndex, new Token([\T_WHITESPACE, $trimmed]));
                }
            }

            return;
        }

        $before = substr_count($prevWhitespace ?? '', "\n") + ($beforePrevEndsLine ? 1 : 0);
        $after = substr_count($nextWhitespace, "\n");
        $newlines = min($before + $after - 1, max(2, $before, $after));

        $nextNonWhitespace = $tokens->getNonEmptySibling($nextIndex, 1);
        $closesBlock = $nextNonWhitespace !== null && $this->tokenAt($tokens, $nextNonWhitespace)->equalsAny([')', ']', '}']);
        $opensBlock = $beforePrev instanceof Token && $beforePrev->equalsAny(['(', '[', '{']);
        if ($closesBlock || $opensBlock) {
            $newlines = 1;
        }

        $newlines -= $beforePrevEndsLine ? 1 : 0;
        $indent = substr($nextWhitespace, (int) strrpos($nextWhitespace, "\n") + 1);
        $merged = str_repeat($lineEnding, max(0, $newlines)) . $indent;

        if ($prevWhitespace !== null && $prevIndex !== null) {
            $tokens->clearAt($prevIndex);
        }

        if ($merged === '') {
            $tokens->clearAt($nextIndex);
        } else {
            $tokens->offsetSet($nextIndex, new Token([\T_WHITESPACE, $merged]));
        }
    }

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens<\PhpCsFixer\Tokenizer\Token> $tokens
     */
    private function tokenAt(Tokens $tokens, int $index): Token
    {
        $token = $tokens->offsetGet($index);
        if (!$token instanceof Token) {
            throw new \OutOfBoundsException(\sprintf('No token at index %d.', $index));
        }

        return $token;
    }
}
