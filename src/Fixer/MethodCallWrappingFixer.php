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
            'Wrap method arguments at their own expression width, with a separate limit for a single argument.',
            [new CodeSample("<?php\n\$service->run(1, 2, 3, 4);\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON]);
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum width of the method expression, including indentation.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )->setDefault(120)->getOption(),
            new FixerOptionBuilder('single_argument_max_length', 'Maximum source width of a single inline argument.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )->setDefault(40)->getOption(),
            new FixerOptionBuilder('max_single_line_arguments', 'Maximum number of syntactic arguments allowed on one line.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )->setDefault(3)->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $starts = CallSyntax::methodStarts($tokens);

        for ($open = \count($tokens) - 1; 0 < $open; --$open) {
            if (! $tokens[$open]->equals('(') || ! isset($starts[$open])) {
                continue;
            }

            $this->wrapCall(
                $tokens,
                $open,
                $starts[$open],
            );
        }
    }

    private function wrapCall(Tokens $tokens, int $open, int $start): void
    {
        $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open);
        $first = $tokens->getNextMeaningfulToken($open);

        if ($first === $close
            || (null !== $first && $tokens[$first]->isGivenKind([\T_ELLIPSIS, CT::T_FIRST_CLASS_CALLABLE]) && $tokens->getNextMeaningfulToken($first) === $close)
        ) {
            return;
        }

        $commas = DelimitedList::commas($tokens, $open, $close);
        $trailingComma = [] !== $commas && $tokens->getNextMeaningfulToken($commas[\array_key_last($commas)]) === $close;
        $argumentCount = \count($commas) + ($trailingComma ? 0 : 1);
        $last = $tokens->getPrevMeaningfulToken(
            $trailingComma ? $commas[\array_key_last($commas)] : $close,
        );
        \assert(null !== $first && null !== $last);
        $multiline = Layout::isMultiline($tokens, $first, $last);

        if (1 === $argumentCount
            && ! $multiline
            && $this->fitsSingleArgument(
                $tokens,
                $start,
                $open,
                $first,
                $last,
            )
        ) {
            $this->compactSingleArgument(
                $tokens,
                $open,
                $close,
                $first,
                $last,
                $start,
            );

            return;
        }

        if (1 < $argumentCount
            && $argumentCount <= $this->configuration['max_single_line_arguments']
            && Layout::spanWidth(
                $tokens,
                $start,
                $close,
                Layout::indentAt($tokens, $start),
            ) <= $this->configuration['max_line_length']
            && ! Layout::isMultiline($tokens, $open + 1, $close - 1)
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

    private function fitsSingleArgument(Tokens $tokens, int $start, int $open, int $first, int $last): bool
    {
        $argumentWidth = Layout::spanWidth(
            $tokens,
            $first,
            $last,
            '',
        );

        if ($argumentWidth <= $this->configuration['single_argument_max_length']) {
            return true;
        }

        if (! CallSyntax::isArrayArgument($tokens, $first, $last)) {
            return false;
        }

        $next = $tokens->getNextMeaningfulToken($last);
        $suffix = null !== $next && $tokens[$next]->equals(',')
            ? $tokens->generatePartialCode($last + 1, $next) . ')'
            : ')';
        $prefix = Layout::indentAt($tokens, $start) . Layout::firstLine($tokens, $start, $open);

        return Layout::spanWidth(
            $tokens,
            $first,
            $last,
            $prefix,
            $suffix,
        ) <= $this->configuration['max_line_length'];
    }

    private function compactSingleArgument(Tokens $tokens, int $open, int $close, int $first, int $last, int $start): void
    {
        for ($index = $open + 1; $index < $close; ++$index) {
            if (($index < $first || $last < $index)
                && $tokens[$index]->isGivenKind([\T_COMMENT, \T_DOC_COMMENT])
            ) {
                return;
            }
        }

        Layout::reindent(
            $tokens,
            $first,
            $last + 1,
            Layout::indentAt($tokens, $first),
            Layout::indentAt($tokens, $start),
        );
        Layout::joinBefore($tokens, $close);
        Layout::joinBefore($tokens, $first);
    }
}
