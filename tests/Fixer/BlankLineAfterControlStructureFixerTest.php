<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests\Fixer;

use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Tests\FixerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\PhpCsFixer\Fixer\BlankLineAfterControlStructureFixer;

final class BlankLineAfterControlStructureFixerTest extends FixerTestCase
{
    #[DataProvider('provideStatements')]
    public function testStatementSeparation(string $input, string $expected): void
    {
        $this->assertFixerOutput(new BlankLineAfterControlStructureFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideStatements(): iterable
    {
        $structures = [
            'return guard' => 'if ($context->hasHidden("audit.source")) { return; }',
            'throw guard' => 'if (!$valid) { throw new \\RuntimeException(); }',
            'if elseif else chain' => 'if ($a) { first(); } elseif ($b) { second(); } else { third(); }',
            'else if chain' => 'if ($a) {} else if ($b) {}',
            'while' => 'while ($ready) { work(); }',
            'for' => 'for ($i = 0; $i < 3; ++$i) { work(); }',
            'foreach' => 'foreach ($items as $item) { work($item); }',
            'do while' => 'do { work(); } while ($ready);',
            'switch' => 'switch ($value) { case 1: work(); break; default: break; }',
            'try catch' => 'try { work(); } catch (\\Throwable $error) { recover(); }',
            'try finally' => 'try { work(); } finally { cleanup(); }',
            'try catch finally' => 'try { work(); } catch (\\LogicException $error) {} catch (\\Throwable $error) {} finally {}',
        ];

        foreach ($structures as $name => $structure) {
            yield $name => ["<?php\n{$structure}\n\$source = source();", "<?php\n{$structure}\n\n\$source = source();"];

            yield $name . ' before method call' => [
                "<?php\n{$structure}\n\$handler->next();",
                "<?php\n{$structure}\n\n\$handler->next();",
            ];
        }

        yield 'same line' => ['<?php if ($a) {} nextStep();', "<?php if (\$a) {}\n\nnextStep();"];

        yield 'no whitespace before next statement' => [
            '<?php if ($a) {}nextStep();',
            "<?php if (\$a) {}\n\nnextStep();",
        ];

        yield 'redundant empty statement stays intact' => [
            "<?php if (\$a) {}\n;\nnextStep();",
            "<?php if (\$a) {}\n\n;\nnextStep();",
        ];

        yield 'adjacent inline comment' => [
            "<?php if (\$a) {}/* complete */\nnextStep();",
            "<?php if (\$a) {}/* complete */\n\nnextStep();",
        ];

        yield 'collapse extra blank lines' => [
            "<?php if (\$a) {}\n\n\n\nnextStep();",
            "<?php if (\$a) {}\n\nnextStep();",
        ];

        yield 'nested statement list' => [
            "<?php function run() {\n    if (\$a) { return; }\n    nextStep();\n}",
            "<?php function run() {\n    if (\$a) { return; }\n\n    nextStep();\n}",
        ];

        yield 'multiple boundaries and ordinary following while' => [
            "<?php if (\$a) {}\nwhile (\$b) {}\nnextStep();",
            "<?php if (\$a) {}\n\nwhile (\$b) {}\n\nnextStep();",
        ];

        yield 'standalone comments belong to following statement' => [
            "<?php if (\$a) {}\n// Next step\n/* detail */\nnextStep();",
            "<?php if (\$a) {}\n\n// Next step\n/* detail */\nnextStep();",
        ];

        yield 'inline comments stay with completed block' => [
            "<?php if (\$a) {} /* complete */ // done\n// Next step\nnextStep();",
            "<?php if (\$a) {} /* complete */ // done\n\n// Next step\nnextStep();",
        ];

        yield 'multiline trailing comment stays intact' => [
            "<?php if (\$a) {} /* complete\n * detail */\nnextStep();",
            "<?php if (\$a) {} /* complete\n * detail */\n\nnextStep();",
        ];

        yield 'following docblock stays intact' => [
            "<?php if (\$a) {}\n/** @var Handler \$handler */\n\$handler->next();",
            "<?php if (\$a) {}\n\n/** @var Handler \$handler */\n\$handler->next();",
        ];

        yield 'switch case statement list' => [
            "<?php switch (\$a) { case 1:\n    if (\$b) {}\n    nextStep();\n    break;\n}",
            "<?php switch (\$a) { case 1:\n    if (\$b) {}\n\n    nextStep();\n    break;\n}",
        ];

        yield 'nested closure body' => [
            "<?php run(function () { if (\$a) {}\nnextStep(); });",
            "<?php run(function () { if (\$a) {}\n\nnextStep(); });",
        ];

        $unchanged = [
            'already separated' => "<?php if (\$a) {}\n\nnextStep();",
            'end of file' => '<?php if ($a) {}',
            'closing outer block' => "<?php function run() { if (\$a) {}\n}",
            'trailing comment only' => "<?php if (\$a) {}\n// End",
            'case boundary' => '<?php switch ($a) { case 1: if ($b) {} case 2: break; }',
            'unbraced if' => "<?php if (\$a) first();\nnextStep();",
            'unbraced do while' => "<?php do work(); while (\$a);\nnextStep();",
            'alternative syntax' => "<?php if (\$a): first(); endif;\nnextStep();",
            'alternative while' => "<?php while (\$a): work(); endwhile;\nnextStep();",
            'declare block' => "<?php declare(ticks=1) {}\nnextStep();",
            'closure expression' => "<?php \$handler = (function () {})\n();",
            'anonymous class expression' => "<?php \$handler = new class {}\n->next();",
            'match expression' => "<?php \$result = match (\$a) { default => 1 }\n+ 2;",
            'function and class declarations' => "<?php function run() {}\nclass Handler {}\nnextStep();",
            'if continuations with comments' => "<?php if (\$a) {} /* next */ elseif (\$b) {}\nelse {}",
            'try continuations with comments' => '<?php try {} /* next */ catch (\\Throwable $e) {} finally {}',
            'do continuation with comments' => '<?php do {} /* next */ while ($a);',
            'unbraced parent continuations' => '<?php if ($a) if ($b) {} else {} else {}',
            'unbraced do body ends in if' => '<?php do if ($a) {} while ($b);',
            'closing PHP tag' => '<?php if ($a) {} ?>text',
            'literal contents' => '<?php $text = "if ($a) {}\\nnextStep();";',
            'heredoc' => "<?php \$text = <<<TEXT\nif (\$a) {}\nnextStep();\nTEXT;",
            'nowdoc' => "<?php \$text = <<<'TEXT'\nif (\$a) {}\nnextStep();\nTEXT;",
        ];

        foreach ($unchanged as $name => $code) {
            yield $name => [$code, $code];
        }
    }

    public function testConfiguredLineEndingsAndExistingTabIndentation(): void
    {
        $fixer = new BlankLineAfterControlStructureFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));
        $this->assertFixerOutput(
            $fixer,
            "<?php\r\nfunction run() {\r\n\tif (\$a) {}\r\n\tnextStep();\r\n}",
            "<?php\r\nfunction run() {\r\n\tif (\$a) {}\r\n\r\n\tnextStep();\r\n}",
        );
    }
}
