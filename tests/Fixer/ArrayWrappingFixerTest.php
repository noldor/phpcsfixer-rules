<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests\Fixer;

use InvalidArgumentException;
use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Tests\FixerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Noldors\PhpCsFixer\Fixer\ArrayWrappingFixer;

final class ArrayWrappingFixerTest extends FixerTestCase
{
    #[DataProvider('provideArrays')]
    public function testArrayLayout(string $input, string $expected): void
    {
        $this->assertFixerOutput(new ArrayWrappingFixer(), $input, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideArrays(): iterable
    {
        yield 'short literal' => ['<?php $values = [1, 2];', '<?php $values = [1, 2];'];

        $longSibling = "<?php\n\$o->run([1, 2], '" . \str_repeat('x', 100) . "');\n";

        yield 'a long following argument does not select a short array' => [
            $longSibling,
            $longSibling,
        ];

        yield 'empty literal' => ["<?php\n\$values = [ ];", "<?php\n\$values = [ ];"];

        yield 'empty commented literal' => [
            "<?php\n\$values = [\n/* none */\n];",
            "<?php\n\$values = [\n/* none */\n];",
        ];

        yield 'existing multiline literal' => [
            "<?php\n\$values = [1, 2,\n3];",
            "<?php\n\$values = [\n    1,\n    2,\n    3\n];",
        ];

        yield 'long array syntax' => [
            "<?php\n\$values = array(1, 2,\n3);",
            "<?php\n\$values = array(\n    1,\n    2,\n    3\n);",
        ];

        yield 'trailing comma retained' => [
            "<?php\n\$values = [1,\n2,];",
            "<?php\n\$values = [\n    1,\n    2,\n];",
        ];

        yield 'nested arrays remain independently inline' => [
            "<?php\n\$values = ['first' => [1, 2],\n'second' => [3, 4]];",
            "<?php\n\$values = [\n    'first' => [1, 2],\n    'second' => [3, 4]\n];",
        ];

        yield 'comments remain with elements' => [
            "<?php\n\$values = [1, // first\n2, /* third */ 3];",
            "<?php\n\$values = [\n    1, // first\n    2,\n    /* third */ 3\n];",
        ];

        yield 'nested calls do not split array elements' => [
            "<?php\n\$values = [resolve(1, 2),\nfn () => [3, 4]];",
            "<?php\n\$values = [\n    resolve(1, 2),\n    fn () => [3, 4]\n];",
        ];

        yield 'anonymous class interfaces do not split elements' => [
            "<?php\n\$values = [new class implements First, Second {},\n1];",
            "<?php\n\$values = [\n    new class implements First, Second {},\n    1\n];",
        ];

        yield 'destructuring unchanged' => [
            "<?php\n[\$first,\n\$second] = \$values;",
            "<?php\n[\$first,\n\$second] = \$values;",
        ];

        yield 'list destructuring unchanged' => [
            "<?php\nlist(\$first,\n\$second) = \$values;",
            "<?php\nlist(\$first,\n\$second) = \$values;",
        ];

        yield 'array access unchanged' => [
            "<?php\n\$value = \$values[\n1\n];",
            "<?php\n\$value = \$values[\n1\n];",
        ];

        yield 'attribute unchanged' => [
            "<?php\n#[Example(\n1, 2\n)]\nclass Example {}",
            "<?php\n#[Example(\n1, 2\n)]\nclass Example {}",
        ];

        yield 'array parameter and return types unchanged' => [
            '<?php function values(array $items): array { return $items; }',
            '<?php function values(array $items): array { return $items; }',
        ];

        yield 'array property type unchanged' => [
            '<?php class Values { private array $items; }',
            '<?php class Values { private array $items; }',
        ];

        $shell = "\$values = ['', 2];";
        $atLimit = "\$values = ['" . \str_repeat('a', 100 - \mb_strlen($shell)) . "', 2];";

        yield 'exactly 100 characters unchanged' => ["<?php\n" . $atLimit, "<?php\n" . $atLimit];
        $overLimit = \str_replace("', 2]", "a', 2]", $atLimit);

        yield '101 characters wrapped' => [
            "<?php\n" . $overLimit,
            "<?php\n\$values = [\n    '" . \str_repeat('a', 101 - \mb_strlen($shell)) . "',\n    2\n];",
        ];
        $unicodeAtLimit = "\$values = ['" . \str_repeat('я', 100 - \mb_strlen($shell)) . "', 2];";

        yield 'width counts characters rather than bytes' => [
            "<?php\n" . $unicodeAtLimit,
            "<?php\n" . $unicodeAtLimit,
        ];

        yield 'string content preserved' => [
            "<?php\n\$values = ['first, second',\n\"a\\nb\"];",
            "<?php\n\$values = [\n    'first, second',\n    \"a\\nb\"\n];",
        ];

        yield 'multiline string contents preserved' => [
            "<?php\n\$values = [\"first\nsecond\", 2];",
            "<?php\n\$values = [\n    \"first\nsecond\",\n    2\n];",
        ];

        yield 'heredoc contents preserved' => [
            "<?php\n\$values = [<<<TEXT\nfirst\nsecond\nTEXT, 2];",
            "<?php\n\$values = [\n    <<<TEXT\nfirst\nsecond\nTEXT,\n    2\n];",
        ];
    }

    public function testConfiguredWidth(): void
    {
        $fixer = new ArrayWrappingFixer();
        $fixer->configure(['max_line_length' => 20]);

        $this->assertFixerOutput(
            $fixer,
            "<?php\n\$values = ['one', 'two'];",
            "<?php\n\$values = [\n    'one',\n    'two'\n];",
        );
    }

    public function testConfiguredWhitespace(): void
    {
        $fixer = new ArrayWrappingFixer();
        $fixer->setWhitespacesConfig(new WhitespacesFixerConfig("\t", "\r\n"));

        $this->assertFixerOutput(
            $fixer,
            "<?php\r\n\$values = [1,\r\n2];",
            "<?php\r\n\$values = [\r\n\t1,\r\n\t2\r\n];",
        );
    }

    #[DataProvider('provideInvalidWidths')]
    public function testRejectsInvalidWidth(mixed $width): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ArrayWrappingFixer()->configure(['max_line_length' => $width]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidWidths(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];

        yield 'numeric string' => ['100'];

        yield 'float' => [100.0];

        yield 'null' => [null];
    }
}
