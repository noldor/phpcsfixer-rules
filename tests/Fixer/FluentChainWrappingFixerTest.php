<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests\Fixer;

use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Tests\FixerTestCase;
use Noldors\PhpCsFixer\Fixer\FluentChainWrappingFixer;

final class FluentChainWrappingFixerTest extends FixerTestCase
{
    public function testKeepsAChainAtTheConfiguredWidth(): void
    {
        $input = $this->chainAtWidth(100);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testWrapsAChainOneColumnOverTheConfiguredWidth(): void
    {
        $input = $this->chainAtWidth(101);
        $expected = \str_replace('->second()->third();', "\n    ->second()\n    ->third();", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testSupportsStaticAndNullsafeLinks(): void
    {
        $input = "<?php\nBuilder::query()->where('" . \str_repeat('x', 75) . "')?->first()::finish();\n";
        $expected = \str_replace(
            ['->where(', '?->first()', '::finish()'],
            ["\n    ->where(", "\n    ?->first()", "\n    ::finish()"],
            $input,
        );

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testDoesNotCombineUnrelatedNestedCallsIntoAChain(): void
    {
        $input = "<?php\n\$outer->map(\$inner->value('" . \str_repeat('x', 90) . "'));\n";

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testHandlesNestedChainsIndependently(): void
    {
        $input = "<?php\n\$outer->map(\n    \$inner->first('" . \str_repeat('x', 90) . "')->second()\n)->done();\n";
        $expected = \str_replace(
            ['->second()', ')->done();'],
            ["\n        ->second()", ")\n    ->done();"],
            $input,
        );

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testKeepsPropertyAccessAndClassConstantsOutOfTheCallCount(): void
    {
        $input = "<?php\n\$object->" . \str_repeat('property', 15) . "->name;\n";
        $input .= "SomeClass::class;\nSomeClass::CONSTANT;\n\$object->first(\$value->property);\n";

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testPreservesPropertiesBetweenActualCallLinks(): void
    {
        $input = "<?php\n\$query->first('" . \str_repeat('x', 80) . "')->result->second();\n";
        $expected = \str_replace('->second()', "\n    ->second()", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testKeepsInlineAndLineCommentsInPlace(): void
    {
        $input = "<?php\n\$query->first('" . \str_repeat('x', 80) . "') /* why */ ->second() // next step\n    ->third();\n";
        $expected = \str_replace(' /* why */ ->second()', " /* why */\n    ->second()", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testPreservesAnExistingMultilineChain(): void
    {
        $input = "<?php\n\$query->first()\n    ->second()\n    ->third();\n";

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testCompletesAPartiallyMultilineChain(): void
    {
        $input = "<?php\n\$query->first()\n    ->second()->third();\n";
        $expected = \str_replace('->third()', "\n    ->third()", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testCountsUnicodeCharactersInsteadOfBytes(): void
    {
        $input = $this->chainAtWidth(100, 'я');

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testUsesConfiguredTabsAndCrLf(): void
    {
        $fixer = new FluentChainWrappingFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));
        $input = "<?php\r\n\t\$query->first('" . \str_repeat('x', 80) . "')->second()->third();\r\n";
        $expected = \str_replace('->second()->third();', "\r\n\t\t->second()\r\n\t\t->third();", $input);

        $this->assertFixerOutput($fixer, $input, $expected);
    }

    public function testCountsTabsAtTheWidthBoundary(): void
    {
        $fixer = new FluentChainWrappingFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\n"));
        $input = \str_replace("<?php\n", "<?php\n\t", $this->chainAtWidth(96));

        $this->assertFixerOutput($fixer, $input, $input);

        $input = \str_replace("<?php\n", "<?php\n\t", $this->chainAtWidth(97));
        $expected = \str_replace('->second()->third();', "\n\t\t->second()\n\t\t->third();", $input);

        $this->assertFixerOutput($fixer, $input, $expected);
    }

    public function testDoesNotCountFirstClassCallableReferencesAsMethodCalls(): void
    {
        $input = "<?php\n\$object->method(...)->__invoke('" . \str_repeat('x', 90) . "');\n";

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testDoesNotCountFreeFunctionsOrConstructorsAsMethodCalls(): void
    {
        $input = "<?php\nfactory('" . \str_repeat('x', 90) . "')->method();\n";
        $input .= "(new Builder('" . \str_repeat('x', 90) . "'))->method();\n";

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testReusesTheIndentationOfAnExistingFirstCallContinuation(): void
    {
        foreach (['new Builder()', 'factory()'] as $receiver) {
            foreach (['->', '::'] as $operator) {
                $input = "<?php\n\$result = {$receiver}\n    ->first(){$operator}second();\n";
                $expected = "<?php\n\$result = {$receiver}\n    ->first()\n    {$operator}second();\n";

                $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
            }
        }
    }

    public function testSupportsACustomWidth(): void
    {
        $fixer = new FluentChainWrappingFixer();
        $fixer->configure(['max_line_length' => 30]);
        $input = "<?php\n\$query->first('1234567890')->second();\n";
        $expected = \str_replace('->second()', "\n    ->second()", $input);

        $this->assertFixerOutput($fixer, $input, $expected);
    }

    public function testSupportsReservedStaticMethodNames(): void
    {
        $input = "<?php\nHandler::match('" . \str_repeat('x', 90) . "')->next();\n";
        $expected = \str_replace('->next()', "\n    ->next()", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testDoesNotCountAConstructorUsingAPropertyAsAMethodLink(): void
    {
        $input = "<?php\nnew \$object->className('" . \str_repeat('x', 90) . "')->next();\n";

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $input);
    }

    public function testWrapsRealMethodsAfterAConstructorUsingAProperty(): void
    {
        $input = "<?php\nnew \$object->className('" . \str_repeat('x', 90) . "')->next()->last();\n";
        $expected = \str_replace('->last()', "\n    ->last()", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
    }

    public function testSupportsBracedDynamicMethodNames(): void
    {
        foreach (['Handler::{$method}', '$object->{$method}', '$object?->{$method}'] as $receiver) {
            $input = "<?php\n" . $receiver . "('" . \str_repeat('x', 90) . "')->next();\n";
            $expected = \str_replace('->next()', "\n    ->next()", $input);

            $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);
        }
    }

    public function testPreservesInterpolatedStringContents(): void
    {
        $input = "<?php return \"prefix{\$object->first('" . \str_repeat('x', 90) . "')->second()}suffix\";";
        $expected = \str_replace('->second()', "\n    ->second()", $input);

        $this->assertFixerOutput(new FluentChainWrappingFixer(), $input, $expected);

        $object = new class () {
            public function first(string $payload): static
            {
                return $this;
            }

            public function second(): string
            {
                return 'OK';
            }
        };

        $this->assertSame('prefixOKsuffix', eval(\mb_substr($expected, 5)));
    }

    private function chainAtWidth(int $width, string $character = 'x'): string
    {
        $prefix = "\$query->first('";
        $suffix = "')->second()->third();";

        return "<?php\n" . $prefix . \str_repeat($character, $width - \mb_strlen($prefix . $suffix)) . $suffix . "\n";
    }
}
