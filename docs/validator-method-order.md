# Validator method order

`Config::withValidatorMethodOrder()` opts selected validator files into the native
PHP-CS-Fixer `ordered_class_elements` rule with this method order:

1. `rules()`
2. `messages()`
3. `attributes()`

The preset is unchanged until this API is called. Other files retain the existing
native class-element configuration. No framework, application directory or
namespace is hard-coded into the package.

```php
<?php

use Noldors\PhpCsFixer\Config;

$config = Config::create(__DIR__);
$validatorFiles = [
    __DIR__ . '/src/Validation/CreateOrder.php',
    __DIR__ . '/src/Validation/UpdateOrder.php',
];

$config->withValidatorMethodOrder(
    static fn (SplFileInfo $file): bool => in_array($file->getRealPath(), $validatorFiles, true),
    'validator-files-v1',
);

return $config;
```

The callback is a `Closure(SplFileInfo): bool`; it can select dedicated validator
directories or consume the project's own validator file manifest. Use the
project's actual paths and validators. The non-empty cache version must change
when the selector's behavior or external manifest changes, including additions
and removals of selected files.

## Scope and ordering

The native customisation policy operates on **whole files**. Select only files
whose classes are validators. Selecting a mixed file also applies the named
method order to its other classes, interfaces, traits and enums. The callback
does not discover Laravel or Lighthouse roles, resolve inheritance, or select an
individual class within a file.

The integration adds `method:rules`, `method:messages` and `method:attributes`
before the first existing method group while preserving the current native
member-group order, sort algorithm and case-sensitivity configuration. Existing
explicit positions for those three names are replaced by the required order.
Missing methods are not created, and inherited methods are not copied into the
file. Their bodies, attributes, attached comments and literals move with the
native class elements.

Use this API after setting deliberate `setRules()` overrides.
`ordered_class_elements` must remain enabled. The method also works when that
native rule is enabled as `true`, using its native defaults, or enabled through
a native rule set such as `@Symfony`. Effective rule-set options and explicit
configuration overrides are preserved. An explicit
`['ordered_class_elements' => false]` disables the rule and prevents this opt-in.

Registered native `RuleSetDefinitionInterface` definitions are supported through
`Config::registerCustomRuleSets()`. The API captures the current rules at opt-in
and resolves their effective native configuration when the runner first requests
the policy's customisers, after native custom-set registration. A disabled rule
is rejected at that point, before files are changed. No package code registers
sets in the native global registry. Configure rule overrides and custom sets
before opting in; later `setRules()` changes do not update this captured policy.

## Existing native policies

Existing customisers for other rules are preserved, and their policy version
participates in the cache fingerprint. If a customiser for
`ordered_class_elements` already exists, the first customiser request throws
`InvalidArgumentException` before files are changed:
the caller must explicitly choose one policy. An opaque replacement fixer cannot
be safely merged with another ordering configuration.

The prior policy's customisers are requested at the same native runner boundary,
after custom rule-set registration. Its cache version remains available during
configuration setup.

Setting another native customisation policy afterwards replaces this one through
the standard PHP-CS-Fixer API. Per-file native ignore annotations retain their
normal effect.

The implementation uses the native `RuleCustomisationPolicyInterface` and
`OrderedClassElementsFixer`, supported by the package's PHP-CS-Fixer version.
Upstream marks the policy API experimental; the package's CLI regression tests
verify selected/unselected files, preservation of tokens and literals, and
idempotence against installed updates. No custom token-reordering fixer is
registered.
