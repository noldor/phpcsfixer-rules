<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

final class PerFileRuleSelectionTest extends TestCase
{
    public function testTheNativeCliHonorsIgnoreAnnotationsForNativeRules(): void
    {
        $input = "<?php\n// @php-cs-fixer-ignore strict_param\nin_array(\$needle, \$haystack);\n";

        $this->assertSame($input, $this->format($input, ['strict_param' => true]));
    }

    public function testTheNativeCliHonorsIgnoreAnnotationsForCustomRules(): void
    {
        $input = "<?php\n// @php-cs-fixer-ignore Noldors/method_call_wrapping\n\$o->run(1, 2, 3, 4);\n";

        $this->assertSame($input, $this->format($input, ['Noldors/method_call_wrapping' => true]));
    }

    public function testTheNativePolicyCanDisableACustomRule(): void
    {
        $input = "<?php\n\$o->run(1, 2, 3, 4);\n";

        $this->assertSame(
            $input,
            $this->format($input, ['Noldors/method_call_wrapping' => true], 'false'),
        );
    }

    public function testTheNativePolicyCanReplaceACustomRulesConfiguration(): void
    {
        $input = "<?php\n\$o->run(1, 2, 3, 4);\n";
        $selection = <<<'PHP'
            (static function (): \PhpCsFixer\Fixer\FixerInterface {
                $fixer = new \Noldors\PhpCsFixer\Fixer\MethodCallWrappingFixer();
                $fixer->configure(['max_single_line_arguments' => 5]);

                return $fixer;
            })()
            PHP;

        $this->assertSame(
            $input,
            $this->format($input, ['Noldors/method_call_wrapping' => true], $selection),
        );
    }

    /**
     * @param array<string, array<string, mixed>|bool> $rules
     */
    private function format(string $code, array $rules, string|null $policySelection = null): string
    {
        $filesystem = new Filesystem();
        $root = \sys_get_temp_dir() . '/noldors-cli-selection-' . \bin2hex(\random_bytes(8));
        $autoload = \var_export(__DIR__ . '/../vendor/autoload.php', true);
        $rulesCode = \var_export($rules, true);
        $config = "<?php\nrequire {$autoload};\n\$config = \\Noldors\\PhpCsFixer\\Config::create(__DIR__);\n\$config->setRules({$rulesCode});\n";

        if (null !== $policySelection) {
            $config .= <<<'PHP'
                $config->setRuleCustomisationPolicy(new class implements \PhpCsFixer\Config\RuleCustomisationPolicyInterface {
                    public function getPolicyVersionForCache(): string { return 'test-v1'; }
                    public function getRuleCustomisers(): array {
                        return ['Noldors/method_call_wrapping' => static fn (\SplFileInfo $file) =>
                PHP;
            $config .= $policySelection . "];\n}\n});\n";
        }

        $config .= "return \$config;\n";

        try {
            $filesystem->dumpFile($root . '/example.php', $code);
            $filesystem->dumpFile($root . '/config.php', $config);
            $command = [
                \PHP_BINARY,
                __DIR__ . '/../vendor/bin/php-cs-fixer',
                'fix',
                '--config=' . $root . '/config.php',
                '--using-cache=no',
                '--sequential',
            ];

            $command[] = $root . '/example.php';
            $process = new Process($command);
            $this->assertSame(
                0,
                $process->run(),
                $process->getOutput() . $process->getErrorOutput(),
            );
            $result = \file_get_contents($root . '/example.php');

            return false === $result ? throw new RuntimeException('Cannot read formatted fixture.') : $result;
        } finally {
            $filesystem->remove($root);
        }
    }
}
