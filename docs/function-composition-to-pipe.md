# Function composition to pipe

`Noldors/function_composition_to_pipe` rewrites known native unary wrappers
around another function call as a PHP 8.5 pipeline:

```php
// Before:
$allowed = \array_values(\array_filter($history, $predicate));

// After:
$allowed = \array_filter($history, $predicate)
    |> \array_values(...);
```

Each `|>` starts on a new line with one continuation indent. This also formats
existing pipelines, including pipelines with user-defined callables. Multiple
unary wrappers become successive stages in their original invocation order.
The first call retains its arguments and callback; no new closure, capture or
intermediate variable is introduced.

The rule resolves fully qualified native names, global names and explicit
function imports. Unqualified calls inside a namespace are skipped when they
could resolve to a locally defined function. The original spelling and imported
aliases are retained.

## Supported conversions

The outer call must have exactly one positional, non-unpacked argument, whose
value is a function call or another pipeline. Its resolved global name must be
one of these known functions with by-value first parameters:

- `array_filter`, `array_flip`, `array_is_list`, `array_keys`, `array_product`,
  `array_reverse`, `array_sum`, `array_unique`, `array_values`, `count`
- `ltrim`, `rtrim`, `trim`, `strlen`, `strtolower`, `strtoupper`
- `mb_strlen`, `mb_strtolower`, `mb_strtoupper`

Unknown or dynamic outer calls, named arguments, unpacking and reference
contexts are skipped. Comments in the outer syntax that would be removed also
prevent conversion; comments inside the retained inner call are preserved.
Calls needing extra outer arguments remain ordinary calls.

PHP-Parser's public format-preserving printer retains the inner expression and
adds grouping where the pipe's precedence requires it. Runtime tests cover
values, invocation order, single evaluation, strict type errors and surrounding
operators. Layout-only token preservation does not apply to this intentional
syntax rewrite.

## Configuration

```php
'Noldors/function_composition_to_pipe' => ['max_line_length' => 120],
```

`max_line_length` must be a positive integer. When the pipeline's first stage is
a long function call with multiple arguments, those arguments get separate
lines. The pipe stages always start on separate lines, regardless of width.
Unicode characters count once and tabs advance to four-column tab stops.
Configured indentation and line endings are respected.

Requires PHP 8.5, already required by the package. See PHP's
[functional operators](https://www.php.net/manual/en/language.operators.functional.php)
and [operator precedence](https://www.php.net/manual/en/language.operators.precedence.php).
