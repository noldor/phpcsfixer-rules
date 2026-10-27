# Method call wrapping

`Noldors/method_call_wrapping` places every argument of a selected method call on
its own line. It handles instance, static and nullsafe calls, including variable
and braced method names.

A call is selected when its argument count exceeds `max_single_line_arguments`,
the physical call line exceeds `max_line_length`, or the argument list already
contains a newline. Empty calls and first-class callable placeholders are left
unchanged.

Line breaks inside an array literal or an anonymous function's body do not make
the surrounding argument list multiline. A short header such as
`Schema::create('users', static function (Blueprint $table): void {` stays on one
line while its body keeps its own lines. The argument-count and physical header
width limits still apply, as do existing breaks between arguments or within the
closure signature. Already expanded argument lists are not collapsed.

A multiline array can keep its opening bracket beside the call's opening
parenthesis:

```php
Context::add([
    'correlationId' => $correlationId,
    'executionId' => $executionId,
    'source' => 'http',
]);
```

This applies to short and long array syntax, named arguments and unpacking.
Line breaks in array access expressions still select the surrounding call.

## Configuration

```php
'Noldors/method_call_wrapping' => [
    'max_line_length' => 100,
    'max_single_line_arguments' => 3,
],
```

Both options must be positive integers. The defaults are shown above. Width
counts Unicode code points, with tabs advancing to four-column tab stops. It
includes the indentation and surrounding content of the physical line.

## Example

```php
$service->publish($topic, $message, $headers, $options);
```

Becomes:

```php
$service->publish(
    $topic,
    $message,
    $headers,
    $options
);
```

Named arguments and unpacked arguments each count as one syntactic argument.
Commas inside nested expressions do not count as separators of the outer call.
The rule preserves argument expressions, comments, string and heredoc contents,
and the presence or absence of an existing trailing comma. It uses the
configuration's indentation and line endings.

Free function calls, constructors, declarations and attributes are outside this
rule. Wrapping cannot shorten an indivisible long argument, such as a string
literal; it does not split or rewrite that value.

The native `method_argument_space` rule remains useful for normal argument
spacing. Its `on_multiline: ensure_fully_multiline` setting is compatible with
this rule. This custom rule supplies the argument-count and width thresholds
that the native rule does not provide.
