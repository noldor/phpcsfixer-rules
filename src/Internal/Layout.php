<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use LogicException;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class Layout
{
    public static function lineWidth(Tokens $tokens, int $index): int
    {
        $width = 0;

        foreach (\mb_str_split(self::physicalLine($tokens, $index), 1, 'UTF-8') as $character) {
            $width += "\t" === $character ? 4 - $width % 4 : 1;
        }

        return $width;
    }

    public static function indentAt(Tokens $tokens, int $index): string
    {
        \preg_match('/^[\\t ]*/', self::physicalLine($tokens, $index), $matches);

        return $matches[0] ?? '';
    }

    public static function breakBefore(Tokens $tokens, int $index, string $indent, string $lineEnding): void
    {
        self::breakAt(
            $tokens,
            $index,
            $indent,
            $lineEnding,
        );
    }

    public static function breakAfter(Tokens $tokens, int $index, string $indent, string $lineEnding): void
    {
        $next = $tokens->getNextNonWhitespace($index);

        if (null !== $next && $tokens[$next]->isGivenKind(\T_COMMENT)
            && \preg_match('/^(?:\\/\\/|#)/', $tokens[$next]->getContent()) === 1
            && ! \str_contains($tokens->generatePartialCode($index + 1, $next), "\n")) {
            self::breakAt(
                $tokens,
                $next + 1,
                $indent,
                $lineEnding,
            );

            return;
        }

        self::breakAt(
            $tokens,
            $index + 1,
            $indent,
            $lineEnding,
        );
    }

    /**
     * @param list<int> $commas
     */
    public static function wrapDelimitedList(
        Tokens $tokens,
        int $open,
        int $close,
        array $commas,
        string $baseIndent,
        string $indent,
        string $lineEnding,
    ): void {
        $boundaries = [$open, ...$commas, $close];
        $childIndent = $baseIndent . $indent;

        for ($position = 0; \count($boundaries) - 1 > $position; ++$position) {
            $start = $tokens->getNextMeaningfulToken($boundaries[$position]);
            $end = $boundaries[$position + 1];

            if (null !== $start && $start < $end) {
                self::reindent(
                    $tokens,
                    $start,
                    $end,
                    self::indentAt(
                        $tokens,
                        $start,
                    ),
                    $childIndent,
                );
            }
        }

        $lastComma = [] === $commas ? null : $commas[\array_key_last($commas)];
        $trailingComma = null !== $lastComma && $tokens->getNextMeaningfulToken(
            $lastComma,
        ) === $close;
        self::breakBefore(
            $tokens,
            $close,
            $baseIndent,
            $lineEnding,
        );

        foreach (\array_reverse($commas) as $comma) {
            self::breakAfter(
                $tokens,
                $comma,
                $trailingComma && $comma === $lastComma ? $baseIndent : $childIndent,
                $lineEnding,
            );
        }
        self::breakAfter(
            $tokens,
            $open,
            $childIndent,
            $lineEnding,
        );
    }

    private static function physicalLine(Tokens $tokens, int $index): string
    {
        return \mb_rtrim(self::linePrefix($tokens, $index) . self::lineSuffix(
            $tokens,
            $index,
        ), "\r");
    }

    private static function linePrefix(Tokens $tokens, int $index): string
    {
        $prefix = '';

        for ($cursor = $index - 1; 0 <= $cursor; --$cursor) {
            $content = $tokens[$cursor]->getContent();
            $newline = \mb_strrpos($content, "\n");

            if (false !== $newline) {
                return \mb_substr($content, $newline + 1) . $prefix;
            }
            $prefix = $content . $prefix;
        }

        return $prefix;
    }

    private static function lineSuffix(Tokens $tokens, int $index): string
    {
        $suffix = '';

        for ($cursor = $index; \count($tokens) > $cursor; ++$cursor) {
            $content = $tokens[$cursor]->getContent();
            $newline = \mb_strpos($content, "\n");

            if (false !== $newline) {
                return $suffix . \mb_substr($content, 0, $newline);
            }
            $suffix .= $content;
        }

        return $suffix;
    }

    private static function breakAt(Tokens $tokens, int $index, string $indent, string $lineEnding): void
    {
        $whitespace = new Token([\T_WHITESPACE, $lineEnding . $indent]);

        if (isset($tokens[$index]) && $tokens[$index]->isWhitespace()) {
            $tokens[$index] = $whitespace;
        } elseif (0 < $index && $tokens[$index - 1]->isWhitespace()) {
            $tokens[$index - 1] = $whitespace;
        } else {
            $tokens->insertAt($index, $whitespace);
        }
    }

    private static function reindent(Tokens $tokens, int $start, int $end, string $oldIndent, string $newIndent): void
    {
        if ($oldIndent === $newIndent) {
            return;
        }

        for ($index = $start; $index < $end; ++$index) {
            if (! $tokens[$index]->isWhitespace()) {
                continue;
            }
            $content = \preg_replace_callback(
                '/(\\r\\n|\\n|\\r)([\\t ]*)/',
                static fn (array $match): string => $match[1] . (\str_starts_with($match[2], $oldIndent) ? $newIndent . \mb_substr($match[2], \mb_strlen($oldIndent)) : $match[2]),
                $tokens[$index]->getContent(),
            ) ?? throw new LogicException('Cannot reindent token whitespace.');
            $tokens[$index] = new Token([\T_WHITESPACE, $content]);
        }
    }
}
