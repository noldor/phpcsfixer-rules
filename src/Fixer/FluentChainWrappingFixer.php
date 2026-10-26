<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use Noldors\PhpCsFixer\Internal\Layout;
use PhpCsFixer\FixerDefinition\CodeSample;
use Noldors\PhpCsFixer\Internal\CallSyntax;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use Noldors\PhpCsFixer\Internal\AbstractWrappingFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

final class FluentChainWrappingFixer extends AbstractWrappingFixer
{
    public function getName(): string
    {
        return 'Noldors/fluent_chain_wrapping';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Wrap long fluent chains with each successive method call on its own line.', [
            new CodeSample("<?php\n\$query->first()\n    ->second()->third();\n"),
        ]);
    }

    /**
     * Run after native double-colon spacing and method-chain indentation.
     */
    public function getPriority(): int
    {
        return -1;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([...Token::getObjectOperatorKinds(), \T_DOUBLE_COLON]);
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum width of an inline fluent chain.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )
                ->setDefault(100)
                ->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $breaks = [];

        foreach ($this->chains($tokens) as $chain) {
            if (! $this->shouldWrap($tokens, $chain)) {
                continue;
            }

            $firstCall = $chain['calls'][0];
            $indent = Layout::indentAt($tokens, $firstCall);

            if (! $this->startsContinuationLine($tokens, $firstCall)) {
                $indent .= $this->whitespacesConfig->getIndent();
            }

            foreach (\array_slice($chain['calls'], 1) as $index) {
                $breaks[$index] = $indent;
            }
        }

        \krsort($breaks);

        foreach ($breaks as $index => $indent) {
            Layout::breakBefore(
                $tokens,
                $index,
                $indent,
                $this->whitespacesConfig->getLineEnding(),
            );
        }
    }

    /**
     * @return list<array{calls: non-empty-list<int>, end: int}>
     */
    private function chains(Tokens $tokens): array
    {
        $chains = [];
        $paths = [];

        foreach ($tokens as $index => $token) {
            $link = $this->chainLink($tokens, $index);

            if (null === $link) {
                continue;
            }

            $previous = $tokens->getPrevMeaningfulToken($index);
            $chainIndex = null === $previous ? null : ($paths[$previous] ?? null);

            if (! $link['call']) {
                if (null !== $chainIndex) {
                    $paths[$link['end']] = $chainIndex;
                }

                continue;
            }

            if (null === $chainIndex) {
                $chainIndex = \count($chains);
                $chains[] = ['calls' => [$index], 'end' => $link['end']];
            } else {
                $chains[$chainIndex]['calls'][] = $index;
                $chains[$chainIndex]['end'] = $link['end'];
            }

            $paths[$link['end']] = $chainIndex;
        }

        return $chains;
    }

    /**
     * @return array{end: int, call: bool}|null
     */
    private function chainLink(Tokens $tokens, int $index): array|null
    {
        if (! $tokens[$index]->isObjectOperator() && ! $tokens[$index]->isGivenKind(
            \T_DOUBLE_COLON,
        )) {
            return null;
        }

        $member = $tokens->getNextMeaningfulToken($index);

        if (null === $member) {
            return null;
        }

        if ($tokens[$member]->isGivenKind(CT::T_DYNAMIC_PROP_BRACE_OPEN)) {
            $member = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_DYNAMIC_PROP_BRACE, $member);
        } elseif ($tokens[$member]->equals('{')) {
            $member = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $member);
        } elseif (! $tokens[$member]->isGivenKind(
            [\T_STRING, \T_VARIABLE],
        ) && ! $tokens[$member]->isKeyword()) {
            return null;
        }

        $open = $tokens->getNextMeaningfulToken($member);

        if (null === $open || ! $tokens[$open]->equals('(')) {
            return ['end' => $member, 'call' => false];
        }

        $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open);
        $receiver = $tokens->getPrevMeaningfulToken($index);

        if (null !== $receiver && CallSyntax::isConstructorReceiver($tokens, $receiver)) {
            return ['end' => $close, 'call' => false];
        }

        return $this->isCallableReference(
            $tokens,
            $open,
            $close,
        ) ? null : ['end' => $close, 'call' => true];
    }

    private function isCallableReference(Tokens $tokens, int $open, int $close): bool
    {
        $argument = $tokens->getNextMeaningfulToken($open);

        return null !== $argument
            && $tokens[$argument]->isGivenKind(CT::T_FIRST_CLASS_CALLABLE)
            && $tokens->getNextMeaningfulToken($argument) === $close;
    }

    /**
     * @param array{calls: non-empty-list<int>, end: int} $chain
     */
    private function shouldWrap(Tokens $tokens, array $chain): bool
    {
        if (\count($chain['calls']) < 2) {
            return false;
        }

        if ($this->startsContinuationLine($tokens, $chain['calls'][0])) {
            return true;
        }

        $code = $tokens->generatePartialCode($chain['calls'][0], $chain['end']);

        if (\str_contains($code, "\n") || \str_contains($code, "\r")) {
            return true;
        }

        return Layout::lineWidth(
            $tokens,
            $chain['calls'][0],
        ) > $this->configuration['max_line_length'];
    }

    private function startsContinuationLine(Tokens $tokens, int $index): bool
    {
        for ($previous = $index - 1; 0 <= $previous; --$previous) {
            if ($tokens->isEmptyAt($previous)) {
                continue;
            }

            return $tokens[$previous]->isWhitespace()
                && 1 === \preg_match('/[\\r\\n][\\t ]*$/D', $tokens[$previous]->getContent());
        }

        return false;
    }
}
