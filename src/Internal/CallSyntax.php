<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @internal
 */
final class CallSyntax
{
    /**
     * @return array<int, int> call opening parentheses mapped to their expression starts
     */
    public static function methodStarts(Tokens $tokens): array
    {
        $source = new Source($tokens);
        $calls = new NodeFinder()->find(
            $source->statements,
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall,
        );
        $starts = [];

        foreach ($calls as $call) {
            \assert($call instanceof Node\Expr\MethodCall
                || $call instanceof Node\Expr\NullsafeMethodCall
                || $call instanceof Node\Expr\StaticCall);

            if ($call->isFirstClassCallable()) {
                continue;
            }

            $open = $tokens->getNextTokenOfKind($source->end($call->name), ['(']);
            \assert(null !== $open);
            $starts[$open] = $source->start($call);
        }

        return $starts;
    }

    public static function isConstructorReceiver(Tokens $tokens, int $receiver): bool
    {
        if ($tokens[$receiver]->equals(']')) {
            $open = $tokens->findBlockStart(Tokens::BLOCK_TYPE_INDEX_BRACKET, $receiver);
            $previous = $tokens->getPrevMeaningfulToken($open);

            return null !== $previous && self::isConstructorReceiver($tokens, $previous);
        }

        if ($tokens[$receiver]->isGivenKind(CT::T_DYNAMIC_PROP_BRACE_CLOSE)) {
            $receiver = $tokens->findBlockStart(Tokens::BLOCK_TYPE_DYNAMIC_PROP_BRACE, $receiver);
        }

        $previous = $tokens->getPrevMeaningfulToken($receiver);

        if (null === $previous) {
            return false;
        }

        if ($tokens[$previous]->isGivenKind(\T_NEW)) {
            return true;
        }

        if (! $tokens[$previous]->isGivenKind([
            \T_OBJECT_OPERATOR,
            \T_NULLSAFE_OBJECT_OPERATOR,
            \T_DOUBLE_COLON,
        ], )) {
            return false;
        }

        $receiver = $tokens->getPrevMeaningfulToken($previous);

        return null !== $receiver && self::isConstructorReceiver($tokens, $receiver);
    }
}
