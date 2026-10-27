<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests\Fixer;

use InvalidArgumentException;
use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Tests\FixerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\PhpCsFixer\Fixer\ConditionalExpressionWrappingFixer;

final class ConditionalExpressionWrappingFixerTest extends FixerTestCase
{
    #[DataProvider('expressions')]
    public function testWrapsConditionalExpressions(string $input, string $expected): void
    {
        $this->assertFixerOutput(new ConditionalExpressionWrappingFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressions(): iterable
    {
        $short = "<?php\nif (\$first && \$second) {}\n";

        yield 'short condition stays inline' => [$short, $short];

        $nested = "<?php\nreturn \$this->trashed()\n    || \$this->getDeletedStatus() === (\$status instanceof BackedEnum ? \$status->value : \$status);\n";

        yield 'short nested ternary is independent of the outer comparison width' => [
            $nested,
            $nested,
        ];

        $long = \str_repeat('x', 85);

        yield 'top-level same-line statements are independent siblings' => [
            "<?php\n\$first = \$a ? check('{$long}') : \$b; \$second = \$c ? check('{$long}') : \$d;\n",
            "<?php\n\$first = \$a\n    ? check('{$long}')\n    : \$b; \$second = \$c\n    ? check('{$long}')\n    : \$d;\n",
        ];

        yield 'array key and value ternaries are independent siblings' => [
            "<?php\n\$values = [(\$a ? check('{$long}') : \$b) => (\$c ? check('{$long}') : \$d)];\n",
            "<?php\n\$values = [(\$a\n    ? check('{$long}')\n    : \$b) => (\$c\n    ? check('{$long}')\n    : \$d)];\n",
        ];

        yield 'match arm results are independent siblings' => [
            "<?php\n\$value = match (\$key) {0 => \$a ? check('{$long}') : \$b, 1 => \$c ? check('{$long}') : \$d};\n",
            "<?php\n\$value = match (\$key) {0 => \$a\n    ? check('{$long}')\n    : \$b, 1 => \$c\n    ? check('{$long}')\n    : \$d};\n",
        ];

        yield 'match condition and result are independent siblings' => [
            "<?php\n\$value = match (\$key) {(\$a ? check('{$long}') : \$b) => (\$c ? check('{$long}') : \$d)};\n",
            "<?php\n\$value = match (\$key) {(\$a\n    ? check('{$long}')\n    : \$b) => (\$c\n    ? check('{$long}')\n    : \$d)};\n",
        ];

        yield 'echo statement owns its sibling expressions' => [
            "<?php\necho \$a ? check('{$long}') : \$b, \$c ? check('{$long}') : \$d;\n",
            "<?php\necho \$a\n    ? check('{$long}')\n    : \$b, \$c\n    ? check('{$long}')\n    : \$d;\n",
        ];

        yield 'static statement owns its sibling initializers' => [
            "<?php\nfunction sample(): void {\n    static \$first = \$a ? check('{$long}') : \$b, \$second = \$c ? check('{$long}') : \$d;\n}\n",
            "<?php\nfunction sample(): void {\n    static \$first = \$a\n        ? check('{$long}')\n        : \$b, \$second = \$c\n        ? check('{$long}')\n        : \$d;\n}\n",
        ];

        yield 'inline siblings in an already expanded list use its first item indentation' => [
            "<?php\n\$values = [\n    \$a ? check('{$long}') : \$b, \$c ? check('{$long}') : \$d,\n];\n",
            "<?php\n\$values = [\n    \$a\n        ? check('{$long}')\n        : \$b, \$c\n        ? check('{$long}')\n        : \$d,\n];\n",
        ];

        yield 'nested ternary follows its moved if operand' => [
            "<?php\nif (\$first && (\$pick ? check('{$long}') : \$fallback)) {}\n",
            "<?php\nif (\$first\n    && (\$pick\n        ? check('{$long}')\n        : \$fallback)\n) {}\n",
        ];

        foreach (['[', 'wrap('] as $container) {
            $close = '[' === $container ? ']' : ')';

            yield 'nested first item reaches its outer sibling argument in ' . $container => [
                "<?php\n\$o->run(\$a ? check('{$long}') : \$b, {$container}\$c ? check('{$long}') : \$d{$close});\n",
                "<?php\n\$o->run(\$a\n    ? check('{$long}')\n    : \$b, {$container}\$c\n    ? check('{$long}')\n    : \$d{$close});\n",
            ];
        }

        yield 'concatenated ternaries keep independent indentation' => [
            "<?php\n\$value = (\$a ? check('{$long}') : \$b) . (\$c ? check('{$long}') : \$d);\n",
            "<?php\n\$value = (\$a\n    ? check('{$long}')\n    : \$b) . (\$c\n    ? check('{$long}')\n    : \$d);\n",
        ];

        yield 'an existing binary continuation retains its own indentation' => [
            "<?php\n\$value = (\$a ? check('{$long}') : \$b)\n    . (\$c ? check('{$long}') : \$d);\n",
            "<?php\n\$value = (\$a\n    ? check('{$long}')\n    : \$b)\n    . (\$c\n        ? check('{$long}')\n        : \$d);\n",
        ];

        foreach (['[', 'run('] as $container) {
            $prefix = '[' === $container ? '[' : '$o->run(';
            $close = '[' === $container ? ']' : ')';

            yield 'nested sibling ternaries own their branch plans in ' . $container => [
                "<?php\n\$value = \$flag ? {$prefix}\$a ? check('{$long}') : \$b, \$c ? check('{$long}') : \$d{$close} : \$fallback;\n",
                "<?php\n\$value = \$flag\n    ? {$prefix}\$a\n        ? check('{$long}')\n        : \$b, \$c\n        ? check('{$long}')\n        : \$d{$close}\n    : \$fallback;\n",
            ];
        }

        yield 'ternary inside a sibling concatenation uses its containing argument' => [
            "<?php\n\$o->run(\$a ? check('{$long}') : \$b, \$prefix . (\$c ? check('{$long}') : \$d));\n",
            "<?php\n\$o->run(\$a\n    ? check('{$long}')\n    : \$b, \$prefix . (\$c\n    ? check('{$long}')\n    : \$d));\n",
        ];

        yield 'ternary branches are not outer condition boundaries' => [
            "<?php\nif (\$pick ? \$a && \$b : \$c && check('{$long}')) {}\n",
            "<?php\nif (\$pick\n    ? \$a && \$b\n    : \$c && check('{$long}')\n) {}\n",
        ];

        yield 'logical operator outside a ternary retains its own boundary' => [
            "<?php\nif (\$pick ? \$a && \$b : \$c and check('{$long}')) {}\n",
            "<?php\nif (\$pick ? \$a && \$b : \$c\n    and check('{$long}')\n) {}\n",
        ];

        yield 'grouped ternary condition retains its boolean grouping' => [
            "<?php\n\$value = (\$first && check('{$long}')) ? \$yes : \$no;\n",
            "<?php\n\$value = (\$first && check('{$long}'))\n    ? \$yes\n    : \$no;\n",
        ];

        foreach (['[', 'run('] as $container) {
            $prefix = '[' === $container ? '$values = [' : '$o->run(';
            $close = '[' === $container ? ']' : ')';

            yield 'sibling ternaries do not share indentation in ' . $container => [
                "<?php\n{$prefix}\$a ? check('{$long}') : \$b, \$c ? check('{$long}') : \$d{$close};\n",
                "<?php\n{$prefix}\$a\n    ? check('{$long}')\n    : \$b, \$c\n    ? check('{$long}')\n    : \$d{$close};\n",
            ];
        }

        $variable = '$' . \str_repeat('longVariable', 8);

        foreach (['??=', '.='] as $operator) {
            yield 'compound assignment prefix ' . $operator => [
                "<?php\n{$variable} {$operator} \$condition ? \$yes : \$no;\n",
                "<?php\n{$variable} {$operator} \$condition\n    ? \$yes\n    : \$no;\n",
            ];
        }

        yield 'long if condition' => [
            "<?php\nif (\$first && check('{$long}')) {}\n",
            "<?php\nif (\$first\n    && check('{$long}')\n) {}\n",
        ];

        yield 'AST byte offsets survive non-ASCII source' => [
            "<?php\n// Проверка заголовка\n\$label = 'я';\nif (\$first && check('{$long}')) {}\n",
            "<?php\n// Проверка заголовка\n\$label = 'я';\nif (\$first\n    && check('{$long}')\n) {}\n",
        ];

        yield 'existing break distributes all top-level operators' => [
            "<?php\nif (\$a &&\n    \$b || \$c) {}\n",
            "<?php\nif (\$a\n    && \$b\n    || \$c\n) {}\n",
        ];

        yield 'elseif condition' => [
            "<?php\nif (\$a) {} elseif (\$b || check('{$long}')) {}\n",
            "<?php\nif (\$a) {} elseif (\$b\n    || check('{$long}')\n) {}\n",
        ];

        yield 'while condition' => [
            "<?php\nwhile (\$first && check('{$long}')) {}\n",
            "<?php\nwhile (\$first\n    && check('{$long}')\n) {}\n",
        ];

        yield 'do while condition' => [
            "<?php\ndo {} while (\$first && check('{$long}'));\n",
            "<?php\ndo {} while (\$first\n    && check('{$long}')\n);\n",
        ];

        yield 'operators in calls arrays and strings are not condition boundaries' => [
            "<?php\nif (check('&& ||', [\$a && \$b]) || check('{$long}')) {}\n",
            "<?php\nif (check('&& ||', [\$a && \$b])\n    || check('{$long}')\n) {}\n",
        ];

        yield 'explicit grouping and precedence retained' => [
            "<?php\nif ((\$a || \$b) && check('{$long}')) {}\n",
            "<?php\nif ((\$a || \$b)\n    && check('{$long}')\n) {}\n",
        ];

        yield 'line comment stays with its operand' => [
            "<?php\nif (\$a // keep this reason\n    && \$b) {}\n",
            "<?php\nif (\$a // keep this reason\n    && \$b\n) {}\n",
        ];

        $body = "<?php\nif (\$a && check(function (): void {\n    consume();\n})) {}\n";

        yield 'closure body alone does not select a condition' => [$body, $body];

        $header = "<?php\nif (\$a && \$b) { consume('{$long}'); }\n";

        yield 'long statement body does not select a short condition' => [$header, $header];

        yield 'long full ternary' => [
            "<?php\n\$value = check('{$long}') ? \$first : \$second;\n",
            "<?php\n\$value = check('{$long}')\n    ? \$first\n    : \$second;\n",
        ];

        yield 'boolean ternary condition' => [
            "<?php\n\$value = \$first && check('{$long}') ? \$yes : \$no;\n",
            "<?php\n\$value = \$first\n    && check('{$long}')\n        ? \$yes\n        : \$no;\n",
        ];

        yield 'a long result branch does not split a short boolean condition' => [
            "<?php\n\$value = \$first && \$second ? check('{$long}') : \$fallback;\n",
            "<?php\n\$value = \$first && \$second\n    ? check('{$long}')\n    : \$fallback;\n",
        ];

        yield 'nested ternary uses its containing branch indentation' => [
            "<?php\n\$value = \$a ? (\$b ? check('{$long}') : \$c) : \$d;\n",
            "<?php\n\$value = \$a\n    ? (\$b\n        ? check('{$long}')\n        : \$c)\n    : \$d;\n",
        ];

        $elvis = "<?php\n\$value = check('{$long}') ?: \$fallback;\n";

        yield 'shorthand ternary is left unchanged' => [$elvis, $elvis];

        $signature = "<?php\nfunction accept(?string \$value): string { return \$value ?? 'x'; }\n";

        yield 'nullable types and null coalescing are not ternaries' => [$signature, $signature];
    }

    public function testConfiguredWidthAndWhitespace(): void
    {
        $fixer = new ConditionalExpressionWrappingFixer();
        $fixer->configure(['max_line_length' => 25]);
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));

        $this->assertFixerOutput(
            $fixer,
            "<?php\r\n\tif (\$first && \$second && \$third) {}\r\n",
            "<?php\r\n\tif (\$first\r\n\t\t&& \$second\r\n\t\t&& \$third\r\n\t) {}\r\n",
        );
    }

    public function testCountsTheHeaderWidthInUnicodeCharacters(): void
    {
        $prefix = "if (\$first && check('";
        $suffix = "')) {";
        $value = \str_repeat('я', 100 - \mb_strlen($prefix . $suffix));
        $input = "<?php\n{$prefix}{$value}{$suffix}\n}\n";

        $this->assertFixerOutput(new ConditionalExpressionWrappingFixer(), $input, $input);

        $value .= 'я';
        $input = "<?php\n{$prefix}{$value}{$suffix}\n}\n";
        $expected = "<?php\nif (\$first\n    && check('{$value}')\n) {\n}\n";

        $this->assertFixerOutput(new ConditionalExpressionWrappingFixer(), $input, $expected);
    }

    public function testRejectsANonPositiveWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConditionalExpressionWrappingFixer()->configure(['max_line_length' => 0]);
    }
}
