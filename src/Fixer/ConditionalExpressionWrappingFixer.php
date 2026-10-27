<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpParser\Node\Expr\Ternary;
use Noldors\PhpCsFixer\Internal\Layout;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use Noldors\PhpCsFixer\Internal\AbstractWrappingFixer;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolver;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

final class ConditionalExpressionWrappingFixer extends AbstractWrappingFixer
{
    public function getName(): string
    {
        return 'Noldors/conditional_expression_wrapping';
    }

    public function getPriority(): int
    {
        return 90;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Wrap long conditions and full ternaries at their operators.', [
            new CodeSample("<?php\nif (\$first\n    && \$second || \$third) {}\n"),
        ]);
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_IF, \T_ELSEIF, \T_WHILE, '?']);
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum width before wrapping a conditional expression.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )->setDefault(120)->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $statements = new ParserFactory()->createForNewestSupportedVersion()
            ->parse($tokens->generateCode());
        // Give top-level siblings the same parent relationship as statements inside a block.
        $statements = new NodeTraverser(new ParentConnectingVisitor())->traverse([
            new Node\Stmt\Block($statements ?? []),
        ]);
        $nodes = new NodeFinder()->find(
            $statements,
            static fn (Node $node): bool => $node instanceof Node\Stmt\If_
                || $node instanceof Node\Stmt\ElseIf_
                || $node instanceof Node\Stmt\While_
                || $node instanceof Node\Stmt\Do_
                || $node instanceof Ternary,
        );
        $positions = $this->tokenPositions($tokens);
        $breaks = [];
        $plans = [];

        foreach ($nodes as $node) {
            $plan = $node instanceof Ternary
                ? $this->ternaryBreaks(
                    $tokens,
                    $node,
                    $positions,
                    $plans,
                )
                : $this->conditionBreaks(
                    $tokens,
                    $node,
                    $positions,
                    $plans,
                );

            $plans[\spl_object_id($node)] = $plan;

            foreach ($plan as $index => $indent) {
                $breaks[$index] = $indent;
            }
        }

        \krsort($breaks);

        foreach ($breaks as $index => $indent) {
            if (! $tokens[$index]->equals(')')
                && $tokens[$index + 1]->isWhitespace()
                && \str_contains($tokens[$index + 1]->getContent(), "\n")
            ) {
                $tokens[$index + 1] = new Token([\T_WHITESPACE, ' ']);
            }

            Layout::breakBefore(
                $tokens,
                $index,
                $indent,
                $this->whitespacesConfig->getLineEnding(),
            );
        }
    }

    /**
     * @return array{starts: array<int, int>, ends: array<int, int>}
     */
    private function tokenPositions(Tokens $tokens): array
    {
        $positions = ['starts' => [], 'ends' => []];
        $offset = 0;

        foreach ($tokens as $index => $token) {
            $length = \mb_strlen($token->getContent(), '8bit');

            if (0 === $length) {
                continue;
            }

            $positions['starts'][$offset] = $index;
            $offset += $length;
            $positions['ends'][$offset - 1] = $index;
        }

        return $positions;
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     * @param array<int, array<int, string>> $plans
     *
     * @return array<int, string>
     */
    private function conditionBreaks(Tokens $tokens, Node $node, array $positions, array $plans): array
    {
        \assert($node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_);
        $keyword = $positions['starts'][$node->getStartFilePos()];

        if ($node instanceof Node\Stmt\Do_) {
            $keyword = $tokens->getPrevTokenOfKind(
                $positions['starts'][$node->cond->getStartFilePos()],
                [
                    [\T_WHILE],
                ],
            );
            \assert(null !== $keyword);
        }

        $open = $tokens->getNextTokenOfKind($keyword, ['(']);
        \assert(null !== $open);
        $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open);
        $operators = $this->booleanOperators(
            $tokens,
            $node->cond,
            $positions,
            $open + 1,
            $close - 1,
        );
        $indent = $this->projectedIndent(
            $tokens,
            $node,
            $positions,
            $keyword,
            $plans,
        );
        $start = $keyword;
        $end = $close;

        if ([] === $operators) {
            if (! $node->cond instanceof Ternary || null === $node->cond->if) {
                return [];
            }

            $start = $positions['starts'][$node->cond->getStartFilePos()];
            $end = $positions['ends'][$node->cond->getEndFilePos()];
        }

        if (! $this->shouldWrap(
            $tokens,
            $start,
            $end,
            $indent,
        )
        ) {
            return [];
        }

        $breaks = \array_fill_keys($operators, $indent . $this->whitespacesConfig->getIndent());
        $breaks[$close] = $indent;

        return $breaks;
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     * @param array<int, array<int, string>> $plans
     *
     * @return array<int, string>
     */
    private function ternaryBreaks(Tokens $tokens, Ternary $node, array $positions, array $plans): array
    {
        if (null === $node->if) {
            return [];
        }

        $start = $positions['starts'][$node->getStartFilePos()];
        $end = $positions['ends'][$node->getEndFilePos()];
        $expressionStart = $start;
        $baseIndent = $this->projectedIndent(
            $tokens,
            $node,
            $positions,
            $start,
            $plans,
        );
        $owner = $node->getAttribute('parent');

        if ($owner instanceof Node\Stmt\Return_
            || $owner instanceof Node\Stmt\Expression
            || (($owner instanceof Node\Expr\Assign || $owner instanceof Node\Expr\AssignOp) && $owner->getAttribute('parent') instanceof Node\Stmt\Expression)
        ) {
            $start = $positions['starts'][$owner->getStartFilePos()];
        }

        if (! $this->shouldWrap(
            $tokens,
            $start,
            $end,
            $baseIndent,
        )) {
            return [];
        }

        $conditionEnd = $positions['ends'][$node->cond->getEndFilePos()];
        $question = $tokens->getNextTokenOfKind($conditionEnd, ['?']);
        $colon = $tokens->getNextTokenOfKind($positions['ends'][$node->if->getEndFilePos()], [':']);
        \assert(null !== $question && null !== $colon);
        $indent = $baseIndent . $this->whitespacesConfig->getIndent();
        $operators = $this->booleanOperators(
            $tokens,
            $node->cond,
            $positions,
            $expressionStart,
            $question - 1,
        );

        if (! $this->shouldWrap(
            $tokens,
            $start,
            $conditionEnd,
            $baseIndent,
        )) {
            $operators = [];
        }

        $breaks = \array_fill_keys($operators, $indent);
        $branchIndent = [] === $operators
            ? $indent
            : $indent . $this->whitespacesConfig->getIndent();
        $breaks[$question] = $branchIndent;
        $breaks[$colon] = $branchIndent;

        return $breaks;
    }

    private function shouldWrap(Tokens $tokens, int $start, int $end, string $indent): bool
    {
        $next = $tokens->getNextMeaningfulToken($end);
        $end = null !== $next && $tokens[$next]->equalsAny([';', '{']) ? $next : $end;

        return Layout::isMultiline($tokens, $start, $end)
            || Layout::spanWidth(
                $tokens,
                $start,
                $end,
                $indent,
            ) > $this->configuration['max_line_length'];
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     * @param array<int, array<int, string>> $plans
     */
    private function projectedIndent(Tokens $tokens, Node $node, array $positions, int $index, array $plans): string
    {
        $owner = $node->getAttribute('parent');

        while ($owner instanceof Node) {
            if (isset($plans[\spl_object_id($owner)])) {
                $indent = $this->indentFromPlan(
                    $tokens,
                    $index,
                    $plans[\spl_object_id($owner)],
                );

                if (null !== $indent) {
                    return $indent;
                }
            }

            $owner = $owner->getAttribute('parent');
        }

        return $this->sourceIndent(
            $tokens,
            $node,
            $positions,
            $index,
        );
    }

    /**
     * @param array<int, string> $breaks
     */
    private function indentFromPlan(Tokens $tokens, int $index, array $breaks): string|null
    {
        $nearest = null;

        foreach ($breaks as $position => $indent) {
            if ($position <= $index && (null === $nearest || $position > $nearest)) {
                $nearest = $position;
            }
        }

        return null !== $nearest && ! $tokens->isPartialCodeMultiline($nearest, $index)
            ? $breaks[$nearest]
            : null;
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     */
    private function sourceIndent(Tokens $tokens, Node $node, array $positions, int $index): string
    {
        $owner = $node->getAttribute('parent');

        while ($owner instanceof Node) {
            $index = $this->inlineSiblingStart(
                $tokens,
                $owner,
                $positions,
                $index,
            ) ?? $index;
            $owner = $owner->getAttribute('parent');
        }

        return Layout::indentAt($tokens, $index);
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     */
    private function inlineSiblingStart(Tokens $tokens, Node $owner, array $positions, int $index): int|null
    {
        $children = $this->sourceChildren($owner, $positions);
        $firstInList = [];
        $previous = null;

        foreach ($children as $child) {
            if (null !== $child['list']) {
                $firstInList[$child['list']] ??= $child['start'];
            }

            if ($child['end'] < $index) {
                $previous = $child;

                continue;
            }

            if ($child['start'] > $index
                || null === $previous
                || $tokens->isPartialCodeMultiline($previous['end'] + 1, $index)
            ) {
                return null;
            }

            return null !== $child['list'] && $child['list'] === $previous['list']
                ? $firstInList[$child['list']]
                : ($positions['starts'][$owner->getStartFilePos()] ?? null);
        }

        return null;
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     *
     * @return list<array{start: int, end: int, list: string|null}>
     */
    private function sourceChildren(Node $owner, array $positions): array
    {
        $children = [];

        foreach ($owner->getSubNodeNames() as $name) {
            $value = $owner->{$name};
            $list = \is_array($value) ? $name : null;

            foreach (null === $list ? [$value] : $value as $child) {
                if (! $child instanceof Node
                    || ! isset($positions['starts'][$child->getStartFilePos()], $positions['ends'][$child->getEndFilePos()])
                ) {
                    continue;
                }

                $children[] = [
                    'start' => $positions['starts'][$child->getStartFilePos()],
                    'end' => $positions['ends'][$child->getEndFilePos()],
                    'list' => $list,
                ];
            }
        }

        \usort($children, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return $children;
    }

    /**
     * @param array{starts: array<int, int>, ends: array<int, int>} $positions
     *
     * @return list<int>
     */
    private function booleanOperators(Tokens $tokens, Node\Expr $expression, array $positions, int $start, int $end): array
    {
        $boundaries = [];
        $pending = [$expression];

        while ([] !== $pending) {
            $node = \array_pop($pending);

            if (! $node instanceof Node\Expr\BinaryOp\BooleanAnd
                && ! $node instanceof Node\Expr\BinaryOp\BooleanOr
                && ! $node instanceof Node\Expr\BinaryOp\LogicalAnd
                && ! $node instanceof Node\Expr\BinaryOp\LogicalOr
                && ! $node instanceof Node\Expr\BinaryOp\LogicalXor
            ) {
                continue;
            }

            $operator = $tokens->getNextTokenOfKind(
                $positions['ends'][$node->left->getEndFilePos()],
                [
                    [\T_BOOLEAN_AND],
                    [\T_BOOLEAN_OR],
                    [\T_LOGICAL_AND],
                    [\T_LOGICAL_OR],
                    [\T_LOGICAL_XOR],
                ],
            );
            \assert(null !== $operator);
            $boundaries[$operator] = true;
            $pending[] = $node->left;
            $pending[] = $node->right;
        }

        $operators = [];

        for ($index = $start; $index <= $end; ++$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);
            } elseif (isset($boundaries[$index])) {
                $operators[] = $index;
            }
        }

        return $operators;
    }
}
