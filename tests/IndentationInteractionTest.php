<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use LogicException;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\AbstractFixer;
use Noldors\PhpCsFixer\Config;
use PhpCsFixer\RuleSet\RuleSet;
use PhpCsFixer\Tokenizer\Token;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Config as NativeConfig;
use PhpCsFixer\WhitespacesFixerConfig;
use PhpCsFixer\FixerDefinition\CodeSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use Noldors\PhpCsFixer\Internal\LayoutStabilizationFixer;

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
        $once = $this->format($input);

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
        $once = $this->format($input);

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

    public function testStabilizationHonorsDisabledNativeRulesFromTheFinalConfiguration(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules([...Config::rules(), 'strict_param' => false]);
        $input = "<?php\n\$found = in_array(\$needle, \$values);\n";
        $actual = $this->format($input, $config);

        $this->assertStringContainsString('in_array($needle, $values)', $actual);
        $this->assertStringNotContainsString('in_array($needle, $values, true)', $actual);
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testStabilizationHonorsNativeRuleOptions(): void
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

    public function testStabilizationUsesTheConsumersIndentationAndLineEndings(): void
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

    public function testStabilizationHonorsDisabledLayoutRules(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules([...Config::rules(), 'Noldors/method_call_wrapping' => false]);
        $actual = $this->format("<?php\n\$o->run(1, 2, 3, 4);\n", $config);

        $this->assertStringContainsString('$o->run(1, 2, 3, 4)', $actual);
        $this->assertSame($actual, $this->format($actual, $config));
    }

    public function testStabilizationHonorsTheFinalLayoutRuleOptions(): void
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

    public function testThePassBoundDoesNotLimitSyntaxNestingDepth(): void
    {
        $argument = \str_repeat('$o->inner(', 24) . "'value'" . \str_repeat(')', 24);
        $input = "<?php\n\$o->first()->with(\n    {$argument},\n    2,\n    3,\n    4\n);\n";
        $once = $this->format($input);

        $this->assertSame(24, \mb_substr_count($once, '->inner('));
        $this->assertSame($once, $this->format($once));
    }

    public function testStabilizationRejectsARepeatedOutputCycle(): void
    {
        $fixer = $this->coordinatorWithChangingWhitespace(true);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cycle');

        $fixer->fix(new SplFileInfo('example.php'), Tokens::fromCode("<?php\n\$a = 1;\n"));
    }

    public function testStabilizationRejectsNonConvergingRulesWithinABoundedPassCount(): void
    {
        $fixer = $this->coordinatorWithChangingWhitespace(false);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('16 passes');

        $fixer->fix(new SplFileInfo('example.php'), Tokens::fromCode("<?php\n\$a = 1;\n"));
    }

    private function coordinatorWithChangingWhitespace(bool $cycle): LayoutStabilizationFixer
    {
        $changing = new class ($cycle) extends AbstractFixer {
            public function __construct(
                private readonly bool $cycle,
            ) {
                parent::__construct();
            }

            public function getName(): string
            {
                return 'Testing/changing_whitespace';
            }

            public function getDefinition(): FixerDefinitionInterface
            {
                return new FixerDefinition('Test-only changing whitespace.', [
                    new CodeSample("<?php\n\$a = 1;\n"),
                ]);
            }

            public function isCandidate(Tokens $tokens): bool
            {
                return true;
            }

            protected function applyFix(SplFileInfo $file, Tokens $tokens): void
            {
                $index = \count($tokens) - 1;
                $previous = $tokens[$index]->getContent();
                $next = $this->cycle ? ("\n" === $previous ? "\n\n" : "\n") : $previous . "\n";
                $tokens[$index] = new Token([\T_WHITESPACE, $next]);
            }
        };

        return new LayoutStabilizationFixer(
            ['Testing/changing_whitespace' => true, 'Noldors/layout_stabilization' => true],
            [$changing],
            new WhitespacesFixerConfig('    ', "\n"),
        );
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
