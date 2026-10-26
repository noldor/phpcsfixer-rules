# Migrating a project from Pint

Adopt this package through the consuming project's own dependency, formatting
and CI configuration.

## Complete preset and parity

The shared preset combines the Laravel preset from Pint 1.32.1 with the 268
explicit rules captured in the maintained project configuration. The explicit
configuration overrides the base preset with Pint's shallow `array_merge`
semantics. This produces 270 native rule entries, including the two base-preset
entries not explicitly repeated by that configuration:

- `general_phpdoc_tag_rename`
- `no_multiple_statements_per_line`

Three custom multiline fixers and one internal stabilization rule are appended,
for 274 entries in total. All 270 native entries remain unchanged, including
27 disabled rules. Nested options and their ordering remain intact.

The source checkout retains the [explicit configuration snapshot](../tests/Fixtures/pint.json)
and [Laravel preset snapshot](../tests/Fixtures/laravel-preset.json). Parity tests
compare native keys and full values independently against both `src/rules.php`
and `Config::rules()`. They also verify the four additions and that the complete
configuration can be loaded by PHP-CS-Fixer.
The snapshots are development fixtures and are excluded from dist archives.

The resolved native preset is embedded in `src/rules.php`; it does not depend on
a local Pint installation or an application path. This is a captured baseline,
rather than an alias that changes when Pint updates its Laravel preset.

Record any additional project rules, exclusions, source directories and
whitespace settings before migration. Projects with further Pint-specific
Blade or PHPDoc fixers must evaluate those extensions separately; the parity
claim above covers the captured profile.

## Connect PHP-CS-Fixer

Install a compatible released version of `noldors/phpcsfixer-rules` and
connect it in the project's `.php-cs-fixer.dist.php`:

```php
<?php

return Noldors\PhpCsFixer\Config::create(__DIR__);
```

The factory returns `Noldors\PhpCsFixer\Config`, which extends the native
`PhpCsFixer\Config`, with the complete preset, custom fixer registration and
risky rules enabled. The full native preset includes transformations beyond
whitespace, as did the source Pint profile.

Internal stabilization repeats the configured fixers until the output stops
changing. This resolves interactions where indentation or spacing makes a line
longer after wrapping has run. It adds verification passes inside the same
invocation. A cycle or failure to settle within 16 passes raises a diagnostic.
Registered custom fixers use their normal instances and native file/token
eligibility checks; implementing clone support is not required.
Per-file ignore annotations and the native rule customization policy are
respected during stabilization. Policy decisions are resolved once before its
internal passes. File policy callbacks should be deterministic because the
runner and stabilization can each evaluate them for the same file.

Its default finder covers PHP files under the supplied root and excludes
`vendor`, `node_modules`, `build`, `storage`, `bootstrap/cache`, Blade templates
and standard generated PHP IDE helper files. Application exclusions remain in
the consuming project. For example, customize a finder
when the project needs a different scope:

```php
<?php

use Noldors\PhpCsFixer\Config;
use PhpCsFixer\Finder;

$config = Config::create(__DIR__);
$config->setFinder(
    Finder::create()
        ->in([__DIR__.'/app', __DIR__.'/tests'])
        ->notPath('generated.php'),
);

return $config;
```

Replace the finder with the project's existing directories and exclusions.
Deliberate project rule exceptions can also be applied through the native API:

```php
$config->setRules([
    ...Config::rules(),
    'Noldors/method_call_wrapping' => [
        'max_line_length' => 100,
        'max_single_line_arguments' => 3,
    ],
]);
```

Review overlapping rule keys. PHP array unpacking replaces a whole option array
for the same rule name; it does not merge individual options. Preserve required
options when adding overrides. Apply overrides through this configuration's
`setRules()` method. CLI `--rules` overrides happen outside the configuration
and cannot supply their effective rule settings to stabilization.

Use the `Config` factory for registration and `Config::rules()` for preset
overrides. `src/rules.php` stores the catalog data.

The shared defaults wrap fluent chains and array literals when their physical
source line exceeds 100 columns. Method calls with more than three syntactic
arguments also wrap; shorter argument lists wrap when their line exceeds the
configured width. Existing multiline arrays and argument lists remain
multiline. Object and nullsafe continuation breaks also preserve multiline
chains. Native `no_space_around_double_colon` can collapse a short chain whose
only continuation breaks precede `::`; long static chains still wrap. See each
rule's documentation for its supported syntax and boundaries.

## Supported PHP-CS-Fixer versions

The package supports PHP-CS-Fixer >=3.95.25 and <3.96 (`~3.95.25`). Stabilization
uses a native fixer factory marked `@internal`, whose API is not covered by
PHP-CS-Fixer's compatibility promise. Test compatibility with a new minor
series before widening that dependency constraint.

## Update scripts and CI

Replace the formatter entries in the project's existing Composer scripts,
preserving its other scripts:

```json
{
    "scripts": {
        "format": "php-cs-fixer fix",
        "test:lint": "php-cs-fixer fix --dry-run --diff"
    }
}
```

Update CI invocations that call Pint directly to use the corresponding
PHP-CS-Fixer lint command. Keep dependency constraints, the lock file, the PHP
runtime, CI setup and cache policy in the consuming project. Confirm that its
runtime satisfies the selected package's requirements.

Review the proposed formatting diff, apply the intended changes, and rerun
`test:lint` to confirm that a second pass proposes no changes. Complete the
project's normal verification before removing its Pint dependency and obsolete
configuration.

## Extract long inline foreach arrays manually

The array fixer changes layout; it does not introduce variables. When a long
literal array is used directly as a `foreach` iterable, choose a name that
expresses what its values mean and extract it at the appropriate statement
boundary:

```php
$requiredPermissions = [
    'read_reports',
    'export_reports',
    'download_exports',
];

foreach ($requiredPermissions as $permission) {
    $authorizer->requirePermission($permission);
}
```

Review evaluation timing, references and value lifetime during this extraction.
The variable name and placement belong to the operation's contract, rather than
to a whitespace formatter.
