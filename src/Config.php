<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer;

use Closure;
use SplFileInfo;
use PhpCsFixer\Finder;
use PhpCsFixer\Config as NativeConfig;
use Noldors\PhpCsFixer\Fixer\ArrayWrappingFixer;
use Noldors\PhpCsFixer\Fixer\MethodCallWrappingFixer;
use Noldors\PhpCsFixer\Fixer\FluentChainWrappingFixer;
use Noldors\PhpCsFixer\Internal\ValidatorMethodOrderPolicy;
use Noldors\PhpCsFixer\Fixer\FunctionCompositionToPipeFixer;
use Noldors\PhpCsFixer\Fixer\ConditionalExpressionWrappingFixer;

final class Config extends NativeConfig
{
    public static function create(string $projectRoot): self
    {
        $config = new self();
        $config->registerCustomFixers([
            new FluentChainWrappingFixer(),
            new MethodCallWrappingFixer(),
            new ArrayWrappingFixer(),
            new ConditionalExpressionWrappingFixer(),
            new FunctionCompositionToPipeFixer(),
        ], )
            ->setRules(self::rules())
            ->setRiskyAllowed(true)
            ->setFinder(
                Finder::create()
                    ->in($projectRoot)
                    ->notName([
                        '*.blade.php',
                        '_ide_helper.php',
                        '_ide_helper_actions.php',
                        '_ide_helper_models.php',
                        '.phpstorm.meta.php',
                    ], )
                    ->exclude([
                        'vendor',
                        'node_modules',
                        'build',
                        'storage',
                        'bootstrap/cache',
                    ], ),
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
     * @param Closure(SplFileInfo): bool $validatorFiles
     */
    public function withValidatorMethodOrder(Closure $validatorFiles, string $cacheVersion): self
    {
        $this->setRuleCustomisationPolicy(
            new ValidatorMethodOrderPolicy(
                $validatorFiles,
                $cacheVersion,
                $this->getRules(),
                $this->getRuleCustomisationPolicy(),
            ),
        );

        return $this;
    }
}
