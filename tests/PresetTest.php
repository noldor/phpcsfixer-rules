<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Tests;

use RuntimeException;
use PhpCsFixer\Finder;
use PhpCsFixer\FixerFactory;
use Noldors\PhpCsFixer\Config;
use PhpCsFixer\RuleSet\RuleSet;
use PHPUnit\Framework\TestCase;
use PhpCsFixer\Config as NativeConfig;
use Symfony\Component\Filesystem\Filesystem;

final class PresetTest extends TestCase
{
    public function testTheRulesEntrypointPreservesEveryNativePintRuleAndOption(): void
    {
        $expected = $this->expectedNativeRules();
        $actual = \array_filter($this->entrypointRules(), static fn (string $name): bool => ! \str_starts_with($name, 'Noldors/'), \ARRAY_FILTER_USE_KEY);

        $this->assertCount(270, $expected);
        $this->assertSame(\array_keys($expected), \array_keys($actual));
        $this->assertSame($expected, $actual);
    }

    public function testTheConvenienceApiPreservesEveryNativePintRuleAndOption(): void
    {
        $actual = \array_filter(Config::rules(), static fn (string $name): bool => ! \str_starts_with($name, 'Noldors/'), \ARRAY_FILTER_USE_KEY);
        $expected = $this->expectedNativeRules();

        $this->assertSame(\array_keys($expected), \array_keys($actual));
        $this->assertSame($expected, $actual);
    }

    public function testTheThreeLayoutRulesAndStabilizationAreTheOnlyAdditions(): void
    {
        $rules = $this->entrypointRules();
        $custom = \array_filter($rules, static fn (string $name): bool => \str_starts_with($name, 'Noldors/'), \ARRAY_FILTER_USE_KEY);

        $this->assertCount(274, $rules);
        $this->assertSame(
            [
                'Noldors/fluent_chain_wrapping' => ['max_line_length' => 100],
                'Noldors/method_call_wrapping' => [
                    'max_line_length' => 100,
                    'max_single_line_arguments' => 3,
                ],
                'Noldors/array_wrapping' => ['max_line_length' => 100],
                'Noldors/layout_stabilization' => true,
            ],
            $custom,
        );
        $this->assertSame($rules, Config::rules());
    }

    public function testCreateRegistersAllRulesFixersAndRiskyPermission(): void
    {
        $config = Config::create(__DIR__);

        $this->assertInstanceOf(NativeConfig::class, $config);
        $this->assertSame($this->entrypointRules(), $config->getRules());
        $this->assertTrue($config->getRiskyAllowed());
        $this->assertSame(
            [
                'Noldors/fluent_chain_wrapping',
                'Noldors/method_call_wrapping',
                'Noldors/array_wrapping',
                'Noldors/layout_stabilization',
            ],
            \array_map(static fn ($fixer): string => $fixer->getName(), $config->getCustomFixers()),
        );

        $factory = new FixerFactory()
            ->registerBuiltInFixers()
            ->registerCustomFixers($config->getCustomFixers())
            ->useRuleSet(new RuleSet($config->getRules()));

        $this->assertNotEmpty($factory->getFixers());
    }

    public function testTheFinderUsesOnlyGenericProjectExclusions(): void
    {
        $filesystem = new Filesystem();
        $root = \sys_get_temp_dir() . '/noldors-fixer-preset-' . \bin2hex(\random_bytes(8));
        $files = [
            'app/Handler.php',
            'src/Service.php',
            'bootstrap/app.php',
            'bootstrap/cacheable/Keep.php',
            'vendor/dependency/Excluded.php',
            'node_modules/dependency/Excluded.php',
            'build/Excluded.php',
            'storage/Excluded.php',
            'bootstrap/cache/Excluded.php',
            'resources/views/page.blade.php',
            '_ide_helper.php',
            '_ide_helper_models.php',
            '_ide_helper_actions.php',
            'notes.txt',
        ];

        try {
            foreach ($files as $file) {
                $filesystem->dumpFile($root . '/' . $file, '<?php');
            }

            $actual = [];

            foreach (Config::create($root)->getFinder() as $file) {
                $actual[] = \mb_substr($file->getPathname(), \mb_strlen($root) + 1);
            }

            \sort($actual);

            $this->assertSame(
                [
                    'app/Handler.php',
                    'bootstrap/app.php',
                    'bootstrap/cacheable/Keep.php',
                    'src/Service.php',
                ],
                $actual,
            );
        } finally {
            $filesystem->remove($root);
        }
    }

    public function testProjectsCanOverrideRulesAndFinderOnTheNativeConfig(): void
    {
        $config = Config::create(__DIR__);
        $finder = Finder::create()->in(__DIR__)->notPath('Fixtures');
        $config->setRules([...Config::rules(), 'strict_param' => false]);
        $config->setFinder($finder);

        $this->assertFalse($config->getRules()['strict_param']);
        $this->assertSame($finder, $config->getFinder());
    }

    /**
     * @return array<string, array<string, mixed>|bool>
     */
    private function entrypointRules(): array
    {
        /** @var array<string, array<string, mixed>|bool> $rules */
        $rules = require __DIR__ . '/../src/rules.php';

        return $rules;
    }

    /**
     * @return array<string, array<string, mixed>|bool>
     */
    private function expectedNativeRules(): array
    {
        /** @var array{preset: string, rules: array<string, array<string, mixed>|bool>} $explicit */
        $explicit = \json_decode($this->fixtureContents(
            'pint.json',
        ), true, flags: \JSON_THROW_ON_ERROR);

        /** @var array<string, array<string, mixed>|bool> $base */
        $base = \json_decode($this->fixtureContents(
            'laravel-preset.json',
        ), true, flags: \JSON_THROW_ON_ERROR);

        return \array_merge($base, $explicit['rules']);
    }

    private function fixtureContents(string $name): string
    {
        $contents = \file_get_contents(__DIR__ . '/Fixtures/' . $name);

        if (false === $contents) {
            throw new RuntimeException('Cannot read preset fixture ' . $name);
        }

        return $contents;
    }
}
