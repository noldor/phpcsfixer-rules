<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Fixer;

use SplFileInfo;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
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
        return $tokens->isAnyTokenKindsFound(
            [\T_STRING, \T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED, \T_PIPE],
        );
    }

    protected function createConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return new FixerConfigurationResolver([
            new FixerOptionBuilder('max_line_length', 'Maximum first-stage width before wrapping its arguments.')
                ->setAllowedTypes(['int'])
                ->setAllowedValues(
                    [static fn (mixed $value): bool => \is_int($value) && 0 < $value],
                )->setDefault(120)->getOption(),
        ]);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
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
        $statements = new NodeTraverser(new ParentConnectingVisitor())->traverse($source->statements);
        $pipes = new NodeFinder()->findInstanceOf($statements, Pipe::class);
        $breaks = [];
        $calls = [];

        foreach ($pipes as $pipe) {
            $owner = $pipe->getAttribute('parent');

            if ($owner instanceof Pipe && $owner->left === $pipe) {
                continue;
            }

            $indent = Layout::indentAt($tokens, $source->start($pipe));
            $stage = $pipe;

            while ($stage instanceof Pipe) {
                $operator = $tokens->getNextTokenOfKind($source->end($stage->left), [[\T_PIPE]]);
                \assert(null !== $operator);
                $breaks[$operator] = $indent . $this->whitespacesConfig->getIndent();
                $stage = $stage->left;
            }

            if ($stage instanceof FuncCall
                && 1 < \count($stage->args)
                && Layout::lineWidth($tokens, $source->start($stage), $source->end($stage)) > $this->configuration['max_line_length']
            ) {
                $open = $tokens->getNextTokenOfKind($source->end($stage->name), ['(']);
                \assert(null !== $open);
                $calls[$open] = $indent;
            }
        }

        $positions = \array_unique([...\array_keys($breaks), ...\array_keys($calls)]);
        \rsort($positions);

        foreach ($positions as $position) {
            if (isset($breaks[$position])) {
                Layout::breakBefore(
                    $tokens,
                    $position,
                    $breaks[$position],
                    $this->whitespacesConfig->getLineEnding(),
                );

                continue;
            }

            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $position);
            Layout::wrapDelimitedList(
                $tokens,
                $position,
                $close,
                DelimitedList::commas($tokens, $position, $close),
                $calls[$position],
                $this->whitespacesConfig->getIndent(),
                $this->whitespacesConfig->getLineEnding(),
            );
        }
    }
}
