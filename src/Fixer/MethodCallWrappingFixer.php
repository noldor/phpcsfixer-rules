<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use Noldors\PhpCsFixer\Internal\Layout;
use PhpCsFixer\FixerDefinition\CodeSample;
use Noldors\PhpCsFixer\Internal\CallSyntax;
use Noldors\PhpCsFixer\Internal\DelimitedList;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use Noldors\PhpCsFixer\Internal\AbstractWrappingFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

final class MethodCallWrappingFixer extends AbstractWrappingFixer
{
    public function getName(): string
    {
        return 'Noldors/method_call_wrapping';
    }

    public function getPriority(): int
    {
        return 70;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Wrap method call arguments when their count, physical line width or existing layout requires multiple lines.',
            [new CodeSample("<?php\n\$service->run(1, 2, 3, 4);\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound(
            [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
        );
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum physical line width for a single-line method call.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )
                ->setDefault(100)
                ->getOption(),
            new FixerOptionBuilder('max_single_line_arguments', 'Maximum number of syntactic arguments allowed on one line.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )
                ->setDefault(3)
                ->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $configuration = $this->configuration;

        for ($open = \count($tokens) - 1; 0 < $open; --$open) {
            if (! $tokens[$open]->equals('(') || ! $this->isMethodCall($tokens, $open)) {
                continue;
            }

            $this->wrapCall(
                $tokens,
                $open,
                $configuration['max_line_length'],
                $configuration['max_single_line_arguments'],
            );
        }
    }

    private function wrapCall(Tokens $tokens, int $open, int $maxWidth, int $maxArguments): void
    {
        $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open);
        $first = $tokens->getNextMeaningfulToken($open);

        if ($first === $close || (null !== $first && $tokens[$first]->isGivenKind(
            [\T_ELLIPSIS, CT::T_FIRST_CLASS_CALLABLE],
        ) && $tokens->getNextMeaningfulToken(
            $first,
        ) === $close)) {
            return;
        }

        $commas = DelimitedList::commas($tokens, $open, $close);
        $trailingComma = [] !== $commas && $tokens->getNextMeaningfulToken(
            $commas[\array_key_last($commas)],
        ) === $close;
        $argumentCount = \count($commas) + ($trailingComma ? 0 : 1);

        if ($argumentCount <= $maxArguments
            && Layout::lineWidth($tokens, $open) <= $maxWidth
            && ! $this->isArgumentListMultiline($tokens, $open, $close)
        ) {
            return;
        }

        Layout::wrapDelimitedList(
            $tokens,
            $open,
            $close,
            $commas,
            Layout::indentAt($tokens, $open),
            $this->whitespacesConfig->getIndent(),
            $this->whitespacesConfig->getLineEnding(),
        );
    }

    private function isArgumentListMultiline(Tokens $tokens, int $open, int $close): bool
    {
        for ($index = $open + 1; $index < $close; ++$index) {
            $array = $this->arrayLiteralStart($tokens, $index);

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
                ? $this->closureParametersStart($tokens, $index)
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

    private function arrayLiteralStart(Tokens $tokens, int $index): int|null
    {
        if ($tokens[$index]->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
            return $index;
        }

        if (! $tokens[$index]->isGivenKind(\T_ARRAY)) {
            return null;
        }

        $previous = $tokens->getPrevMeaningfulToken($index);

        if (null !== $previous && $tokens[$previous]->isGivenKind(
            [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
        )) {
            return null;
        }

        return $tokens->getNextMeaningfulToken($index);
    }

    private function closureParametersStart(Tokens $tokens, int $index): int|null
    {
        $previous = $tokens->getPrevMeaningfulToken($index);

        if (null !== $previous && $tokens[$previous]->isGivenKind(
            [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
        )) {
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

    private function isMethodCall(Tokens $tokens, int $open): bool
    {
        $name = $tokens->getPrevMeaningfulToken($open);

        if (null === $name) {
            return false;
        }

        if ($tokens[$name]->isGivenKind(CT::T_DYNAMIC_PROP_BRACE_CLOSE)) {
            $name = $tokens->findBlockStart(Tokens::BLOCK_TYPE_DYNAMIC_PROP_BRACE, $name);
        } elseif ($tokens[$name]->equals('}')) {
            $name = $tokens->findBlockStart(Tokens::BLOCK_TYPE_BRACE, $name);
        } elseif (! $tokens[$name]->isGivenKind(
            [\T_STRING, \T_VARIABLE],
        ) && ! $tokens[$name]->isKeyword()) {
            return false;
        }

        $operator = $tokens->getPrevMeaningfulToken($name);

        if (null === $operator || ! $tokens[$operator]->isGivenKind(
            [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON],
        )) {
            return false;
        }

        $receiver = $tokens->getPrevMeaningfulToken($operator);

        return null !== $receiver && ! CallSyntax::isConstructorReceiver($tokens, $receiver);
    }
}
