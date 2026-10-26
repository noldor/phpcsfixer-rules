<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use PhpCsFixer\Tokenizer\Tokens;

final class DelimitedList
{
    /**
     * @return list<int>
     */
    public static function commas(Tokens $tokens, int $open, int $close): array
    {
        $commas = [];

        for ($index = $open + 1; $index < $close; ++$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);

                continue;
            }

            $previous = $tokens->getPrevMeaningfulToken($index);

            if ($tokens[$index]->isGivenKind(\T_CLASS)
                && null !== $previous && ! $tokens[$previous]->isGivenKind(\T_DOUBLE_COLON)) {
                $index = self::anonymousClassEnd($tokens, $index);

                continue;
            }

            if ($tokens[$index]->equals(',')) {
                $commas[] = $index;
            }
        }

        return $commas;
    }

    private static function anonymousClassEnd(Tokens $tokens, int $index): int
    {
        while (\count($tokens) > ++$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null === $block || ! $block['isStart']) {
                continue;
            }

            $end = $tokens->findBlockEnd($block['type'], $index);

            if ($tokens[$index]->equals('{')) {
                return $end;
            }
            $index = $end;
        }

        return $index;
    }
}
