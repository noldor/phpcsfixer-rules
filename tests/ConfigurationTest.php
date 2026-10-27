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
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigurationTest extends TestCase
{
    #[DataProvider('readableExpressions')]
    public function testTheFullPresetChoosesTheExpressionBoundary(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readableExpressions(): iterable
    {
        $long = \str_repeat('x', 85);

        yield 'ternary condition branches keep their scope' => [
            "<?php\nif (\$pick ? \$a && \$b : \$c && \\check('{$long}')) {}\n",
            "<?php\n\ndeclare(strict_types = 1);\n\nif (\$pick\n    ? \$a && \$b\n    : \$c && \\check('{$long}')\n) {\n}\n",
        ];

        yield 'explicitly grouped ternary condition is retained' => [
            "<?php\n\$value = (\$first && \\check('{$long}')) ? \$yes : \$no;\n",
            "<?php\n\ndeclare(strict_types = 1);\n\$value = (\$first && \\check('{$long}'))\n    ? \$yes\n    : \$no;\n",
        ];

        yield 'sibling ternaries in arrays keep independent indentation' => [
            "<?php\n\$values = [\$a ? \\check('{$long}') : \$b, \$c ? \\check('{$long}') : \$d];\n",
            "<?php\n\ndeclare(strict_types = 1);\n\$values = [\n    \$a\n        ? \\check('{$long}')\n        : \$b,\n    \$c\n        ? \\check('{$long}')\n        : \$d,\n];\n",
        ];

        yield 'sibling ternaries in arguments keep independent indentation' => [
            "<?php\n\$o->run(\$a ? \\check('{$long}') : \$b, \$c ? \\check('{$long}') : \$d);\n",
            "<?php\n\ndeclare(strict_types = 1);\n\$o->run(\n    \$a\n        ? \\check('{$long}')\n        : \$b,\n    \$c\n        ? \\check('{$long}')\n        : \$d,\n);\n",
        ];

        yield 'long boolean condition' => [
            "<?php\n\ndeclare(strict_types = 1);\nif (\$result instanceof Deferred || \$result instanceof Promise || Executor::getDefaultPromiseAdapter()->isThenable(\$result)) {\n    \\consume(\$result);\n}\n",
            "<?php\n\ndeclare(strict_types = 1);\n\nif (\$result instanceof Deferred\n    || \$result instanceof Promise\n    || Executor::getDefaultPromiseAdapter()->isThenable(\$result)\n) {\n    \\consume(\$result);\n}\n",
        ];

        yield 'boolean condition in a ternary assignment' => [
            "<?php\n\ndeclare(strict_types = 1);\n\$correlationId = \\is_string(\$header) && Str::isUuid(\$header) && '00000000-0000-0000-0000-000000000000' !== \$header ? \\mb_strtolower(\$header) : \$executionId;\n",
            "<?php\n\ndeclare(strict_types = 1);\n\$correlationId = \\is_string(\$header)\n    && Str::isUuid(\$header)\n    && '00000000-0000-0000-0000-000000000000' !== \$header\n        ? \\mb_strtolower(\$header)\n        : \$executionId;\n",
        ];

        $factory = "<?php\n\ndeclare(strict_types = 1);\nUser::factory()->create([\n    'name' => 'Test User',\n    'email' => 'test@example.com',\n]);\n";

        yield 'multiline array body keeps a compact factory chain' => [$factory, $factory];

        $command = "<?php\n\ndeclare(strict_types = 1);\nArtisan::command('inspire', function (): void {\n    \$this->comment(Inspiring::quote());\n})->purpose('Display an inspiring quote');\n";

        yield 'multiline closure body keeps a compact command continuation' => [$command, $command];

        yield 'short array before a long second argument' => [
            "<?php\n\ndeclare(strict_types = 1);\n\$this->assertSame([1, 2], \\array_map(static fn (\$record): mixed => \$record->extra['jobAttempt'], \$retry));\n",
            "<?php\n\ndeclare(strict_types = 1);\n\$this->assertSame(\n    [1, 2],\n    \\array_map(static fn (\$record): mixed => \$record->extra['jobAttempt'], \$retry),\n);\n",
        ];
    }

    public function testTheFullPresetKeepsACompactHeaderWithAMultilineArray(): void
    {
        $input = <<<'PHP'
            <?php

            declare(strict_types = 1);
            Context::add([
                'correlationId' => $correlationId,
                'executionId' => $executionId,
                'source' => 'http',
            ]);

            PHP;

        $this->assertSame($input, $this->format($input));
        $this->assertSame($input, $this->format($this->format($input)));
    }

    public function testTheFullPresetKeepsAShortCallHeaderWithAMultilineClosure(): void
    {
        $input = <<<'PHP'
            <?php

            declare(strict_types = 1);
            Schema::create('users', static function (Blueprint $table): void {
                $table->id();
                $table->string('email')->unique();
            });

            PHP;

        $this->assertSame($input, $this->format($input));
        $this->assertSame($input, $this->format($this->format($input)));
    }

    public function testArgumentCountWrappingAlsoSplitsTheChainInTheSamePass(): void
    {
        $input = "<?php\n\$o->run(1, 2, 3, 4)->next();\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$o->run(\n    1,\n    2,\n    3,\n    4,\n)\n    ->next();\n";

        $this->assertSame($expected, $this->format($input));
        $this->assertSame($expected, $this->format($expected));
    }

    public function testMovingAContinuationReindentsItsMultilineArrayInTheSamePass(): void
    {
        $input = "<?php\nUser::factory()->create([\n    'name' => 'Test User',\n])->finish(1, 2, 3, 4);\n";
        $expected = "<?php\n\ndeclare(strict_types = 1);\nUser::factory()\n    ->create([\n        'name' => 'Test User',\n    ])\n    ->finish(\n        1,\n        2,\n        3,\n        4,\n    );\n";

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
        $expected = "<?php\n\ndeclare(strict_types = 1);\n\$result = \$service->withOptions([\n    'first' => '{$value}',\n    'second' => true,\n])\n    ->send(\n        1,\n        2,\n        3,\n        4,\n    );\n";

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

        return \array_values(\array_map(static fn (array|string $token): array|string => \is_array($token)
            ? [
                $token[0],
                $token[1],
            ]
            : $token, $tokens));
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
