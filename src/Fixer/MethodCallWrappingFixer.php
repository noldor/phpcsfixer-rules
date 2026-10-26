<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use LogicException;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Tokenizer\Tokens;
use Noldors\PhpCsFixer\Internal\Layout;
use PhpCsFixer\FixerDefinition\CodeSample;
use Noldors\PhpCsFixer\Internal\CallSyntax;
use PhpCsFixer\Fixer\ConfigurableFixerTrait;
use Noldors\PhpCsFixer\Internal\DelimitedList;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

/**
 * @implements ConfigurableFixerInterface<array<string, mixed>, array{max_line_length: positive-int, max_single_line_arguments: positive-int}>
 */
final class MethodCallWrappingFixer extends AbstractFixer implements ConfigurableFixerInterface, WhitespacesAwareFixerInterface
{
    /** @use ConfigurableFixerTrait<array<string, mixed>, array{max_line_length: positive-int, max_single_line_arguments: positive-int}> */
    use ConfigurableFixerTrait;

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
        $configuration = $this->configuration ?? throw new LogicException('The fixer must be configured before use.');

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
            && ! $tokens->isPartialCodeMultiline($open, $close)
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
