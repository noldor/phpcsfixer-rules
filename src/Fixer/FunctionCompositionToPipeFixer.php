<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Expr\BinaryOp\Pipe;
use Noldors\PhpCsFixer\Internal\Layout;
use Noldors\PhpCsFixer\Internal\Source;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpCsFixer\FixerDefinition\CodeSample;
use Noldors\PhpCsFixer\Internal\DelimitedList;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use Noldors\PhpCsFixer\Internal\AbstractWrappingFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use Noldors\PhpCsFixer\Internal\FunctionCompositionToPipeVisitor;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

final class FunctionCompositionToPipeFixer extends AbstractWrappingFixer
{
    public function getName(): string
    {
        return 'Noldors/function_composition_to_pipe';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Use multiline pipes for known native unary function composition.', [
            new CodeSample("<?php\n\$result = \\array_values(\\array_filter(\$values));\n"),
        ]);
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_STRING, \T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED, \T_PIPE]);
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum first-stage and joined closing-line width.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )->setDefault(120)->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        // Keep token positions stable while moving nested pipe stages later.
        for ($index = \count($tokens) - 1; 0 < $index; --$index) {
            if ($tokens[$index]->isGivenKind(\T_PIPE) && ! $tokens[$index - 1]->isWhitespace()) {
                $tokens->insertAt($index, new Token([\T_WHITESPACE, ' ']));
            }
        }

        $source = new Source($tokens);
        $statements = new NodeTraverser(new CloningVisitor())->traverse($source->statements);
        $visitor = new FunctionCompositionToPipeVisitor($source);
        $statements = new NodeTraverser(
            new NameResolver(null, ['replaceNodes' => false]),
            new ParentConnectingVisitor(),
            $visitor,
        )->traverse($statements);

        if ($visitor->changed) {
            $printer = new Standard([
                'indent' => $this->whitespacesConfig->getIndent(),
                'newline' => $this->whitespacesConfig->getLineEnding(),
            ]);
            $tokens->setCode(
                $printer->printFormatPreserving($statements, $source->statements, $source->parserTokens),
            );
            $source = new Source($tokens);
        }

        $this->formatPipelines($tokens, $source);
    }

    private function formatPipelines(Tokens $tokens, Source $source): void
    {
        // Whitespace edits preserve significant-token ordinals across insertions.
        $positions = $this->significantPositions($tokens);
        $ordinals = \array_flip($positions);
        $events = $this->layoutEvents($tokens, $source, $ordinals);
        \ksort($events);

        foreach ($events as $ordinal => $event) {
            $index = $positions[$ordinal];
            $start = $positions[$event['start']];
            $end = $positions[$event['end']];
            $indent = Layout::indentAt($tokens, $positions[$event['anchor']]);

            if ($event['arguments']) {
                if (Layout::lineWidth($tokens, $start, $end) > $this->configuration['max_line_length']) {
                    Layout::wrapDelimitedList(
                        $tokens,
                        $index,
                        $end,
                        DelimitedList::commas($tokens, $index, $end),
                        $indent,
                        $this->whitespacesConfig->getIndent(),
                        $this->whitespacesConfig->getLineEnding(),
                    );
                    $positions = $this->significantPositions($tokens);
                }

                continue;
            }

            $this->movePipe(
                $tokens,
                $index,
                $end,
                $indent . $this->whitespacesConfig->getIndent(),
            );
        }
    }

    /**
     * @param array<int, int> $ordinals
     *
     * @return array<int, array{arguments: bool, start: int, end: int, anchor: int}>
     */
    private function layoutEvents(Tokens $tokens, Source $source, array $ordinals): array
    {
        $events = [];

        foreach ($this->rootPipes($source) as $pipe) {
            $anchor = $ordinals[$source->start($pipe)];
            $stage = $pipe;

            while ($stage instanceof Pipe) {
                $operator = $tokens->getNextTokenOfKind($source->end($stage->left), [[\T_PIPE]]);
                \assert(null !== $operator);
                $events[$ordinals[$operator]] = [
                    'arguments' => false,
                    'start' => $anchor,
                    'end' => $ordinals[$source->end($stage->right)],
                    'anchor' => $anchor,
                ];
                $stage = $stage->left;
            }

            if ($stage instanceof FuncCall && 1 < \count($stage->args)) {
                $open = $tokens->getNextTokenOfKind($source->end($stage->name), ['(']);
                \assert(null !== $open);
                $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open);
                $events[$ordinals[$open]] = [
                    'arguments' => true,
                    'start' => $ordinals[$source->start($stage)],
                    'end' => $ordinals[$close],
                    'anchor' => $anchor,
                ];
            }
        }

        return $events;
    }

    /**
     * @return array<int, int>
     */
    private function significantPositions(Tokens $tokens): array
    {
        $positions = [];

        foreach ($tokens as $index => $token) {
            if (! $tokens->isEmptyAt($index) && ! $token->isWhitespace()) {
                $positions[] = $index;
            }
        }

        return $positions;
    }

    /**
     * @return list<Pipe>
     */
    private function rootPipes(Source $source): array
    {
        $statements = new NodeTraverser(new ParentConnectingVisitor())->traverse($source->statements);
        $roots = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Pipe::class) as $pipe) {
            $owner = $pipe->getAttribute('parent');

            if (! $owner instanceof Pipe || $owner->left !== $pipe) {
                $roots[] = $pipe;
            }
        }

        return $roots;
    }

    private function movePipe(Tokens $tokens, int $operator, int $end, string $indent): void
    {
        $previous = $tokens->getPrevMeaningfulToken($operator);
        \assert(null !== $previous);
        $nextPipe = $tokens->getNextTokenOfKind($operator, [[\T_PIPE]]);
        $lineEnd = null === $nextPipe ? null : $tokens->getPrevNonWhitespace($nextPipe);
        $join = Layout::onlyClosingLine($tokens, $previous)
            && $tokens->getPrevNonWhitespace($operator) === $previous
            && Layout::joinedLineWidth(
                $tokens,
                $operator,
                $lineEnd,
                ' ',
            ) <= $this->configuration['max_line_length'];
        $newIndent = $join ? Layout::indentAt($tokens, $previous) : $indent;
        Layout::reindent(
            $tokens,
            $operator,
            $end + 1,
            Layout::indentAt($tokens, $operator),
            $newIndent,
        );

        if ($join) {
            $tokens[$operator - 1] = new Token([\T_WHITESPACE, ' ']);

            return;
        }

        Layout::breakBefore(
            $tokens,
            $operator,
            $indent,
            $this->whitespacesConfig->getLineEnding(),
        );
    }
}
