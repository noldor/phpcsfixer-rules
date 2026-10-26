<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use PhpCsFixer\FixerFactory;
use Noldors\PhpCsFixer\Config;
use PhpCsFixer\RuleSet\RuleSet;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Config as NativeConfig;
use PhpCsFixer\WhitespacesFixerConfig;
use PHPUnit\Framework\Attributes\DataProvider;

final class IndentationInteractionTest extends TestCase
{
    #[DataProvider('widthBoundaries')]
    public function testTheFullPresetIsStableAtWidthBoundaries(string $prefix, string $suffix, bool $insideBlock): void
    {
        $line = $prefix . \str_repeat('x', 100 - \mb_strlen($prefix . $suffix)) . $suffix;
        $this->assertSame(100, \mb_strlen($line));
        $input = $insideBlock
            ? "<?php\nif (\$condition) {\n{$line}\n}\n"
            : "<?php\n{$line}\n";
        $once = $this->formatUntilStable($input);

        $this->assertSame($once, $this->format($once));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function widthBoundaries(): iterable
    {
        yield 'native statement indentation before a chain' => [
            "\$o->first('",
            "')->second();",
            true,
        ];

        yield 'native statement indentation before an array' => ["\$items = ['", "'];", true];

        yield 'native comma spacing before a method call' => ["\$o->run('", "',\$a,\$b);", false];
    }

    #[DataProvider('compoundArguments')]
    public function testChainAndArgumentIndentationReachAStableLayout(string $prefix, string $suffix): void
    {
        $argument = $prefix . \str_repeat('x', 100 - \mb_strlen($prefix . $suffix)) . $suffix;
        $this->assertSame(100, \mb_strlen($argument));
        $input = "<?php\n\$o->first()->with(\n{$argument}\n    2,\n    3,\n    4\n);\n";
        $once = $this->formatUntilStable($input);

        $this->assertSame($once, $this->format($once));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compoundArguments(): iterable
    {
        yield 'nested method' => ["    \$o->inner('", "'),"];

        yield 'array argument' => ["    ['", "'],"];
    }

    public function testConfigurationHonorsDisabledNativeRulesFromTheFinalConfiguration(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules([...Config::rules(), 'strict_param' => false]);
        $input = "<?php\n\$found = in_array(\$needle, \$values);\n";
        $actual = $this->format($input, $config);

        $this->assertStringContainsString('in_array($needle, $values)', $actual);
        $this->assertStringNotContainsString('in_array($needle, $values, true)', $actual);
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testConfigurationHonorsNativeRuleOptions(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules([...Config::rules(), 'concat_space' => ['spacing' => 'none']]);
        $prefix = "\$o->run(\$value . '";
        $suffix = "');";
        $line = $prefix . \str_repeat('x', 101 - \mb_strlen($prefix . $suffix)) . $suffix;
        $actual = $this->format("<?php\n{$line}\n", $config);

        $this->assertStringContainsString("\$value.'", $actual);
        $this->assertStringNotContainsString("\$value . '", $actual);
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testConfigurationUsesTheConsumersIndentationAndLineEndings(): void
    {
        $config = Config::create(__DIR__);
        $config->setIndent("\t");
        $config->setLineEnding("\r\n");
        $input = "<?php\nif (\$condition) {\n\$o->run(1, 2, 3, 4);\n}\n";
        $actual = $this->format($input, $config);

        $this->assertStringContainsString("\r\n\t\$o->run(\r\n\t\t1,", $actual);
        $this->assertStringNotContainsString("\n", \str_replace("\r\n", '', $actual));
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testConfigurationHonorsDisabledLayoutRules(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules([...Config::rules(), 'Noldors/method_call_wrapping' => false]);
        $actual = $this->format("<?php\n\$o->run(1, 2, 3, 4);\n", $config);

        $this->assertStringContainsString('$o->run(1, 2, 3, 4)', $actual);
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testConfigurationHonorsTheFinalLayoutRuleOptions(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules(
            [
                ...Config::rules(),
                'Noldors/method_call_wrapping' => [
                    'max_line_length' => 200,
                    'max_single_line_arguments' => 5,
                ],
            ],
        );
        $argument = \str_repeat('x', 110);
        $actual = $this->format("<?php\n\$o->run('{$argument}', 2, 3, 4);\n", $config);

        $this->assertStringContainsString("\$o->run('{$argument}', 2, 3, 4)", $actual);
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testRepeatedFormattingSupportsDeepSyntaxNesting(): void
    {
        $argument = \str_repeat('$o->inner(', 24) . "'value'" . \str_repeat(')', 24);
        $input = "<?php\n\$o->first()->with(\n    {$argument},\n    2,\n    3,\n    4\n);\n";
        $once = $this->formatUntilStable($input);

        $this->assertSame(24, \mb_substr_count($once, '->inner('));
        $this->assertSame($once, $this->format($once));
    }

    private function formatUntilStable(string $code): string
    {
        for ($pass = 0; 32 > $pass; ++$pass) {
            $next = $this->format($code);

            if ($next === $code) {
                return $next;
            }

            $code = $next;
        }

        $this->fail('Repeated CLI-equivalent formatting did not settle.');
    }

    private function format(string $code, NativeConfig|null $config = null): string
    {
        $config ??= Config::create(__DIR__);
        $factory = new FixerFactory()
            ->registerBuiltInFixers()
            ->registerCustomFixers($config->getCustomFixers())
            ->useRuleSet(new RuleSet($config->getRules()))
            ->setWhitespacesConfig(
                new WhitespacesFixerConfig($config->getIndent(), $config->getLineEnding()),
            );
        $tokens = Tokens::fromCode($code);

        foreach ($factory->getFixers() as $fixer) {
            $fixer->fix(new SplFileInfo('example.php'), $tokens);
            $tokens->clearEmptyTokens();
        }

        return $tokens->generateCode();
    }
}
