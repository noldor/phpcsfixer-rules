<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use PhpCsFixer\FixerFactory;
use Noldors\PhpCsFixer\Config;
use PhpCsFixer\RuleSet\RuleSet;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\FixerInterface;

final class ConfigurationTest extends TestCase
{
    public function testArgumentCountWrappingAlsoSplitsTheChainInTheSamePass(): void
    {
        $input = "<?php\n\$o->run(1, 2, 3, 4)->next();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$o->run(\n    1,\n    2,\n    3,\n    4,\n)\n    ->next();\n";

        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    public function testThePublishedConfigurationProducesStableCombinedLayout(): void
    {
        $value = \str_repeat('x', 70);
        $input = "<?php\n\$result = Service::fetch(1, 2, 3, 4)->using('{$value}')->send();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$result = Service::fetch(\n    1,\n    2,\n    3,\n    4,\n)\n    ->using(\n        '{$value}',\n    )\n    ->send();\n";

        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    public function testNestedArraysAndCallsKeepTheirIndentationAcrossRuns(): void
    {
        $value = \str_repeat('x', 70);
        $input = "<?php\n\$result = \$service->withOptions(['first' => '{$value}', 'second' => true])->send(1, 2, 3, 4);\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$result = \$service->withOptions(\n    [\n        'first' => '{$value}',\n        'second' => true,\n    ],\n)\n    ->send(\n        1,\n        2,\n        3,\n        4,\n    );\n";

        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    public function testStaticContinuationStartsOnItsOwnLineAfterArgumentWrapping(): void
    {
        $value = \str_repeat('x', 90);
        $input = "<?php\n\$o->first('{$value}')::finish();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$o->first(\n    '{$value}',\n)\n    ::finish();\n";

        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    public function testMixedNullsafeAndStaticContinuationsRemainSeparated(): void
    {
        $value = \str_repeat('x', 90);
        $input = "<?php\n\$o?->first('{$value}')?->next()::finish()?->last();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$o?->first(\n    '{$value}',\n)\n    ?->next()\n    ::finish()\n    ?->last();\n";

        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    public function testSeparatingAnExistingStaticContinuationPreservesSignificantTokens(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\n\$o->first(\n    'value',\n)::finish();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$o->first(\n    'value',\n)\n    ::finish();\n";
        $actual = $this->format($input);

        $this->assertSame($expected, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    public function testNativeSpacingCanonicalizesAnOtherwiseShortStaticChain(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\nHandler::first()\n    ::second();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\nHandler::first()::second();\n";
        $actual = $this->format($input);

        $this->assertSame($expected, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    public function testExistingObjectAndNullsafeBreaksPreserveShortStaticContinuations(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\n\$o->first()\n    ?->next()\n    ::finish();\n";
        $actual = $this->format($input);

        $this->assertSame($input, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    public function testNativeOperatorSpacingAndIndentationRunBeforeFluentWrapping(): void
    {
        $config = Config::create(__DIR__);
        $fixers = new FixerFactory()
            ->registerBuiltInFixers()
            ->registerCustomFixers($config->getCustomFixers())
            ->useRuleSet(new RuleSet($config->getRules()))
            ->getFixers();
        $names = \array_map(static fn (FixerInterface $fixer): string => $fixer->getName(), $fixers);
        $relevant = [
            'no_space_around_double_colon',
            'method_chaining_indentation',
            'Noldors/fluent_chain_wrapping',
        ];

        $this->assertSame(
            $relevant,
            \array_values(\array_filter($names, static fn (string $name): bool => \in_array($name, $relevant, true))),
        );
    }

    public function testConstructorReceiverContinuationsKeepTheSameIndentation(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\n\$result = new Handler()\n    ->first()\n    ->second();\n";
        $actual = $this->format($input);

        $this->assertSame($input, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    public function testFreeFunctionReceiverContinuationsKeepTheSameIndentation(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\n\$result = \\makeHandler()\n    ->first()\n    ->second();\n";
        $actual = $this->format($input);

        $this->assertSame($input, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    public function testConstructorReceiverStaticContinuationsKeepTheSameIndentation(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\n\$result = new Handler()\n    ->first()\n    ::second();\n";
        $actual = $this->format($input);

        $this->assertSame($input, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    public function testFreeFunctionReceiverStaticContinuationsKeepTheSameIndentation(): void
    {
        $input = "<?php\n\ndeclare(strict_types = 1);\n\$result = \\makeHandler()\n    ->first()\n    ::second();\n";
        $actual = $this->format($input);

        $this->assertSame($input, $actual);
        $this->assertSame($this->significantTokens($input), $this->significantTokens($actual));
        $this->assertSame($actual, $this->format($actual));
    }

    /**
     * @return list<array{int, string}|string>
     */
    private function significantTokens(string $code): array
    {
        $tokens = \array_filter(\token_get_all($code, \TOKEN_PARSE), static fn (array|string $token): bool => ! \is_array($token) || \T_WHITESPACE !== $token[0]);

        return \array_values(\array_map(static fn (array|string $token): array|string => \is_array($token) ? [
            $token[0],
            $token[1],
        ] : $token, $tokens));
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
