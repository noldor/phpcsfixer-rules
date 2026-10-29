<?php

declare(strict_types = 1);

namespace Noldors\PhpCsFixer\Internal;

use Closure;
use SplFileInfo;
use InvalidArgumentException;
use PhpCsFixer\RuleSet\RuleSet;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Config\RuleCustomisationPolicyInterface;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;

final class ValidatorMethodOrderPolicy implements RuleCustomisationPolicyInterface
{
    private OrderedClassElementsFixer|null $fixer = null;

    private readonly string $cacheVersion;

    /**
     * @param Closure(SplFileInfo): bool $validatorFiles
     * @param array<string, array<string, mixed>|bool> $rules
     */
    public function __construct(
        private readonly Closure $validatorFiles,
        string $cacheVersion,
        private readonly array $rules,
        private readonly RuleCustomisationPolicyInterface|null $previous,
    ) {
        if ('' === \mb_trim($cacheVersion)) {
            throw new InvalidArgumentException('Provide a non-empty validator selector cache version.');
        }

        if ($previous instanceof self) {
            throw new InvalidArgumentException('An ordered_class_elements customiser already exists; choose one ordering policy explicitly.');
        }
        $this->cacheVersion = \hash('sha256', \json_encode([
            'validator-method-order-v2',
            $cacheVersion,
            $previous?->getPolicyVersionForCache(),
            $rules,
        ], \JSON_THROW_ON_ERROR));
    }

    public function getPolicyVersionForCache(): string
    {
        return $this->cacheVersion;
    }

    public function getRuleCustomisers(): array
    {
        $customisers = $this->previous?->getRuleCustomisers() ?? [];

        if (isset($customisers['ordered_class_elements'])) {
            throw new InvalidArgumentException('An ordered_class_elements customiser already exists; choose one ordering policy explicitly.');
        }
        $fixer = $this->configuredFixer();

        return [
            ...$customisers,
            'ordered_class_elements' => fn (SplFileInfo $file): bool|FixerInterface => ($this->validatorFiles)($file) ? clone $fixer : true,
        ];
    }

    private function configuredFixer(): OrderedClassElementsFixer
    {
        if (null !== $this->fixer) {
            return $this->fixer;
        }
        $configuration = new RuleSet($this->rules)->getRules()['ordered_class_elements'] ?? false;

        if (false === $configuration) {
            throw new InvalidArgumentException('Enable ordered_class_elements before selecting validator method order.');
        }
        $fixer = new OrderedClassElementsFixer();
        $resolved = $this->nativeConfiguration(
            $fixer->getConfigurationDefinition()->resolve(
                true === $configuration ? [] : $configuration,
            ),
        );
        $fixer->configure($resolved);
        $this->fixer = $fixer;

        return $fixer;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array{order: list<string>, case_sensitive: bool, sort_algorithm: 'alpha'|'none'}
     */
    private function nativeConfiguration(array $configuration): array
    {
        $caseSensitive = $configuration['case_sensitive'] ?? false;
        $algorithm = $configuration['sort_algorithm'] ?? 'none';

        if (! \is_bool($caseSensitive) || ! \in_array($algorithm, ['alpha', 'none'], true)) {
            throw new InvalidArgumentException('Unsupported native ordered_class_elements configuration.');
        }

        return [
            'order' => $this->methodOrder($configuration['order'] ?? null),
            'case_sensitive' => $caseSensitive,
            'sort_algorithm' => $algorithm,
        ];
    }

    /**
     * @return list<string>
     */
    private function methodOrder(mixed $order): array
    {
        if (! \is_array($order) || ! \array_is_list($order)) {
            throw new InvalidArgumentException('The native ordered_class_elements order must be a list of strings.');
        }
        $named = ['method:rules', 'method:messages', 'method:attributes'];
        $result = [];

        foreach ($order as $element) {
            if (! \is_string($element)) {
                throw new InvalidArgumentException('The native ordered_class_elements order must contain strings.');
            }

            if (! \in_array(\mb_strtolower($element), $named, true)) {
                $result[] = $element;
            }
        }
        $position = \count($result);

        foreach ($result as $index => $element) {
            if (\str_starts_with($element, 'method')) {
                $position = $index;

                break;
            }
        }
        \array_splice($result, $position, 0, $named);

        return $result;
    }
}
