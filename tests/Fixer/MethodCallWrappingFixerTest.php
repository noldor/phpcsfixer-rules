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
    #[DataProvider('compactArguments')]
    public function testSingleArgumentUsesItsOwnConfigurableWidth(string $input, string $expected): void
    {
        $this->assertFixerOutput(new MethodCallWrappingFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compactArguments(): iterable
    {
        $array = "['command', 'job', 'jobId', 'jobAttempt']";

        foreach (['Context::forget', '$context->forget', '$context?->forget'] as $method) {
            $inline = "<?php\n{$method}({$array});\n";

            yield 'single array stays inline: ' . $method => [$inline, $inline];

            yield 'single array rejoins: ' . $method => [
                "<?php\n{$method}(\n    {$array},\n);\n",
                "<?php\n{$method}({$array},);\n",
            ];
        }

        foreach (['keys: ', '...'] as $prefix) {
            $inline = "<?php\n\$context->forget({$prefix}{$array});\n";

            yield 'named or unpacked single array: ' . $prefix => [$inline, $inline];
        }

        $longSyntax = "<?php\n\$context->forget(array('command', 'job', 'jobId', 'jobAttempt'));\n";

        yield 'long array syntax uses call width' => [$longSyntax, $longSyntax];

        yield 'array access keeps the ordinary single-argument limit' => [
            "<?php\n\$o->run({$array}[\$index]);\n",
            "<?php\n\$o->run(\n    {$array}[\$index]\n);\n",
        ];

        yield 'array union keeps the ordinary single-argument limit' => [
            "<?php\n\$o->run({$array} + \$extra);\n",
            "<?php\n\$o->run(\n    {$array} + \$extra\n);\n",
        ];

        yield 'short argument on an otherwise long line' => [
            "<?php\n\$executionId = Context::get('executionId') ?? \$event->job->uuid() ?? Str::uuid()->toString() ?? \$fallbackExecutionId;\n",
            "<?php\n\$executionId = Context::get('executionId') ?? \$event->job->uuid() ?? Str::uuid()->toString() ?? \$fallbackExecutionId;\n",
        ];

        yield 'exactly forty source characters stay inline' => [
            "<?php\n\$scalar->parseLiteral(new StringValueNode(['value' => \$value]));\n",
            "<?php\n\$scalar->parseLiteral(new StringValueNode(['value' => \$value]));\n",
        ];

        yield 'expanded short argument rejoins its call' => [
            "<?php\n\$scalar->parseLiteral(\n    new StringValueNode(['value' => \$value]),\n);\n",
            "<?php\n\$scalar->parseLiteral(new StringValueNode(['value' => \$value]),);\n",
        ];

        yield 'two short arguments ignore an outer closure header' => [
            "<?php\nContext::scope(static function (): void { ContextJob::dispatch('retry', retry: true); }, data: ['source' => 'http']);\n",
            "<?php\nContext::scope(static function (): void { ContextJob::dispatch('retry', retry: true); }, data: ['source' => 'http']);\n",
        ];

        yield 'forty-one characters wrap even in a short call' => [
            "<?php\n\$o->run('xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');\n",
            "<?php\n\$o->run(\n    'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'\n);\n",
        ];
    }

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

        foreach (['$o->{factory()}', '$o->{factory()->name}', 'Handler::{factory()}'] as $method) {
            yield 'dynamic name expression ' . $method => [
                "<?php\n{$method}(1, 2, 3, 4);\n",
                "<?php\n{$method}(\n    1,\n    2,\n    3,\n    4\n);\n",
            ];
        }

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

        $context = <<<'PHP'
            <?php
            Context::add([
                'correlationId' => $correlationId,
                'executionId' => $executionId,
                'source' => 'http',
            ]);

            PHP;

        yield 'array contents keep a compact call header' => [$context, $context];

        $namedArray = "<?php\n\$o?->run(options: [\n    'nested' => [1, 2, 3, 4],\n], enabled: true);\n";

        yield 'named array with a following argument' => [$namedArray, $namedArray];

        $unpackedArray = "<?php\n\$o->run(...[\n    1,\n    2,\n]);\n";

        yield 'unpacked array counts once' => [$unpackedArray, $unpackedArray];

        $longArray = "<?php\n\$o->run(array(\n    'first' => 1,\n    'second' => 2,\n));\n";

        yield 'long array syntax keeps a compact call header' => [$longArray, $longArray];

        $nestedArray = "<?php\n\$o->run([\n    \$o->inner(\n        1,\n        2,\n        3,\n        4\n    ),\n]);\n";

        yield 'nested calls inside an array do not expand the surrounding call' => [
            $nestedArray,
            $nestedArray,
        ];

        yield 'argument limit still applies to arrays' => [
            "<?php\n\$o->run(1, 2, 3, [\n    4,\n]);\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3,\n    [\n        4,\n    ]\n);\n",
        ];

        yield 'newline between array arguments still selects the call' => [
            "<?php\n\$o->run([\n    1,\n],\n    2);\n",
            "<?php\n\$o->run(\n    [\n        1,\n    ],\n    2\n);\n",
        ];

        $expandedArray = "<?php\n\$o->run(\n    [\n        1,\n    ],\n);\n";

        yield 'a short array rejoins its call header' => [
            $expandedArray,
            "<?php\n\$o->run([\n    1,\n],);\n",
        ];

        yield 'array access line breaks still select the call' => [
            "<?php\n\$o->run(\$values[\n    0\n]);\n",
            "<?php\n\$o->run(\n    \$values[\n        0\n    ]\n);\n",
        ];

        yield 'a short reserved-name nested call compacts' => [
            "<?php\n\$o->run(Handler::array(\n    1\n));\n",
            "<?php\n\$o->run(Handler::array(1));\n",
        ];

        $longArrayHeader = \str_repeat('x', 100);

        yield 'long call header does not expand a short array argument' => [
            "<?php\nHandler::{$longArrayHeader}([\n    1,\n]);\n",
            "<?php\nHandler::{$longArrayHeader}([\n    1,\n]);\n",
        ];

        $longArrayBody = "<?php\n\$o->run([\n    '{$longArrayHeader}',\n]);\n";

        yield 'long array contents do not affect the call header' => [
            $longArrayBody,
            $longArrayBody,
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

        $migration = <<<'PHP'
            <?php
            Schema::create('users', static function (Blueprint $table): void {
                $table->id();
                $table->string('email')->unique();
            });

            PHP;

        yield 'closure body does not make the argument list multiline' => [$migration, $migration];

        $capturingClosure = <<<'PHP'
            <?php
            $o->run(1, function ($value) use ($captured): void {
                consume($value, $captured);
            }, 3);

            PHP;

        yield 'capturing closure with a following argument' => [
            $capturingClosure,
            $capturingClosure,
        ];

        $namedClosure = <<<'PHP'
            <?php
            $o->run(callback: #[Example] static function ($value = new Value([1, 2])): void {
                consume($value);
            });

            PHP;

        yield 'long named attributed closure header wraps' => [
            $namedClosure,
            "<?php\n\$o->run(\n    callback: #[Example] static function (\$value = new Value([1, 2])): void {\n        consume(\$value);\n    }\n);\n",
        ];

        $nestedClosures = <<<'PHP'
            <?php
            $o->run(static function (): void {
                consume(static function (): void {
                    consume(1);
                });
            }, static function (): void {
                consume(2);
            });

            PHP;

        yield 'multiple nested closures' => [$nestedClosures, $nestedClosures];

        $referenceClosure = "<?php\n\$o->run(function &() use (&\$value) {\n    return \$value;\n});\n";

        yield 'reference-returning closure' => [$referenceClosure, $referenceClosure];

        yield 'named methods in anonymous classes are not closures' => [
            "<?php\n\$o->run(new class { public function execute(): void {\n    consume(1);\n} });\n",
            "<?php\n\$o->run(\n    new class { public function execute(): void {\n        consume(1);\n    } }\n);\n",
        ];

        yield 'method names using the function keyword are not closures' => [
            "<?php\n\$o->run(Handler::function(), \$other->function(), \$other?->function());\n",
            "<?php\n\$o->run(Handler::function(), \$other->function(), \$other?->function());\n",
        ];

        yield 'argument limit still applies to closures' => [
            "<?php\n\$o->run(1, 2, 3, static function (): void {\n    consume(4);\n});\n",
            "<?php\n\$o->run(\n    1,\n    2,\n    3,\n    static function (): void {\n        consume(4);\n    }\n);\n",
        ];

        yield 'newline in closure signature still selects the call' => [
            "<?php\n\$o->run(static function (\n    \$value,\n): void {\n    consume(\$value);\n});\n",
            "<?php\n\$o->run(\n    static function (\n        \$value,\n    ): void {\n        consume(\$value);\n    }\n);\n",
        ];

        yield 'newline between closure arguments still selects the call' => [
            "<?php\n\$o->run(1,\n    static function (): void {\n        consume(2);\n    });\n",
            "<?php\n\$o->run(\n    1,\n    static function (): void {\n        consume(2);\n    }\n);\n",
        ];

        $longArgument = \str_repeat('x', 100);

        yield 'long call line still selects a closure argument' => [
            "<?php\n\$o->run('{$longArgument}', static function (): void {\n    consume(1);\n});\n",
            "<?php\n\$o->run(\n    '{$longArgument}',\n    static function (): void {\n        consume(1);\n    }\n);\n",
        ];

        $longClosureBody = "<?php\n\$o->run(static function (): void {\n    \$value = '{$longArgument}';\n});\n";

        yield 'long closure body does not affect the call header' => [
            $longClosureBody,
            $longClosureBody,
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
    public function testMeasuresASingleArgumentInUnicodeCharacters(int $width, string $character, bool $wrap): void
    {
        $argument = "'" . \str_repeat($character, $width - 2) . "'";
        $input = "<?php\n\$o->run({$argument});\n";
        $expected = $wrap ? "<?php\n\$o->run(\n    {$argument}\n);\n" : $input;

        $this->assertFixerOutput(new MethodCallWrappingFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function lineLengths(): iterable
    {
        yield 'exactly 40' => [40, 'a', false];

        yield '41 characters' => [41, 'a', true];

        yield '40 Unicode characters' => [40, 'я', false];

        yield '41 Unicode characters' => [41, 'я', true];
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

    public function testTheSingleArgumentLimitCanBeConfiguredIndependently(): void
    {
        $fixer = new MethodCallWrappingFixer();
        $fixer->configure(['single_argument_max_length' => 5, 'max_line_length' => 120]);
        $input = "<?php\n\$o->run('abcd');\n";
        $expected = "<?php\n\$o->run(\n    'abcd'\n);\n";

        $this->assertFixerOutput($fixer, $input, $expected);
        $fixer->configure(['single_argument_max_length' => 6, 'max_line_length' => 1]);
        $this->assertFixerOutput($fixer, $input, $input);
    }

    public function testShortSingleArgumentsKeepBoundaryComments(): void
    {
        foreach ([
            "/* before */\n    'value'",
            "'value' // after",
            "'value', /* after comma */",
            "/* before */\n    ['command', 'job', 'jobId', 'jobAttempt']",
            "['command', 'job', 'jobId', 'jobAttempt'] // after",
            "['command', 'job', 'jobId', 'jobAttempt'], /* after comma */",
        ] as $argument) {
            $input = "<?php\n\$o->run(\n    {$argument}\n);\n";

            $this->assertFixerOutput(new MethodCallWrappingFixer(), $input, $input);
        }
    }

    public function testSingleArraysUseTheConfiguredCallWidth(): void
    {
        foreach ([80, 120] as $limit) {
            foreach (['x', 'я'] as $character) {
                foreach ([$limit, $limit + 1] as $width) {
                    $value = \str_repeat($character, $width - \mb_strlen("    \$o->run([''])"));
                    $array = "['{$value}']";
                    $inline = "<?php\n    \$o->run({$array});\n";
                    $expanded = "<?php\n    \$o->run(\n        {$array}\n    );\n";
                    $fixer = new MethodCallWrappingFixer();
                    $fixer->configure(['max_line_length' => $limit, 'single_argument_max_length' => 5]);
                    $expected = $width === $limit ? $inline : $expanded;

                    $this->assertFixerOutput($fixer, $inline, $expected);
                    $this->assertFixerOutput($fixer, $expanded, $expected);
                }
            }
        }
    }

    public function testSingleArrayJoiningRespectsTabsAndCrLf(): void
    {
        $fixer = new MethodCallWrappingFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));
        $array = "['command', 'job', 'jobId', 'jobAttempt']";

        $this->assertFixerOutput(
            $fixer,
            "<?php\r\n\tContext::forget(\r\n\t\t{$array},\r\n\t);\r\n",
            "<?php\r\n\tContext::forget({$array},);\r\n",
        );
    }

    public function testSingleArrayWidthIncludesThePreservedTrailingComma(): void
    {
        foreach ([80, 120] as $limit) {
            foreach ([$limit, $limit + 1] as $width) {
                foreach (['', '  '] as $padding) {
                    $value = \str_repeat('x', $width - \mb_strlen("\$o->run(['']{$padding},)"));
                    $array = "['{$value}']";
                    $inline = "<?php\n\$o->run({$array}{$padding},);\n";
                    $expanded = "<?php\n\$o->run(\n    {$array}{$padding},\n);\n";
                    $fixer = new MethodCallWrappingFixer();
                    $fixer->configure(['max_line_length' => $limit, 'single_argument_max_length' => 5]);
                    $expected = $width === $limit ? $inline : $expanded;

                    $this->assertFixerOutput($fixer, $inline, $expected);
                    $this->assertFixerOutput($fixer, $expanded, $expected);
                }
            }
        }
    }

    public function testSingleArrayLiteralTabsUseTheirJoinedColumn(): void
    {
        $array = "['\t" . \str_repeat('x', 63) . "']";
        $fixer = new MethodCallWrappingFixer();
        $fixer->configure(['max_line_length' => 80]);

        $this->assertFixerOutput(
            $fixer,
            "<?php\n\$obj->run({$array});\n",
            "<?php\n\$obj->run(\n    {$array}\n);\n",
        );
    }

    public function testMultipleArgumentsUseTheConfiguredExpressionWidth(): void
    {
        $shell = "\$o->run('', 1)";

        foreach ([120, 121] as $width) {
            $value = \str_repeat('x', $width - \mb_strlen($shell));
            $input = "<?php\n\$o->run('{$value}', 1);\n";
            $expected = 120 === $width ? $input : "<?php\n\$o->run(\n    '{$value}',\n    1\n);\n";

            $this->assertFixerOutput(new MethodCallWrappingFixer(), $input, $expected);
        }
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
        foreach (['max_line_length', 'max_single_line_arguments', 'single_argument_max_length'] as $option) {
            foreach ([0, -1, '100', 1.5, true] as $index => $value) {
                yield $option . $index => [$option, $value];
            }
        }
    }
}
