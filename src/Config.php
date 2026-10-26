<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer;

use PhpCsFixer\Finder;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Config as NativeConfig;
use PhpCsFixer\WhitespacesFixerConfig;
use Noldors\PhpCsFixer\Fixer\ArrayWrappingFixer;
use Noldors\PhpCsFixer\Fixer\MethodCallWrappingFixer;
use Noldors\PhpCsFixer\Fixer\FluentChainWrappingFixer;
use Noldors\PhpCsFixer\Internal\LayoutStabilizationFixer;

final class Config extends NativeConfig
{
    public static function create(string $projectRoot): self
    {
        $config = new self();
        $config->registerCustomFixers(
            [
                new FluentChainWrappingFixer(),
                new MethodCallWrappingFixer(),
                new ArrayWrappingFixer(),
            ],
        )
            ->setRules(self::rules())
            ->setRiskyAllowed(true)
            ->setFinder(
                Finder::create()
                    ->in($projectRoot)
                    ->notName(
                        [
                            '*.blade.php',
                            '_ide_helper.php',
                            '_ide_helper_actions.php',
                            '_ide_helper_models.php',
                            '.phpstorm.meta.php',
                        ],
                    )
                    ->exclude(
                        [
                            'vendor',
                            'node_modules',
                            'build',
                            'storage',
                            'bootstrap/cache',
                        ],
                    ),
            );

        return $config;
    }

    /**
     * @return array<string, array<string, mixed>|bool>
     */
    public static function rules(): array
    {
        /** @var array<string, array<string, mixed>|bool> $rules */
        $rules = require __DIR__ . '/rules.php';

        return $rules;
    }

    /**
     * @return list<FixerInterface>
     */
    public function getCustomFixers(): array
    {
        $fixers = parent::getCustomFixers();

        return [
            ...$fixers,
            new LayoutStabilizationFixer(
                $this->getRules(),
                $fixers,
                new WhitespacesFixerConfig($this->getIndent(), $this->getLineEnding()),
                $this->getRuleCustomisationPolicy(),
            ),
        ];
    }
}
