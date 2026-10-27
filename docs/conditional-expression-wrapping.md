# Conditional expression wrapping

`Noldors/conditional_expression_wrapping` selects long or already multiline
`if`, `elseif`, `while` and `do ... while` conditions with top-level boolean
operators. It places each subsequent operand on a separate line, with its
operator at the beginning. The closing condition parenthesis has its own line.

```php
if ($result instanceof Deferred
    || $result instanceof Promise
    || Executor::getDefaultPromiseAdapter()->isThenable($result)
) {
    consume($result);
}
```

Long full ternary expressions put `?` and `:` on separate lines. When the
condition itself needs wrapping at top-level boolean operators, its operands use one
continuation indent and the result branches use two. For example, with
`max_line_length` set to 100:

```php
$correlationId = \is_string($header)
    && Str::isUuid($header)
    && '00000000-0000-0000-0000-000000000000' !== $header
        ? \mb_strtolower($header)
        : $executionId;
```

The rule uses PHP-Parser's public AST and source positions to identify
conditional expressions. It changes token whitespace, preserving grouping,
operator precedence, evaluation order, expressions, comments and string values.
Operators inside a nested call, array, string or parenthesized group are not
outer operand boundaries. Nested ternaries retain their branch indentation.

```php
'Noldors/conditional_expression_wrapping' => ['max_line_length' => 120],
```

`max_line_length` must be a positive integer and defaults to 120. Width counts
Unicode characters with four-column tab stops and includes indentation. A root
ternary also includes its assignment or return prefix; nested ternaries are
measured independently of surrounding comparisons and calls. Following statement bodies
do not select a short condition. Line breaks inside array literals and closure
bodies alone do not select an expression. The configured indentation and line
endings are respected.

The rule runs before array, method-call and fluent-chain wrapping so those rules
see the shorter operand lines. Shorthand ternaries (`?:`), `for` headers and
boolean expressions outside these conditions or full ternaries are outside its
scope. Indivisible long operands are preserved.
