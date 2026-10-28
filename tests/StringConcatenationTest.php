<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use PhpCsFixer\FixerFactory;
use Noldors\PhpCsFixer\Config;
use PhpCsFixer\RuleSet\RuleSet;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;

final class StringConcatenationTest extends TestCase
{
    #[DataProvider('stringExpressions')]
    public function testTheFullPresetPreservesStringBytesAndIsIdempotent(string $expression, string $expectedHex): void
    {
        $input = "<?php\n\$suffix = 'tail';\nreturn {$expression};\n";
        $this->assertSame(
            $expectedHex,
            \bin2hex($this->evaluate($input)),
            'The input must match the independently specified bytes.',
        );

        $actual = $this->format($input);

        $this->assertSame($expectedHex, \bin2hex($this->evaluate($actual)), $actual);
        $this->assertSame($actual, $this->format($actual), 'Formatting must be idempotent.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stringExpressions(): iterable
    {
        yield 'TAR magic and version' => ['"ustar\\0" . \'00\'', '7573746172003030'];

        yield 'same quoted octal boundary' => ['"\\0" . "0\\n"', '00300a'];

        yield 'two digit octal boundary' => ['"\\12" . \'3\'', '0a33'];

        yield 'same quoted hexadecimal boundary' => ['"\\x4" . "1\\n"', '04310a'];

        yield 'mixed quoted hexadecimal boundary' => ['"\\x4" . \'1\'', '0431'];

        yield 'uppercase hexadecimal without concatenation' => ['"\\X41"', '41'];

        yield 'uppercase hexadecimal heredoc' => ["<<<TEXT\n\\X41\nTEXT", '41'];

        yield 'uppercase hexadecimal boundary' => ['"\\X4" . \'f\'', '0466'];

        yield 'interpolated right operand' => ['"\\0" . "0{$suffix}"', '00307461696c'];

        yield 'interpolated left operand' => ['"{$suffix}\\0" . "0\\n"', '7461696c00300a'];

        yield 'chained concatenation' => ['"\\0" . \'0\' . \'0\'', '003030'];

        yield 'binary string literals' => ['b"\\0" . B\'00\'', '003030'];

        yield 'non-octal digit' => ['"\\0" . \'8\'', '0038'];

        yield 'complete octal escape' => ['"\\000" . \'00\'', '003030'];

        yield 'complete hexadecimal escape' => ['"\\x41" . \'f\'', '4166'];

        yield 'escaped backslash and escape letter' => ['"\\\\" . \'n\'', '5c6e'];

        yield 'single quoted backslash' => ['\'\\\\\' . \'n\'', '5c6e'];

        yield 'single quoted literal escape' => ['\'\\0\' . \'00\'', '5c303030'];

        yield 'escaped dollar' => ['"\\$" . \'suffix\'', '24737566666978'];

        yield 'ordinary single quoted strings' => ['\'hello\' . \'world\'', '68656c6c6f776f726c64'];

        yield 'ordinary mixed quoted strings' => ['"hello" . \'world\'', '68656c6c6f776f726c64'];

        yield 'empty strings' => ['\'\' . \'value\' . ""', '76616c7565'];

        yield 'line feed escape' => ['"line\\n" . \'end\'', '6c696e650a656e64'];

        yield 'unicode escape' => ['"\\u{41}" . \'0\'', '4130'];

        yield 'numeric literals' => ['"\\0" . 00', '0030'];

        yield 'comment at the boundary' => ['"\\0" /* boundary */ . \'00\'', '003030'];

        yield 'heredoc boundary' => ["<<<TEXT\n\\0\nTEXT\n . '00'", '003030'];

        yield 'nowdoc boundary' => ["<<<'TEXT'\n\\0\nTEXT\n . '00'", '5c303030'];
    }

    public function testOrdinaryConcatenationsRemainFormattedAndStable(): void
    {
        $input = "<?php\nreturn 'hello'.'world';\n";
        $actual = $this->format($input);

        $this->assertStringContainsString("return 'hello' . 'world';", $actual);
        $this->assertSame('helloworld', $this->evaluate($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    private function evaluate(string $code): string
    {
        // Only the fixed expressions above and their formatter output are evaluated.
        $value = eval(\mb_substr($code, 5));
        $this->assertIsString($value);

        return $value;
    }

    private function format(string $code): string
    {
        $config = Config::create(__DIR__);
        $factory = new FixerFactory()
            ->registerBuiltInFixers()
            ->registerCustomFixers($config->getCustomFixers())
            ->useRuleSet(new RuleSet($config->getRules()));
        $tokens = Tokens::fromCode($code);

        foreach ($factory->getFixers() as $fixer) {
            $fixer->fix(new SplFileInfo('example.php'), $tokens);
            $tokens->clearEmptyTokens();
        }

        return $tokens->generateCode();
    }
}
