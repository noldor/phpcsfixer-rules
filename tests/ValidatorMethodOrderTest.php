<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use SplFileInfo;
use InvalidArgumentException;
use Noldors\PhpCsFixer\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use PhpCsFixer\Config\RuleCustomisationPolicyInterface;

final class ValidatorMethodOrderTest extends TestCase
{
    public function testNativeOrderingIsSelectedOnlyForDedicatedValidatorFiles(): void
    {
        $input = <<<'PHP'
            <?php
            final class Example
            {
                /** Attribute labels. */
                public function attributes(): array { return ['title' => 'Attribute literal']; }
                public function helper(): string { return "Line\n\x41"; }
                // Validation messages.
                public function messages(): array { return ['title.required' => 'Message literal']; }
                #[Marker('rules-literal')]
                public function rules(): array { return ['title' => ['required', 'string']]; }
            }
            PHP;
        $expected = <<<'PHP'
            <?php
            final class Example
            {
                #[Marker('rules-literal')]
                public function rules(): array { return ['title' => ['required', 'string']]; }
                // Validation messages.
                public function messages(): array { return ['title.required' => 'Message literal']; }
                /** Attribute labels. */
                public function attributes(): array { return ['title' => 'Attribute literal']; }
                public function helper(): string { return "Line\n\x41"; }
            }
            PHP;

        $result = $this->formatFiles($input);
        $this->assertSame($expected, $result['validator']);
        $this->assertSame($input, $result['unselected']);
        $this->assertSame($this->significantTokenBag($input), $this->significantTokenBag($result['validator']));
        $this->assertSame($result, $this->formatFiles($result['validator'], $result['unselected']));
    }

    public function testOrderingIsOptIn(): void
    {
        $config = Config::create(__DIR__);
        $this->assertNull($config->getRuleCustomisationPolicy());
        $rule = $config->getRules()['ordered_class_elements'];
        $this->assertIsArray($rule);
        $order = $rule['order'] ?? null;
        $this->assertIsArray($order);
        $this->assertNotContains('method:rules', $order);
    }

    public function testAnExistingUnrelatedPolicyAndItsCacheVersionArePreserved(): void
    {
        $original = new class () implements RuleCustomisationPolicyInterface {
            public function getPolicyVersionForCache(): string
            {
                return 'existing-v1';
            }

            public function getRuleCustomisers(): array
            {
                return [
                    'strict_param' => static fn (SplFileInfo $file): bool => false,
                ];
            }
        };
        $config = Config::create(__DIR__);
        $config->setRuleCustomisationPolicy($original);
        $config->withValidatorMethodOrder(static fn (SplFileInfo $file): bool => false, 'validators-v1');
        $policy = $config->getRuleCustomisationPolicy();
        $this->assertNotNull($policy);
        $this->assertFalse(
            $policy->getRuleCustomisers()['strict_param'](new SplFileInfo('other.php')),
        );
        $this->assertTrue(
            $policy->getRuleCustomisers()['ordered_class_elements'](new SplFileInfo('other.php')),
        );
        $this->assertNotSame($original->getPolicyVersionForCache(), $policy->getPolicyVersionForCache());
        $other = Config::create(__DIR__);
        $other->setRuleCustomisationPolicy($original);
        $other->withValidatorMethodOrder(static fn (SplFileInfo $file): bool => false, 'validators-v2');
        $this->assertNotSame(
            $policy->getPolicyVersionForCache(),
            $other->getRuleCustomisationPolicy()?->getPolicyVersionForCache(),
        );
    }

    public function testAnExistingOrderedClassPolicyIsRejectedClearly(): void
    {
        $config = Config::create(__DIR__);
        $config->setRuleCustomisationPolicy(
            new class () implements RuleCustomisationPolicyInterface {
                public function getPolicyVersionForCache(): string
                {
                    return 'existing-v1';
                }

                public function getRuleCustomisers(): array
                {
                    return [
                        'ordered_class_elements' => static fn (SplFileInfo $file): bool => true,
                    ];
                }
            },
        );
        $config->withValidatorMethodOrder(static fn (SplFileInfo $file): bool => true, 'validators-v1');
        $policy = $config->getRuleCustomisationPolicy();
        $this->assertNotNull($policy);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ordered_class_elements');
        $policy->getRuleCustomisers();
    }

