# Array wrapping

`Noldors/array_wrapping` puts array literal elements on separate lines when the
line up to the literal's closing delimiter exceeds `max_line_length` or the
literal already spans multiple lines. A following statement semicolon also counts;
later arguments do not make a short `[1, 2]` expand. The default limit is 100 characters, including
indentation; UTF-8 characters count once and tabs advance to four-column tab
stops. A line of exactly 100 characters stays inline.

```php
$values = [
    'first' => $firstValue,
    'second' => $secondValue,
];
```

Both `[...]` and `array(...)` literals are supported. Nested literals are handled
independently. Short inline literals stay inline. Array access, destructuring,
`list(...)` and attribute brackets are excluded. Empty literals retain their
layout.

```php
->setRules([
    'Noldors/array_wrapping' => ['max_line_length' => 100],
])
```

`max_line_length` must be a positive integer. The fixer uses the configured
PHP-CS-Fixer indentation and line ending. It preserves element order, comments,
literal string contents and existing trailing commas. Enable the native
`trailing_comma_in_multiline` rule separately when trailing commas are required.

The rule does not impose a hard maximum on indivisible elements such as a long
string. It does not extract arrays from `foreach` expressions or invent local
variable names.
