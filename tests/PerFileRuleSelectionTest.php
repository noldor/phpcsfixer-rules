<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use Closure;
use SplFileInfo;
use RuntimeException;
use Noldors\PhpCsFixer\Config;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\FixerInterface;
use Noldors\PhpCsFixer\Fixer\ArrayWrappingFixer;
use Noldors\PhpCsFixer\Fixer\MethodCallWrappingFixer;
use PhpCsFixer\Config\RuleCustomisationPolicyInterface;

final class PerFileRuleSelectionTest extends TestCase
{
    public function testAFileAnnotationCanIgnoreANativeRuleDuringStabilization(): void
    {
        $input = "<?php\n// @php-cs-fixer-ignore strict_param\nin_array(\$needle, \$haystack);\n";

        $this->assertSame($input, $this->stabilize($input, ['strict_param' => true]));
    }

    public function testAFileAnnotationCanIgnoreACustomLayoutRuleDuringStabilization(): void
    {
        $input = "<?php\n// @php-cs-fixer-ignore Noldors/method_call_wrapping\n\$o->run(1, 2, 3, 4);\n";

        $this->assertSame(
            $input,
            $this->stabilize(
                $input,
                [
                    'Noldors/method_call_wrapping' => true,
                ],
            ),
        );
    }

    public function testTheNativePolicyCanDisableARuleForAFile(): void
    {
        $input = "<?php\nin_array(\$needle, \$haystack);\n";
        $policy = $this->policy(['strict_param' => static fn (SplFileInfo $file): bool => false]);

        $this->assertSame($input, $this->stabilize($input, ['strict_param' => true], $policy));
    }

    public function testAnAnnotationTakesPrecedenceOverTheNativePolicy(): void
    {
        $calls = 0;
        $policy = $this->policy(
            [
                'strict_param' => static function (SplFileInfo $file) use (&$calls): bool {
                    ++$calls;

                    return true;
                },
            ],
        );
        $input = "<?php\n// @php-cs-fixer-ignore strict_param\nin_array(\$needle, \$haystack);\n";

        $this->assertSame($input, $this->stabilize($input, ['strict_param' => true], $policy));
        $this->assertSame(0, $calls);
    }

    public function testATruePolicyDecisionKeepsTheRuleAndIsResolvedOnceBeforePasses(): void
    {
        $calls = 0;
        $policy = $this->policy(
            [
                'strict_param' => static function (SplFileInfo $file) use (&$calls): bool {
                    ++$calls;

                    return true;
                },
            ],
        );

        $this->assertSame(
            "<?php\nin_array(\$needle, \$haystack, true);\n",
            $this->stabilize(
                "<?php\nin_array(\$needle, \$haystack);\n",
                [
                    'strict_param' => true,
                ],
                $policy,
            ),
        );
        $this->assertSame(1, $calls);
    }

    public function testTheNativePolicyCanReplaceACustomRuleWithADifferentConfiguration(): void
    {
        $replacement = new MethodCallWrappingFixer();
        $replacement->configure(['max_line_length' => 100, 'max_single_line_arguments' => 5]);
        $policy = $this->policy(
            [
                'Noldors/method_call_wrapping' => static fn (SplFileInfo $file): FixerInterface => $replacement,
            ],
        );
        $input = "<?php\n\$o->run(1, 2, 3, 4);\n";

        $this->assertSame(
            $input,
            $this->stabilize(
                $input,
                [
                    'Noldors/method_call_wrapping' => true,
                ],
                $policy,
            ),
        );
    }

    public function testAReplacementOfTheWrongClassIsRejectedLikeTheNativeRunner(): void
    {
        $policy = $this->policy(
            [
                'Noldors/method_call_wrapping' => static fn (SplFileInfo $file): FixerInterface => new ArrayWrappingFixer(),
            ],
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be of the same class as the original fixer');

        $this->stabilize(
            "<?php\n\$o->run(1, 2, 3, 4);\n",
            [
                'Noldors/method_call_wrapping' => true,
            ],
            $policy,
        );
    }

    /**
     * @param array<non-empty-string, Closure(SplFileInfo): (bool|FixerInterface)> $customisers
     */
    private function policy(array $customisers): RuleCustomisationPolicyInterface
    {
        return new class ($customisers) implements RuleCustomisationPolicyInterface {
            /**
             * @param array<non-empty-string, Closure(SplFileInfo): (bool|FixerInterface)> $customisers
             */
            public function __construct(
                private readonly array $customisers,
            ) {}

            public function getPolicyVersionForCache(): string
            {
                return 'test-policy-v1';
            }

            public function getRuleCustomisers(): array
            {
                return $this->customisers;
            }
        };
    }

    /**
     * @param array<string, array<string, mixed>|bool> $rules
     */
    private function stabilize(string $code, array $rules, RuleCustomisationPolicyInterface|null $policy = null): string
    {
        $config = Config::create(__DIR__);
        $config->setRules([...$rules, 'Noldors/layout_stabilization' => true]);
        $config->setRuleCustomisationPolicy($policy);
        $tokens = Tokens::fromCode($code);

        foreach ($config->getCustomFixers() as $fixer) {
            if ('Noldors/layout_stabilization' === $fixer->getName()) {
                $fixer->fix(new SplFileInfo('example.php'), $tokens);
            }
        }

        return $tokens->generateCode();
    }
}
