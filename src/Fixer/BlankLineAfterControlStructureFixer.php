<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpCsFixer\Tokenizer\Tokens;
use Noldors\PhpCsFixer\Internal\Layout;
use Noldors\PhpCsFixer\Internal\Source;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use Noldors\PhpCsFixer\Internal\AbstractWhitespaceAwareFixer;

final class BlankLineAfterControlStructureFixer extends AbstractWhitespaceAwareFixer
{
    public function getName(): string
    {
        return 'Noldors/blank_line_after_control_structure';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Separate a completed braced control structure from the following statement by one blank line.', [
            new CodeSample("<?php\nif (\$done) {\n    return;\n}\nnextStep();\n"),
        ]);
    }

    public function getPriority(): int
    {
        // Run after native whitespace cleanup and custom expression wrapping.
        return -30;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([\T_IF, \T_FOR, \T_FOREACH, \T_WHILE, \T_DO, \T_SWITCH, \T_TRY]);
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if (! $this->supports($file) || ! $this->isCandidate($tokens)) {
            return;
        }

        $source = new Source($tokens);
        $ends = [];

        foreach ($this->statementPairs($source->statements) as [$statement, $next]) {
            if (! $next instanceof Stmt\Nop
                && ! $next instanceof Stmt\InlineHTML
                && $this->hasClosingBlock($statement, $source, $tokens)
            ) {
                $ends[] = $source->end($statement);
            }
        }

        // Insertions must not invalidate the remaining source token positions.
        \rsort($ends);

        foreach ($ends as $end) {
            $this->separate($tokens, $end);
        }
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return iterable<array{Stmt, Stmt}>
     */
    private function statementPairs(array $nodes): iterable
    {
        $previous = null;

        foreach ($nodes as $node) {
            if (! $node instanceof Node) {
                continue;
            }

            if ($previous instanceof Stmt && $node instanceof Stmt) {
                yield [$previous, $node];
            }

            $previous = $node;

            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};

                if ($child instanceof Node) {
                    yield from $this->statementPairs([$child]);
                } elseif (\is_array($child)) {
                    yield from $this->statementPairs($child);
                }
            }
        }
    }

    private function hasClosingBlock(Stmt $statement, Source $source, Tokens $tokens): bool
    {
        if ($statement instanceof Stmt\Do_) {
            $open = $tokens->getNextMeaningfulToken($source->start($statement));

            return null !== $open && $tokens[$open]->equals('{');
        }

        $control = $statement instanceof Stmt\If_
            || $statement instanceof Stmt\For_
            || $statement instanceof Stmt\Foreach_
            || $statement instanceof Stmt\While_
            || $statement instanceof Stmt\Switch_
            || $statement instanceof Stmt\TryCatch;

        return $control && $tokens[$source->end($statement)]->equals('}');
    }

    private function separate(Tokens $tokens, int $end): void
    {
        $next = $tokens->getNextNonWhitespace($end);

        // Keep same-line trailing comments attached to the completed structure.
        while (null !== $next
            && $tokens[$next]->isComment()
            && ! $tokens->isPartialCodeMultiline($end + 1, $next - 1)
        ) {
            $end = $next;
            $next = $tokens->getNextNonWhitespace($end);
        }

        $whitespace = $tokens[$end + 1]->isWhitespace() ? $tokens[$end + 1]->getContent() : '';
        $indent = \preg_match('/\\R/', $whitespace)
            ? \preg_replace('/^.*\\R/s', '', $whitespace)
            : Layout::indentAt($tokens, $end);
        $tokens->ensureWhitespaceAtIndex(
            $end + 1,
            0,
            \str_repeat($this->whitespacesConfig->getLineEnding(), 2) . $indent,
        );
    }
}
