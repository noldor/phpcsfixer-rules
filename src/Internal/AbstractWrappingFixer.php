<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use SplFileInfo;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\FixerConfiguration\FixerConfigurationResolverInterface;

/**
 * @internal
 *
 * @implements ConfigurableFixerInterface<array<string, mixed>, array<string, positive-int>>
 */
abstract class AbstractWrappingFixer extends AbstractWhitespaceAwareFixer implements ConfigurableFixerInterface
{
    /**
     * @var array<string, positive-int>
     */
    protected array $configuration;

    private readonly FixerConfigurationResolverInterface $configurationDefinition;

    public function __construct()
    {
        parent::__construct();
        $this->configurationDefinition = $this->createConfigurationDefinition();
        $this->configure([]);
    }

    abstract protected function createConfigurationDefinition(): FixerConfigurationResolverInterface;

    abstract protected function applyFix(SplFileInfo $file, Tokens $tokens): void;

    final public function configure(array $configuration): void
    {
        /** @var array<string, positive-int> $resolved All wrapping options validate positive integers. */
        $resolved = $this->configurationDefinition->resolve($configuration);
        $this->configuration = $resolved;
    }

    final public function getConfigurationDefinition(): FixerConfigurationResolverInterface
    {
        return $this->configurationDefinition;
    }

    final public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if (0 < \count($tokens) && $this->supports($file) && $this->isCandidate($tokens)) {
            $this->applyFix($file, $tokens);
        }
    }

    final public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    final public function isRisky(): bool
    {
        return false;
    }
}
