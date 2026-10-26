<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use SplFileInfo;
use LogicException;
use RuntimeException;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\AbstractFixer;
use PhpCsFixer\RuleSet\RuleSet;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\WhitespacesFixerConfig;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\Config\RuleCustomisationPolicyInterface;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Analyzer\FixerAnnotationAnalyzer;

/**
 * Internal adapter for the supported PHP-CS-Fixer patch line.
 *
 * Stabilization uses the final configuration object's rules. CLI --rules
 * replaces the runner's selection outside that object and is not part of this
 * contract.
 */
final class LayoutStabilizationFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    private const int MAX_PASSES = 16;

    /**
     * @var list<FixerInterface>
     */
    private readonly array $fixers;

    /**
     * @param array<string, array<string, mixed>|bool> $rules
     * @param list<FixerInterface> $customFixers
     */
    public function __construct(
        array $rules,
        array $customFixers,
        WhitespacesFixerConfig $whitespaces,
        private readonly RuleCustomisationPolicyInterface|null $ruleCustomisationPolicy = null,
    ) {
        parent::__construct();
        unset($rules[$this->getName()]);
        $delegates = [];

        foreach ($customFixers as $fixer) {
            if ($this->getName() !== $fixer->getName()) {
                $delegates[] = $fixer;
            }
        }

        $this->fixers = new FixerFactory()
            ->registerBuiltInFixers()
            ->registerCustomFixers($delegates)
            ->useRuleSet(new RuleSet($rules))
            ->getFixers();
        $this->setWhitespacesConfig($whitespaces);
    }

    public function getName(): string
    {
        return 'Noldors/layout_stabilization';
    }

    public function getPriority(): int
    {
        return \PHP_INT_MIN;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Stabilize the configured native and multiline layout rules in bounded internal passes.',
            [new CodeSample("<?php\nif (\$ready) {\nreturn 1;\n}\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return true;
    }

    public function isRisky(): bool
    {
        return \array_any($this->fixers, static fn (FixerInterface $fixer): bool => $fixer->isRisky());
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        parent::setWhitespacesConfig($config);

        foreach ($this->fixers as $fixer) {
            if ($fixer instanceof WhitespacesAwareFixerInterface) {
                $fixer->setWhitespacesConfig($config);
            }
        }
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $fixers = $this->effectiveFixers($file, $tokens);
        $previous = $tokens->generateCode();
        $seen = [\hash('sha256', $previous) => true];

        for ($pass = 1; self::MAX_PASSES >= $pass; ++$pass) {
            foreach ($fixers as $fixer) {
                if (! $fixer instanceof AbstractFixer
                    && (! $fixer->supports($file) || ! $fixer->isCandidate($tokens))) {
                    continue;
                }

                $fixer->fix($file, $tokens);
                $tokens->clearEmptyTokens();
            }

            $current = $tokens->generateCode();

            if ($current === $previous) {
                return;
            }

            $hash = \hash('sha256', $current);

            if (isset($seen[$hash])) {
                throw new LogicException(\sprintf('The configured layout rules entered a cycle for %s after %d passes.', $file->getPathname(), $pass));
            }

            $seen[$hash] = true;
            $previous = $current;
        }

        throw new LogicException(\sprintf('The configured layout rules did not stabilize within %d passes for %s.', self::MAX_PASSES, $file->getPathname()));
    }

    /**
     * @return list<FixerInterface>
     */
    private function effectiveFixers(SplFileInfo $file, Tokens $tokens): array
    {
        $annotations = new FixerAnnotationAnalyzer()->find($tokens);
        $ignored = $annotations['php-cs-fixer-ignore'] ?? [];
        $customisers = $this->ruleCustomisationPolicy?->getRuleCustomisers() ?? [];
        $effective = [];

        foreach ($this->fixers as $fixer) {
            if (\in_array($fixer->getName(), $ignored, true)) {
                continue;
            }

            $customiser = $customisers[$fixer->getName()] ?? null;
            $selected = $this->selectedFixer(
                $fixer,
                null === $customiser ? true : $customiser($file),
            );

            if (null !== $selected) {
                $effective[] = $selected;
            }
        }

        return $effective;
    }

    private function selectedFixer(FixerInterface $fixer, bool|FixerInterface $selected): FixerInterface|null
    {
        if (false === $selected) {
            return null;
        }

        if (true === $selected) {
            return $fixer;
        }

        if ($fixer::class !== $selected::class) {
            throw new RuntimeException(\sprintf('The fixer returned by the Rule Customisation Policy must be of the same class as the original fixer (expected `%s`, got `%s`).', $fixer::class, $selected::class));
        }

        return $selected;
    }
}