    public function testAnEmptyCacheVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Config::create(__DIR__)->withValidatorMethodOrder(static fn (SplFileInfo $file): bool => true, '');
    }

    public function testDisabledNativeOrderingIsRejected(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules(['ordered_class_elements' => false]);
        $config->withValidatorMethodOrder(static fn (SplFileInfo $file): bool => true, 'validators-v1');
        $policy = $config->getRuleCustomisationPolicy();
        $this->assertNotNull($policy);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enable ordered_class_elements');
        $policy->getRuleCustomisers();
    }

    public function testNativeDefaultConfigurationCanBeSelected(): void
    {
        $input = '<?php class V { public function attributes() {} public function messages() {} public function rules() {} }';
        $expected = '<?php class V { public function rules() {} public function messages() {} public function attributes() {} }';
        $result = $this->formatFiles($input, nativeRules: ['ordered_class_elements' => true]);
        $this->assertSame($expected, $result['validator']);
        $this->assertSame($input, $result['unselected']);
    }

    public function testNativeRuleSetActivationKeepsUnselectedMethodOrder(): void
    {
        $input = '<?php class V { public function zeta() {} public function attributes() {} public function alpha() {} public function messages() {} public function rules() {} }';
        $result = $this->formatFiles($input, nativeRules: ['@Symfony' => true]);
        $this->assertSame(
            ['rules', 'messages', 'attributes', 'zeta', 'alpha'],
            $this->methodNames($result['validator']),
        );
        $this->assertSame(
            ['zeta', 'attributes', 'alpha', 'messages', 'rules'],
            $this->methodNames($result['unselected']),
        );
        $this->assertSame($result, $this->formatFiles($result['validator'], $result['unselected'], nativeRules: [
            '@Symfony' => true,
        ]));
    }

    public function testRuleSetAndExplicitSortConfigurationArePreserved(): void
    {
        $input = '<?php class V { public function zeta() {} public function attributes() {} public function alpha() {} public function messages() {} public function rules() {} }';
        $rules = [
            '@Symfony' => true,
            'ordered_class_elements' => ['order' => ['method_public'], 'sort_algorithm' => 'alpha'],
        ];
        $result = $this->formatFiles($input, nativeRules: $rules);
        $this->assertSame(
            ['rules', 'messages', 'attributes', 'alpha', 'zeta'],
            $this->methodNames($result['validator']),
        );
        $this->assertSame(
            ['alpha', 'attributes', 'messages', 'rules', 'zeta'],
            $this->methodNames($result['unselected']),
        );
    }

    public function testExplicitDisablingOverridesNativeRuleSetActivation(): void
    {
        $config = Config::create(__DIR__);
        $config->setRules(['@Symfony' => true, 'ordered_class_elements' => false]);
        $config->withValidatorMethodOrder(static fn (SplFileInfo $file): bool => true, 'validators-v1');
        $policy = $config->getRuleCustomisationPolicy();
        $this->assertNotNull($policy);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enable ordered_class_elements');
        $policy->getRuleCustomisers();
    }

    public function testRegisteredCustomRuleSetsUseTheNativeLifecycleAndCacheVersion(): void
    {
        $input = '<?php class V { public function zeta() {} public function attributes() {} public function alpha() {} public function messages() {} public function rules() {} }';
        $result = $this->formatFiles(
            $input,
            nativeRules: ['@Application/Validators' => true],
            customRuleSet: true,
            warmCache: true,
        );
        $this->assertSame(
            ['rules', 'messages', 'attributes', 'alpha', 'zeta'],
            $this->methodNames($result['validator']),
        );
        $this->assertSame(
            ['alpha', 'attributes', 'messages', 'rules', 'zeta'],
            $this->methodNames($result['unselected']),
        );
    }

    public function testExistingPoliciesResolveCustomSetsOnlyWhenTheNativeRunnerRequestsThem(): void
    {
        $input = '<?php class V { public function attributes() {} public function messages() {} public function rules() {} }';
        $result = $this->formatFiles(
            $input,
            nativeRules: ['@Application/Validators' => true],
            customRuleSet: true,
            previousPolicy: true,
        );
        $this->assertSame(['rules', 'messages', 'attributes'], $this->methodNames($result['validator']));
        $this->assertSame(['attributes', 'messages', 'rules'], $this->methodNames($result['unselected']));
    }

    /**
     * @param array<string, array<string, mixed>|bool>|null $nativeRules
     *
     * @return array{validator: string, unselected: string}
     */
    private function formatFiles(string $validator, string|null $unselected = null, array|null $nativeRules = null, bool $customRuleSet = false, bool $warmCache = false, bool $previousPolicy = false): array
    {
        $filesystem = new Filesystem();
        $root = \sys_get_temp_dir() . '/noldors-validator-order-' . \bin2hex(\random_bytes(8));
        $autoload = \var_export(__DIR__ . '/../vendor/autoload.php', true);
        $configuration = <<<'PHP'
            $config = \Noldors\PhpCsFixer\Config::create(__DIR__);
            $config->setRules({{rules}});
            $config->withValidatorMethodOrder(static fn (\SplFileInfo $file): bool => $file->getFilename() === 'Validator.php', 'validator-files-v1');
            return $config;
            PHP;

        $rules = $nativeRules ?? ['ordered_class_elements' => Config::rules()['ordered_class_elements']];
        $configuration = \str_replace('{{rules}}', \var_export($rules, true), $configuration);

        if ($customRuleSet) {
            $definition = <<<'PHP'
                $config->registerCustomRuleSets([new class implements \PhpCsFixer\RuleSet\RuleSetDefinitionInterface {
                    public function getDescription(): string { return 'Application validator profile'; }
                    public function getName(): string { return '@Application/Validators'; }
                    public function getRules(): array { return ['ordered_class_elements' => ['order' => ['method_public'], 'sort_algorithm' => 'alpha'], 'blank_line_after_namespace' => true]; }
                    public function isRisky(): bool { return false; }
                }]);
                PHP;
            $configuration = \str_replace('$config->setRules(', $definition . "\n" . '$config->setRules(', $configuration);
        }

        if ($previousPolicy) {
            $policy = <<<'PHP'
                $config->setRuleCustomisationPolicy(new class implements \PhpCsFixer\Config\RuleCustomisationPolicyInterface {
                    public function getPolicyVersionForCache(): string { return 'prior-v1'; }
                    public function getRuleCustomisers(): array {
                        $effective = new \PhpCsFixer\RuleSet\RuleSet(['@Application/Validators' => true]);
                        return ['blank_line_after_namespace' => static fn (\SplFileInfo $file): bool => $effective->hasRule('blank_line_after_namespace')];
                    }
                });
                PHP;
            $configuration = \str_replace('$config->withValidatorMethodOrder(', $policy . "\n" . '$config->withValidatorMethodOrder(', $configuration);
        }

        try {
            $filesystem->dumpFile($root . '/Validator.php', $validator);
            $filesystem->dumpFile($root . '/Service.php', $unselected ?? $validator);
            $filesystem->dumpFile($root . '/composer.json', '{"require":{"php":">=8.5"}}');
            $command = [
                \PHP_BINARY,
                __DIR__ . '/../vendor/bin/php-cs-fixer',
                'fix',
                '--config=' . $root . '/config.php',
                '--using-cache=' . ($warmCache ? 'yes' : 'no'),
                '--sequential',
                $root . '/Validator.php',
                $root . '/Service.php',
            ];

            if ($warmCache) {
                $initial = \str_replace("\$file->getFilename() === 'Validator.php'", 'false', $configuration);
                $initial = \str_replace('validator-files-v1', 'validator-files-v0', $initial);
                $filesystem->dumpFile($root . '/config.php', "<?php\nrequire {$autoload};\n{$initial}\n");
                $warmup = new Process($command, $root);
                $this->assertSame(0, $warmup->run(), $warmup->getOutput() . $warmup->getErrorOutput());
            }
            $filesystem->dumpFile($root . '/config.php', "<?php\nrequire {$autoload};\n{$configuration}\n");
            $process = new Process($command, $root);
            $this->assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
            $validatorResult = \file_get_contents($root . '/Validator.php');
            $unselectedResult = \file_get_contents($root . '/Service.php');
            $this->assertIsString($validatorResult);
            $this->assertIsString($unselectedResult);

            return ['validator' => $validatorResult, 'unselected' => $unselectedResult];
        } finally {
            $filesystem->remove($root);
        }
    }

    /**
     * @return list<string>
     */
    private function methodNames(string $code): array
    {
        \preg_match_all('/function\\s+(\\w+)\\s*\\(/', $code, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function significantTokenBag(string $code): array
    {
        $tokens = [];

        foreach (\token_get_all($code, \TOKEN_PARSE) as $token) {
            if (\is_array($token) && \T_WHITESPACE === $token[0]) {
                continue;
            }
            $tokens[] = \is_array($token) ? $token[0] . ':' . $token[1] : $token;
        }
        \sort($tokens);

        return $tokens;
    }
}
