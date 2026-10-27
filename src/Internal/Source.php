<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use PhpParser\Node;
use PhpParser\Token;
use PhpParser\ParserFactory;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * @internal
 */
final class Source
{
    /**
     * @var array<Node\Stmt>
     */
    public readonly array $statements;

    /**
     * @var array<Token>
     */
    public readonly array $parserTokens;

    /**
     * @var array<int, int>
     */
    private array $starts = [];

    /**
     * @var array<int, int>
     */
    private array $ends = [];

    public function __construct(
        private readonly Tokens $tokens,
    ) {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        $this->statements = $parser->parse($tokens->generateCode()) ?? [];
        $this->parserTokens = $parser->getTokens();
        $offset = 0;

        foreach ($tokens as $index => $token) {
            $length = \mb_strlen($token->getContent(), '8bit');

            if (0 === $length) {
                continue;
            }

            $this->starts[$offset] = $index;
            $offset += $length;
            $this->ends[$offset - 1] = $index;
        }
    }

    public function start(Node $node): int
    {
        return $this->starts[$node->getStartFilePos()];
    }

    public function end(Node $node): int
    {
        return $this->ends[$node->getEndFilePos()];
    }

    public function hasComments(int $start, int $end): bool
    {
        for ($index = $start; $index <= $end; ++$index) {
            if ($this->tokens[$index]->isGivenKind([\T_COMMENT, \T_DOC_COMMENT])) {
                return true;
            }
        }

        return false;
    }
}
