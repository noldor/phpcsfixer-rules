<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests\Fixer;

use Throwable;
use SplFileInfo;
use InvalidArgumentException;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Tests\FixerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\PhpCsFixer\Fixer\FunctionCompositionToPipeFixer;

final class FunctionCompositionToPipeFixerTest extends FixerTestCase
{
    #[DataProvider('compositions')]
    public function testPreservesCompositionSemantics(string $input, string $expected): void
    {
        $actual = $this->format($input);
        $this->assertSame($expected, $actual);
        $this->assertSame($actual, $this->format($actual), 'The fixer must be idempotent.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compositions(): iterable
    {
        yield 'array filter followed by values' => [
            "<?php\n\$result = \\array_values(\\array_filter(\$values));\n",
            "<?php\n\$result = \\array_filter(\$values)\n    |> \\array_values(...);\n",
        ];

        yield 'multiple unary layers keep invocation order' => [
            "<?php\n\$result = \\array_reverse(\\array_values(\\array_filter(\$values)));\n",
            "<?php\n\$result = \\array_filter(\$values)\n    |> \\array_values(...)\n    |> \\array_reverse(...);\n",
        ];

        yield 'global unqualified functions resolve to native functions' => [
            "<?php\n\$result = array_values(array_filter(\$values));\n",
            "<?php\n\$result = array_filter(\$values)\n    |> array_values(...);\n",
        ];

        yield 'explicit import aliases preserve their names' => [
            "<?php\nnamespace Example;\nuse function array_values as values;\n\$result = values(\\array_filter(\$values));\n",
            "<?php\nnamespace Example;\nuse function array_values as values;\n\$result = \\array_filter(\$values)\n    |> values(...);\n",
        ];

        yield 'existing pipe stages always use separate lines' => [
            "<?php\n\$result = \$input |> first(...) |> second(...);\n",
            "<?php\n\$result = \$input\n    |> first(...)\n    |> second(...);\n",
        ];

        yield 'comments and Unicode inside the retained call stay intact' => [
            "<?php\n// Проверка\n\$result = \\array_values(\\array_filter([/* keep */ 'я', 'λ']));\n",
            "<?php\n// Проверка\n\$result = \\array_filter([/* keep */ 'я', 'λ'])\n    |> \\array_values(...);\n",
        ];

        foreach ([
            'namespace fallback may resolve a local function' => "namespace Example;\n\$result = array_values(\\array_filter(\$values));",
            'reference-returning function context' => 'function &sample() { return \\array_values(\\array_filter($values)); }',
            'reference array item context' => '$result = [&\\array_values(\\array_filter($values))];',
            'unknown signature' => '$result = custom_function(\\array_filter($values));',
            'by-reference native parameter' => '$result = \\array_pop(\\array_filter($values));',
            'multiple outer arguments' => "\$result = \\array_keys(\\array_filter(\$values), 'key');",
            'named argument validation is preserved' => '$result = \\array_values(array: \\array_filter($values));',
            'unpacking' => '$result = \\array_values(...$values);',
            'comment before removed argument boundary' => '$result = \\array_values(/* keep */ \\array_filter($values));',
            'comment after removed argument boundary' => '$result = \\array_values(\\array_filter($values) /* keep */);',
        ] as $name => $code) {
            $input = "<?php\n{$code}\n";

            yield $name => [$input, $input];
        }
    }

    #[DataProvider('closingLinePipelines')]
    public function testClosingLinePipelinesPreserveTokensAndSettle(string $input, string $expected): void
    {
        $this->assertFixerOutput(new FunctionCompositionToPipeFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function closingLinePipelines(): iterable
    {
        $call = "<?php\n\$outside = \\array_filter(\n    \$history,\n    static fn (\$entry): bool => 'outside.example.test' === \$entry['request']->getUri()->getHost(),\n)";

        yield 'multiline call closes beside its short pipe' => [
            $call . "\n    |> \\array_values(...);\n",
            $call . " |> \\array_values(...);\n",
        ];

        yield 'existing compact closing line is retained' => [
            $call . " |> \\array_values(...);\n",
            $call . " |> \\array_values(...);\n",
        ];

        yield 'following operations retain separate lines' => [
            $call . " |> \\array_values(...) |> \\count(...);\n",
            $call . " |> \\array_values(...)\n    |> \\count(...);\n",
        ];

        yield 'multiline array closes beside a user callable' => [
            "<?php\n\$result = [\n    1,\n    2,\n]\n    |> custom(...);\n",
            "<?php\n\$result = [\n    1,\n    2,\n] |> custom(...);\n",
        ];

        yield 'closure call closes beside its continuation' => [
            "<?php\n\$result = produce(static function (): void {\n    work();\n})\n    |> custom(...);\n",
            "<?php\n\$result = produce(static function (): void {\n    work();\n}) |> custom(...);\n",
        ];

        yield 'a moved callable body keeps its indentation' => [
            "<?php\n\$result = produce(\n    \$items,\n)\n    |> (static function (array \$items): array {\n        return \$items;\n    });\n",
            "<?php\n\$result = produce(\n    \$items,\n) |> (static function (array \$items): array {\n    return \$items;\n});\n",
        ];

        yield 'compact operator tokenization is supported' => [
            "<?php\n\$result = produce(\n    \$items,\n)|> custom(...);\n",
            "<?php\n\$result = produce(\n    \$items,\n) |> custom(...);\n",
        ];

        yield 'nested pipe edits keep source boundaries stable' => [
            "<?php\n\$result = produce(\n    \$items,\n)\n    |> (static fn (\$value): array => \$value|> custom(...));\n",
            "<?php\n\$result = produce(\n    \$items,\n) |> (static fn (\$value): array => \$value\n    |> custom(...));\n",
        ];

        yield 'closing-only exception also applies after an intermediate closure' => [
            "<?php\n\$result = produce()\n    |> (static function (\$value) {\n        return \$value;\n    })\n    |> custom(...);\n",
            "<?php\n\$result = produce()\n    |> (static function (\$value) {\n        return \$value;\n    }) |> custom(...);\n",
        ];
        $name = \str_repeat('x', 105);

        yield 'nested stage width uses the final outer callable indentation' => [
            "<?php\n\$result = produce(\n    \$items,\n)\n    |> (static function (array \$items): array {\n        \$items = normalise(\$items);\n        return produce(\n            \$items,\n        )\n            |> {$name}(...);\n    });\n",
            "<?php\n\$result = produce(\n    \$items,\n) |> (static function (array \$items): array {\n    \$items = normalise(\$items);\n    return produce(\n        \$items,\n    ) |> {$name}(...);\n});\n",
        ];

        foreach (['(', 'consume('] as $prefix) {
            foreach ([120, 121] as $width) {
                $name = \str_repeat('x', $width - \mb_strlen(') |> (...));'));
                $input = "<?php\n\$result = {$prefix}produce(\n    \$items,\n)\n    |> {$name}(...));\n";
                $expected = 120 === $width ? \str_replace(")\n    |>", ') |>', $input) : $input;

                yield 'enclosing suffix ' . $prefix . ' at ' . $width . ' columns' => [$input, $expected];
            }
        }

        $literal = \str_repeat('x', 90);

        yield 'nested first-stage arguments use final enclosing indentation' => [
            "<?php\n\$result = \$items |> (static function (array \$items): array {\n    work();\n    return produce('{$literal}', \$items) |> custom(...);\n});\n",
            "<?php\n\$result = \$items\n    |> (static function (array \$items): array {\n        work();\n        return produce(\n            '{$literal}',\n            \$items\n        ) |> custom(...);\n    });\n",
        ];

        foreach (['// keep', '/* keep */'] as $comment) {
            $input = $call . " {$comment}\n    |> custom(...);\n";

            yield 'comment barrier ' . $comment => [$input, $input];
        }

        foreach ([120, 121] as $width) {
            $name = \str_repeat('x', $width - \mb_strlen(') |> (...);'));
            $input = $call . "\n    |> {$name}(...);\n";
            $expected = $call . (120 === $width ? ' ' : "\n    ") . "|> {$name}(...);\n";

            yield 'closing line at ' . $width . ' columns' => [$input, $expected];
        }
    }

    #[DataProvider('runtimeExpressions')]
    public function testPreservesRuntimeValuesAndErrors(string $expression): void
    {
        foreach ([0, 1] as $strict) {
            $input = "<?php declare(strict_types = {$strict});\nreturn {$expression};\n";
            $actual = $this->format($input);
            $this->assertNotSame($input, $actual, 'The runtime comparison must exercise a conversion.');
            $this->assertSame($this->evaluate($input), $this->evaluate($actual));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runtimeExpressions(): iterable
    {
        yield 'array keys and values' => ['\\array_values(\\array_filter([0, 3, 7, false]))'];

        yield 'offset access binds to pipeline result' => ['\\array_values(\\array_filter([0, 3, 7]))[0]'];

        yield 'arithmetic binds to pipeline result' => ['\\array_sum(\\array_values([3, 7])) + 1'];

        yield 'null coalescing' => ['\\array_values(\\array_filter([0, 3]))[0] ?? 17'];

        yield 'nested argument' => ['\\in_array(3, \\array_values(\\array_filter([0, 3])), true)'];

        yield 'string composition' => ['\\strtoupper(\\trim("  mixed  "))'];

        yield 'implicit reference consumer diagnostics' => ['$consumer(\\array_values(\\array_filter([0, 3])))'];

        yield 'native reference consumer diagnostics' => ['\\sort(\\array_values(\\array_filter([3, 1, 0])))'];

        yield 'weak string coercion' => ["\\strtoupper(\\strlen('x'))"];

        yield 'strict type errors' => ['\\strlen(\\count([1, 2]))'];

        yield 'successive stage invocation order' => ['\\array_sum(\\array_reverse(\\array_values($producer())))'];

        yield 'single evaluation' => ['\\array_values($producer())'];
    }

    public function testConfiguredWhitespaceAndWidth(): void
    {
        $fixer = new FunctionCompositionToPipeFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));
        $fixer->configure(['max_line_length' => 20]);
        $input = "<?php\r\n\t\$result = \\array_values(\\array_filter(\$values, \$predicate));\r\n";
        $tokens = Tokens::fromCode($input);
        $fixer->fix(new SplFileInfo('example.php'), $tokens);

        $this->assertSame(
            "<?php\r\n\t\$result = \\array_filter(\r\n\t\t\$values,\r\n\t\t\$predicate\r\n\t)\r\n\t\t|> \\array_values(...);\r\n",
            $tokens->generateCode(),
        );
    }

    public function testClosingLineUsesConfiguredWhitespace(): void
    {
        $fixer = new FunctionCompositionToPipeFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));
        $input = "<?php\r\n\t\$result = produce(\r\n\t\t\$items,\r\n\t)\r\n\t\t|> custom(...);\r\n";
        $expected = "<?php\r\n\t\$result = produce(\r\n\t\t\$items,\r\n\t) |> custom(...);\r\n";

        $this->assertFixerOutput($fixer, $input, $expected);
    }

    public function testNestedArrowHeadersSettleAfterExplicitFormattingRuns(): void
    {
        $literal = \str_repeat('x', 90);
        $input = "<?php\n\$result = produce(\n    \$items,\n)\n    |> (static fn (\$value) => produce('{$literal}', \$value) |> custom(...));\n";
        $formatted = $this->format($this->format($input));
        $expected = "<?php\n\$result = produce(\n    \$items,\n) |> (static fn (\$value) => produce(\n    '{$literal}',\n    \$value\n) |> custom(...));\n";

        $this->assertSame($expected, $formatted);
        $this->assertFixerOutput(new FunctionCompositionToPipeFixer(), $formatted, $formatted);
    }

    public function testRejectsInvalidWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FunctionCompositionToPipeFixer()->configure(['max_line_length' => 0]);
    }

    private function format(string $code): string
    {
        $tokens = Tokens::fromCode($code);
        new FunctionCompositionToPipeFixer()->fix(new SplFileInfo('example.php'), $tokens);

        return $tokens->generateCode();
    }

    private function evaluate(string $code): mixed
    {
        $calls = 0;
        $producer = static function () use (&$calls): array {
            return [++$calls];
        };

        $consumer = static function (array &$value): array {
            $value[] = 9;

            return $value;
        };
        $diagnostics = [];
        \set_error_handler(static function (int $severity, string $message) use (&$diagnostics): bool {
            $diagnostics[] = [$severity, $message];

            return true;
        });

        try {
            $value = eval(\mb_substr($code, 5));

            return ['value' => $value, 'calls' => $calls, 'diagnostics' => $diagnostics];
        } catch (Throwable $error) {
            return ['error' => $error::class, 'message' => $error->getMessage(), 'diagnostics' => $diagnostics];
        } finally {
            \restore_error_handler();
        }
    }
}
