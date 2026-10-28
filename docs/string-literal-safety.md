# String literal safety

The shared preset deliberately overrides two captured Pint rules to preserve
runtime string bytes with PHP-CS-Fixer 3.95.27. These overrides are included in
both `Config::create()` and `Config::rules()`.

## Concatenation

`no_useless_concat_operator` is disabled. With `juggle_simple_strings: true`,
the native fixer merges `"ustar\0" . '00'` into `"ustar\000"`. The former has hex
bytes `7573746172003030`; the latter has `757374617200`. The merged token lets
PHP consume the following digits as part of the octal escape.

Setting only `juggle_simple_strings` to `false` is insufficient. The native
fixer still merges operands using the same quotes, for example:

```php
"\0" . "0\n";   // Must not become "\00\n".
"\x4" . "1\n";  // Must not become "\x41\n".
"\0" . "0{$suffix}"; // Interpolated strings have the same boundary hazard.
```

The preset leaves concatenation operators in place, including safe expressions
such as `'hello' . 'world'` and concatenations with empty strings. Other rules
still normalize their spacing and simple quotes. No replacement fixer is
registered and no installed dependency is patched.

Regression tests evaluate fixed, trusted PHP expressions before and after the
full preset, compare them against independently specified hex bytes and check
a second formatting pass. They include octal and hexadecimal boundaries,
interpolation, chained and binary literals, escaped backslashes and dollars,
ordinary and empty strings, numeric operands, comments, heredocs and nowdocs.

## Backslashes

`string_implicit_backslashes` uses:

```php
[
    'double_quoted' => 'ignore',
    'single_quoted' => 'escape',
    'heredoc' => 'ignore',
]
```

PHP accepts uppercase `\X` hexadecimal escapes, including `"\X41"` for the
byte `41`. In PHP-CS-Fixer 3.95.27, the `escape` strategy treats this backslash
as implicit and doubles it, changing the value to the literal characters
`\X41`. This also affects heredocs and occurs independently of concatenation.
The `ignore` strategies preserve these backslashes. Single-quoted strings keep
their existing escape strategy because they do not interpret hexadecimal
escapes.

Consumers can override native rules through `setRules()`, but re-enabling these
transformations requires checking their installed PHP-CS-Fixer version for
byte preservation, including the regression cases above.
