<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use Noldors\PhpCsFixer\Internal\Layout;
use PhpCsFixer\FixerDefinition\CodeSample;
use Noldors\PhpCsFixer\Internal\DelimitedList;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use Noldors\PhpCsFixer\Internal\AbstractWrappingFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

final class ArrayWrappingFixer extends AbstractWrappingFixer
{
    public function getName(): string
    {
        return 'Noldors/array_wrapping';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Wrap long and existing multiline array literals with one element per line.',
            [new CodeSample("<?php\n\$values = [1, 2,\n3];\n")],
        );
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_ARRAY, CT::T_ARRAY_BRACKET_OPEN]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $configuration = $this->configuration;

        for ($index = 0; \count($tokens) > $index; ++$index) {
            if (! $tokens[$index]->isGivenKind([\T_ARRAY, CT::T_ARRAY_BRACKET_OPEN])) {
                continue;
            }

            $open = $tokens[$index]->isGivenKind(\T_ARRAY) ? $tokens->getNextMeaningfulToken(
                $index,
            ) : $index;
            \assert(null !== $open);
            $block = Tokens::detectBlockType($tokens[$open]);
            \assert(null !== $block);
            $close = $tokens->findBlockEnd($block['type'], $open);

            if ($tokens->getNextMeaningfulToken($open) === $close) {
                continue;
            }

            if (! $tokens->isPartialCodeMultiline($open, $close)
                && Layout::lineWidth($tokens, $open) <= $configuration['max_line_length']) {
                continue;
            }

            Layout::wrapDelimitedList(
                $tokens,
                $open,
                $close,
                DelimitedList::commas($tokens, $open, $close),
                Layout::indentAt($tokens, $open),
                $this->whitespacesConfig->getIndent(),
                $this->whitespacesConfig->getLineEnding(),
            );
        }
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum physical line width before wrapping array literals.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )
                ->setDefault(100)
                ->getOption(),
        ]);
    }
}
