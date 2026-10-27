<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\BinaryOp\Pipe;

/**
 * @internal
 */
final class FunctionCompositionToPipeVisitor extends NodeVisitorAbstract
{
    private const array FUNCTIONS = [
        'array_filter',
        'array_flip',
        'array_is_list',
        'array_keys',
        'array_product',
        'array_reverse',
        'array_sum',
        'array_unique',
        'array_values',
        'count',
        'ltrim',
        'mb_strlen',
        'mb_strtolower',
        'mb_strtoupper',
        'rtrim',
        'strlen',
        'strtolower',
        'strtoupper',
        'trim',
    ];

    public bool $changed = false;

    public function __construct(
        private readonly Source $source,
    ) {}

    public function leaveNode(Node $node): Node|null
    {
        if (! $node instanceof FuncCall || ! $node->name instanceof Node\Name || 1 !== \count($node->args)) {
            return null;
        }

        $argument = $node->args[0];
        $resolved = $node->name instanceof Node\Name\FullyQualified
            ? $node->name
            : $node->name->getAttribute('resolvedName');

        if (! $argument instanceof Node\Arg
            || null !== $argument->name
            || $argument->unpack
            || $argument->byRef
            || (! $argument->value instanceof FuncCall && ! $argument->value instanceof Pipe)
            || ! $resolved instanceof Node\Name\FullyQualified
            || ! \in_array(\mb_strtolower($resolved->toString()), self::FUNCTIONS, true)
            || $this->inReferenceContext($node)
        ) {
            return null;
        }

        if ($this->source->hasComments($this->source->start($node), $this->source->start($argument->value) - 1)
            || $this->source->hasComments($this->source->end($argument->value) + 1, $this->source->end($node))
        ) {
            return null;
        }

        $this->changed = true;
        $attributes = $node->getAttributes();
        unset($attributes['origNode']);

        return new Pipe(
            $argument->value,
            new FuncCall(clone $node->name, [new Node\VariadicPlaceholder()]),
            $attributes,
        );
    }

    private function inReferenceContext(Node $node): bool
    {
        $owner = $node->getAttribute('parent');

        while ($owner instanceof Node) {
            if ($owner instanceof Node\Expr\AssignRef
                || ($owner instanceof Node\ArrayItem && $owner->byRef)
                || ($owner instanceof Node\Arg && $owner->byRef)
                || ($owner instanceof Node\FunctionLike && $owner->returnsByRef())
            ) {
                return true;
            }

            $owner = $owner->getAttribute('parent');
        }

        return false;
    }
}
