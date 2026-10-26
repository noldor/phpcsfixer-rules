<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests\Fixer;

use InvalidArgumentException;
use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Tests\FixerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\PhpCsFixer\Fixer\MethodCallWrappingFixer;

final class MethodCallWrappingFixerTest extends FixerTestCase
{
    #[DataProvider('layouts')]
    public function testWrapsOnlyMethodCallArgumentLists(string $input, string $expected): void
    {
        $this->assertFixerOutput(new MethodCallWrappingFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function layouts(): iterable
    {
        yield 'four arguments' => [
            "<?php\n\$o->run(1, 2, 3, 4);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'static method' => [
            "<?php\nHandler::run(1, 2, 3, 4);\n",
            "<?php\nHandler::run(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'static method with a reserved name' => [
            "<?php\nHandler::match(1, 2, 3, 4);\n",
            "<?php\nHandler::match(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'nullsafe method' => [
            "<?php\n\$o?->run(1, 2, 3, 4);\n",
            "<?php\n\$o?->run(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'dynamic method' => [
            "<?php\n\$o->\$method(1, 2, 3, 4);\n",
            "<?php\n\$o->\$method(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'braced dynamic method' => [
            "<?php\n\$o->{\$method}(1, 2, 3, 4);\n",
            "<?php\n\$o->{\$method}(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'braced static dynamic method' => [
            "<?php\nHandler::{\$method}(1, 2, 3, 4);\n",
            "<?php\nHandler::{\$method}(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'named arguments' => [
            "<?php\n\$o->run(first: 1, second: 2, third: 3, fourth: 4);\n",
            "<?php\n\$o->run(\n    first: 1,\n    second: 2,\n    third: 3,\n    fourth: 4\n);\n",
        ];

        yield 'unpacked argument counts once' => [
            "<?php\n\$o->run(1, 2, 3, ...\$rest);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3,\n    ...\$rest\n);\n",
        ];

        yield 'preserves trailing comma' => [
            "<?php\n\$o->run(1, 2, 3, 4,);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3,\n    4,\n);\n",
        ];

        yield 'existing multiline list' => [
            "<?php\n\$o->run(1,\n    2, 3);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3\n);\n",
        ];

        yield 'nested commas are not outer arguments' => [
            "<?php\n\$o->run([1, 2, 3, 4], combine(1, 2, 3, 4), 3);\n",
            "<?php\n\$o->run([1, 2, 3, 4], combine(1, 2, 3, 4), 3);\n",
        ];

        yield 'nested method calls' => [
            "<?php\n\$o->outer(\$o->inner(1, 2, 3, 4));\n",
            "<?php\n\$o->outer(\n    \$o->inner(\n        1,\n        2,\n        3,\n        4\n    )\n);\n",
        ];

        yield 'anonymous class header commas do not count' => [
            "<?php\n\$o->go(new class implements A, B {}, 1, 2);\n",
            "<?php\n\$o->go(new class implements A, B {}, 1, 2);\n",
        ];

        yield 'anonymous class header remains together when wrapping' => [
            "<?php\n\$o->go(new class implements A, B {}, 1, 2, 3);\n",
            "<?php\n\$o->go(\n    new class implements A, B {},\n    1,\n    2,\n    3\n);\n",
        ];

        yield 'dynamic property constructor is not a method call' => [
            "<?php\nnew \$o->factory(1, 2, 3, 4);\n",
            "<?php\nnew \$o->factory(1, 2, 3, 4);\n",
        ];

        yield 'nested property constructor is not a method call' => [
            "<?php\nnew \$o->nested->factory(1, 2, 3, 4);\n",
            "<?php\nnew \$o->nested->factory(1, 2, 3, 4);\n",
        ];

        yield 'indexed receiver constructor is not a method call' => [
            "<?php\nnew \$objects[0]->factory(1, 2, 3, 4);\n",
            "<?php\nnew \$objects[0]->factory(1, 2, 3, 4);\n",
        ];

        yield 'method on a constructed instance' => [
            "<?php\nnew Example()->run(1, 2, 3, 4);\n",
            "<?php\nnew Example()->run(\n    1,\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'short three-argument list' => [
            "<?php\n\$o->run(1, 2, 3);\n",
            "<?php\n\$o->run(1, 2, 3);\n",
        ];

        yield 'constructors free functions declarations and attributes' => [
            "<?php\n#[Example(1, 2, 3, 4)]\nfunction run(\$a, \$b, \$c, \$d) { run(1, 2, 3, 4); new Example(1, 2, 3, 4); }\n",
            "<?php\n#[Example(1, 2, 3, 4)]\nfunction run(\$a, \$b, \$c, \$d) { run(1, 2, 3, 4); new Example(1, 2, 3, 4); }\n",
        ];

        yield 'first-class callable placeholders' => [
            "<?php\n\$callback = \$o->run(...); \$other = Handler::run(...);\n",
            "<?php\n\$callback = \$o->run(...); \$other = Handler::run(...);\n",
        ];

        yield 'existing multiline first-class callable placeholder' => [
            "<?php\n\$callback = Handler::run(\n...\n);\n",
            "<?php\n\$callback = Handler::run(\n...\n);\n",
        ];
        $longMethod = \str_repeat('x', 100);

        yield 'long first-class callable placeholder' => [
            "<?php\n\$callback = Handler::{$longMethod}(...);\n",
            "<?php\n\$callback = Handler::{$longMethod}(...);\n",
        ];

        yield 'block comments' => [
            "<?php\n\$o->run(/* first */ 1, /* second */ 2, 3, 4 /* last */);\n",
            "<?php\n\$o->run(\n    /* first */ 1,\n    /* second */ 2,\n    3,\n    4 /* last */\n);\n",
        ];

        yield 'line comment remains attached to preceding argument' => [
            "<?php\n\$o->run(1, // keep this note\n    2, 3, 4);\n",
            "<?php\n\$o->run(\n    1, // keep this note\n    2,\n    3,\n    4\n);\n",
        ];

        yield 'heredoc contents remain unchanged' => [
            "<?php\n\$o->run(1, 2, 3, <<<'TEXT'\nkeep, these, bytes\nTEXT\n);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3,\n    <<<'TEXT'\nkeep, these, bytes\nTEXT\n);\n",
        ];
    }

    #[DataProvider('lineLengths')]
    public function testMeasuresPhysicalLinesInUnicodeCharacters(int $width, string $character, bool $wrap): void
    {
        $argument = "'" . \str_repeat($character, $width - \mb_strlen('$o->run();') - 2) . "'";
        $input = "<?php\n\$o->run({$argument});\n";
        $expected = $wrap ? "<?php\n\$o->run(\n    {$argument}\n);\n" : $input;

        $this->assertFixerOutput(new MethodCallWrappingFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function lineLengths(): iterable
    {
        yield 'exactly 100' => [100, 'a', false];

        yield '101 characters' => [101, 'a', true];

        yield '100 Unicode characters' => [100, 'я', false];

        yield '101 Unicode characters' => [101, 'я', true];
    }

    public function testUsesConfiguredIndentationAndLineEndings(): void
    {
        $fixer = new MethodCallWrappingFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));

        $this->assertFixerOutput(
            $fixer,
            "<?php\r\n\t\$o->run(1, 2, 3, 4);\r\n",
            "<?php\r\n\t\$o->run(\r\n\t\t1,\r\n\t\t2,\r\n\t\t3,\r\n\t\t4\r\n\t);\r\n",
        );
    }

    public function testAcceptsCustomWidthAndArgumentLimit(): void
    {
        $fixer = new MethodCallWrappingFixer();
        $fixer->configure(['max_line_length' => 80, 'max_single_line_arguments' => 2]);

        $this->assertFixerOutput(
            $fixer,
            "<?php\n\$o->run(1, 2, 3);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3\n);\n",
        );
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(string $option, mixed $value): void
    {
        $fixer = new MethodCallWrappingFixer();
        $this->expectException(InvalidArgumentException::class);

        $fixer->configure([$option => $value]);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidOptions(): iterable
    {
        foreach (['max_line_length', 'max_single_line_arguments'] as $option) {
            foreach ([0, -1, '100', 1.5, true] as $index => $value) {
                yield $option . $index => [$option, $value];
            }
        }
    }
}
