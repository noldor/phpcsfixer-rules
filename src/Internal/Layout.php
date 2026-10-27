<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use LogicException;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

final class Layout
{
    public static function lineWidth(Tokens $tokens, int $index, int|null $end = null): int
    {
        return self::width(
            self::physicalLine($tokens, $index, $end),
        );
    }

    public static function spanWidth(Tokens $tokens, int $start, int $end, string $indent): int
    {
        $line = \preg_split('/\\r\\n|\\n|\\r/', $tokens->generatePartialCode($start, $end), 2);
        \assert(false !== $line);

        return self::width($indent . $line[0]);
    }

    public static function onlyClosingLine(Tokens $tokens, int $index): bool
    {
        $prefix = self::linePrefix($tokens, $index) . $tokens[$index]->getContent();

        return 1 === \preg_match('/^[\\t ]*[\\)\\]\\}]+$/D', $prefix);
    }

    public static function joinedLineWidth(Tokens $tokens, int $index, int|null $end, string $separator = ''): int
    {
        $previous = $tokens->getPrevNonWhitespace($index);
        \assert(null !== $previous);
        $prefix = self::linePrefix($tokens, $previous) . $tokens[$previous]->getContent();

        return self::width(
            $prefix . $separator . \mb_rtrim(self::lineSuffix($tokens, $index, $end), "\r"),
        );
    }

    public static function joinBefore(Tokens $tokens, int $index): void
    {
        for ($previous = $index - 1; 0 <= $previous; --$previous) {
            if ($tokens->isEmptyAt($previous)) {
                continue;
            }

            if (! $tokens[$previous]->isWhitespace()) {
                break;
            }

            $tokens->clearAt($previous);
        }
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

        if (null !== $next
            && $tokens[$next]->isGivenKind(\T_COMMENT)
            && \preg_match('/^(?:\\/\\/|#)/', $tokens[$next]->getContent()) === 1
            && ! \str_contains($tokens->generatePartialCode($index + 1, $next), "\n")
        ) {
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
        $firstItem = $tokens->getNextMeaningfulToken($open);
        $inlineIndent = null === $firstItem ? $baseIndent : self::indentAt($tokens, $firstItem);

        for ($position = 0; \count($boundaries) - 1 > $position; ++$position) {
            $start = $tokens->getNextMeaningfulToken($boundaries[$position]);
            $end = $boundaries[$position + 1];

            if (null !== $start && $start < $end) {
                self::reindent(
                    $tokens,
                    $start,
                    $end,
                    $tokens->isPartialCodeMultiline($boundaries[$position], $start)
                        ? self::indentAt($tokens, $start)
                        : $inlineIndent,
                    $childIndent,
                );
            }
        }

        $lastComma = [] === $commas ? null : $commas[\array_key_last($commas)];
        $trailingComma = null !== $lastComma && $tokens->getNextMeaningfulToken($lastComma) === $close;
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

    /**
     * Ignore line breaks inside array literals and anonymous function bodies.
     */
    public static function isMultiline(Tokens $tokens, int $start, int $end): bool
    {
        for ($index = $start; $index <= $end; ++$index) {
            $array = self::arrayLiteralStart($tokens, $index);

            if (null !== $array) {
                if ($tokens->isPartialCodeMultiline($index, $array)) {
                    return true;
                }

                $block = Tokens::detectBlockType($tokens[$array]);
                \assert(null !== $block);
                $index = $tokens->findBlockEnd($block['type'], $array);

                continue;
            }

            $parameters = $tokens[$index]->isGivenKind(\T_FUNCTION)
                ? self::closureParametersStart($tokens, $index)
                : null;

            if (null !== $parameters) {
                $parametersEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $parameters);
                $body = $tokens->getNextTokenOfKind($parametersEnd, ['{']);
                \assert(null !== $body);

                if ($tokens->isPartialCodeMultiline($index, $body)) {
                    return true;
                }

                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $body);

                continue;
            }

            if (\str_contains($tokens[$index]->getContent(), "\n")) {
                return true;
            }
        }

        return false;
    }

    public static function reindent(Tokens $tokens, int $start, int $end, string $oldIndent, string $newIndent): void
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
                static fn (array $match): string => $match[1] . (\str_starts_with($match[2], $oldIndent)
                    ? $newIndent . \mb_substr($match[2], \mb_strlen($oldIndent))
                    : $match[2]),
                $tokens[$index]->getContent(),
            ) ?? throw new LogicException('Cannot reindent token whitespace.');
            $tokens[$index] = new Token([\T_WHITESPACE, $content]);
        }
    }

    private static function width(string $line): int
    {
        $width = 0;

        foreach (\mb_str_split($line, 1, 'UTF-8') as $character) {
            $width += "\t" === $character ? 4 - $width % 4 : 1;
        }

        return $width;
    }

    private static function arrayLiteralStart(Tokens $tokens, int $index): int|null
    {
        if ($tokens[$index]->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
            return $index;
        }

        if (! $tokens[$index]->isGivenKind(\T_ARRAY)) {
            return null;
        }

        $previous = $tokens->getPrevMeaningfulToken($index);

        if (null !== $previous
            && $tokens[$previous]->isGivenKind(
                [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
            )
        ) {
            return null;
        }

        return $tokens->getNextMeaningfulToken($index);
    }

    private static function closureParametersStart(Tokens $tokens, int $index): int|null
    {
        $previous = $tokens->getPrevMeaningfulToken($index);

        if (null !== $previous
            && $tokens[$previous]->isGivenKind(
                [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
            )
        ) {
            return null;
        }

        $parameters = $tokens->getNextMeaningfulToken($index);
        \assert(null !== $parameters);

        if ($tokens[$parameters]->isGivenKind(CT::T_RETURN_REF)) {
            $parameters = $tokens->getNextMeaningfulToken($parameters);
            \assert(null !== $parameters);
        }

        return $tokens[$parameters]->equals('(') ? $parameters : null;
    }

    private static function physicalLine(Tokens $tokens, int $index, int|null $end = null): string
    {
        return \mb_rtrim(self::linePrefix($tokens, $index) . self::lineSuffix(
            $tokens,
            $index,
            $end,
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

    private static function lineSuffix(Tokens $tokens, int $index, int|null $end): string
    {
        $suffix = '';

        for ($cursor = $index; ($end ?? (\count($tokens) - 1)) >= $cursor; ++$cursor) {
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
}
