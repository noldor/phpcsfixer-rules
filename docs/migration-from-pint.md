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

Four custom layout fixers and one function-to-pipe fixer are appended, for 275
entries in total. All 270 native rule keys remain, including 28 disabled rules.
One explicit option override sets
`braces_position.allow_single_line_anonymous_functions` to `false`, so closure
bodies expand while short calls inside them remain inline. String safety
overrides disable `no_useless_concat_operator` and set
`string_implicit_backslashes.double_quoted` and `.heredoc` to `ignore`.
See [string literal safety](string-literal-safety.md) for the reasons and examples.
Every other native option and its ordering remain unchanged.

The source checkout retains the [explicit configuration snapshot](../tests/Fixtures/pint.json)
and [Laravel preset snapshot](../tests/Fixtures/laravel-preset.json). Parity tests
compare native keys and full values independently against both `src/rules.php`
and `Config::rules()`. They apply the documented closure and string safety
overrides and verify the five additions and that the complete configuration can
be loaded by PHP-CS-Fixer.
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

The custom rules run once in the native fixer pipeline. Native indentation or
spacing may make a line exceed the width limit after its wrapping rule has run;
wrapping a surrounding chain may also change a nested call's indentation. These
cases can require another `php-cs-fixer fix` invocation. A later native
closure-to-arrow conversion can also remove a closing-only line after the pipe
rule has run, changing where the next pipe belongs. Wrapping a nested call in
an arrow-function header can likewise make an outer pipe eligible to join on
the next invocation. Repeat formatting until
`php-cs-fixer fix --dry-run --diff` reports no changes before committing.

The package does not replay native or third-party rules. File annotations,
rule customization policies and custom fixer eligibility belong to the native
runner and are evaluated by PHP-CS-Fixer normally.

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
        'max_line_length' => 120,
        'max_single_line_arguments' => 3,
        'single_argument_max_length' => 40,
    ],
]);
```

Review overlapping rule keys. PHP array unpacking replaces a whole option array
for the same rule name; it does not merge individual options. Preserve required
options when adding overrides. Apply overrides through the native `setRules()`
method.

Use the `Config` factory for registration and `Config::rules()` for preset
overrides. `src/rules.php` stores the catalog data.

The shared defaults wrap long fluent chains and array literals at 120 columns;
array width includes its closing delimiter and a following statement semicolon.
Later arguments do not affect that width. Long conditional headers split at
their boolean operators, and full ternaries split at `?` and `:`. These are
wrapping thresholds, not a general hard line limit.

Method calls with more than three syntactic arguments wrap. A single argument
uses its own 40-character limit and rejoins its call when short. A single array
literal can also stay inline when its complete method expression fits 120
columns, as in `Context::forget(['command', 'job', 'jobId', 'jobAttempt']);`.
Two or three arguments use the method expression's width, independently of preceding outer
expressions. Array contents and closure bodies do not force short headers or
chains to expand. Short continuations join lines containing only closing
delimiters, including `})->create()` and `)->getJson('/path')->assertOk()`.
Other existing object and nullsafe continuation breaks preserve multiline
chains. Native `no_space_around_double_colon` can collapse a short chain whose
only continuation breaks precede `::`; long static chains still wrap.

Known native unary function composition becomes a PHP 8.5 pipeline, for example
`array_values(array_filter($values))` becomes `array_filter($values)` followed
by `|> array_values(...)`. Pipe stages start on new lines, except for short
stages that can join closing-only lines within 120 columns, such as
`) |> array_values(...)`. Comments prevent joining. Existing pipe expressions
follow the same layout. See the [pipe rule](function-composition-to-pipe.md)
for the supported functions and conservative exclusions, and each layout
rule's documentation for its boundaries.

## Supported PHP-CS-Fixer versions

The dependency constraint is `^3.95.25`: PHP-CS-Fixer >=3.95.25 and <4.0. The
configuration uses the supported custom-fixer registration API, without the
internal native fixer factory, rule-set resolver, annotation analyzer, base
fixer or configuration trait.

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
`test:lint` until it proposes no changes. Complete the
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
