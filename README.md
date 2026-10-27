# Noldors PHP-CS-Fixer rules

One reusable PHP-CS-Fixer configuration containing the complete captured Pint
profile: 270 native rule entries and four multiline layout rules, for 274
entries in total. Custom wrapping uses a maximum
inline width of 100 columns and at most three inline method arguments.

Requires PHP >=8.5, `ext-mbstring` and PHP-CS-Fixer `^3.95.25` (>=3.95.25, <4.0).

## Installation

```sh
composer require --dev noldors/phpcsfixer-rules
```

## Connection

Create or update the project's `.php-cs-fixer.dist.php`:

```php
<?php

return Noldors\PhpCsFixer\Config::create(__DIR__);
```

The factory registers the four layout fixers through the supported custom-fixer
API, enables the complete preset and allows its risky native rules, matching
Pint's configuration. Native indentation or spacing changes can require another
formatting run before the dry-run check passes. Its finder scans PHP files under
the supplied project root
and excludes `vendor`, `node_modules`, `build`, `storage` and `bootstrap/cache`
directories, Blade templates and standard generated PHP IDE helper files.

The returned `Config` extends the native PHP-CS-Fixer configuration, supporting
its finder and option methods. Use `Config::rules()` as the base for deliberate
rule overrides through `setRules()`.
See the migration guide for examples, preset provenance and version support.

```sh
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Rule documentation

- [Fluent chain wrapping](docs/fluent-chain-wrapping.md)
- [Method call wrapping](docs/method-call-wrapping.md)
- [Array wrapping](docs/array-wrapping.md)
- [Conditional expression wrapping](docs/conditional-expression-wrapping.md)
- [Migrating a project from Pint](docs/migration-from-pint.md)

Long arrays used directly as a `foreach` iterable still require a meaningful
local variable chosen during code review; the formatters do not extract one.
