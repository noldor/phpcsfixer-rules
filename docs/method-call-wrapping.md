# Method call wrapping

`Noldors/method_call_wrapping` formats instance, static and nullsafe calls,
including variable and braced method names. More than three arguments always
put each argument on its own line.

A single argument has a separate `single_argument_max_length` limit, defaulting
to 40 source characters. A short argument stays inside its call even on a long
surrounding line. Existing outer argument-list breaks are removed when the
argument is short and has no structural line breaks or boundary comments:

```php
$executionId = Context::get('executionId') ?? $event->job->uuid() ?? Str::uuid()->toString();
$scalar->parseLiteral(new StringValueNode(['value' => $value]));
```

The argument's width includes quotes, named-argument labels, unpacking and
nested expressions, but excludes the call's receiver, name and parentheses.
At exactly 40 characters it remains inline; ordinary arguments wrap at 41.

A single array literal may also stay inline when the complete method expression,
including indentation, fits `max_line_length`, even above the 40-character
argument limit. This applies to named and unpacked array arguments and to
`array(...)` syntax. Existing outer breaks rejoin under the same condition:

```php
Context::forget(['command', 'job', 'jobId', 'jobAttempt']);
```

Array accesses and expressions combining an array with other values retain the
ordinary single-argument limit. Array bodies keep their own existing line breaks;
this exception does not collapse multiline arrays or move boundary comments.

Two or three arguments wrap when their method expression, including its
indentation, exceeds `max_line_length`, or their argument list already contains
a structural newline. Preceding outer calls and lambda headers do not count:

```php
Context::scope(static function (): void {
    ContextJob::dispatch('retry', retry: true);
}, data: [
    'source' => 'http',
]);
```

Line breaks inside array literals and anonymous function bodies do not count
as structural argument-list breaks. Short headers such as
`Schema::create('users', static function (Blueprint $table): void {` and
`Context::add([` remain together. Array body length does not affect header
width. Breaks inside a closure signature or an array access still select the
surrounding call. Intrinsically multiline arguments remain multiline.

## Configuration

```php
'Noldors/method_call_wrapping' => [
    'max_line_length' => 120,
    'max_single_line_arguments' => 3,
    'single_argument_max_length' => 40,
],
```

All options must be positive integers. Width counts Unicode code points, with
tabs advancing to four-column tab stops. These are wrapping thresholds, not a
hard maximum on every source line.

Named arguments and unpacked arguments each count as one syntactic argument.
Nested commas do not count as outer separators. The rule preserves argument
expressions, comments, string and heredoc contents, and existing trailing
commas. Native `no_trailing_comma_in_singleline` removes a comma left behind
when a short single argument rejoins its call in the complete preset.
Indentation and line endings follow the configuration.

Empty calls, first-class callable references, free functions, constructors,
declarations and attributes are outside this rule. Indivisible long arguments
are preserved. Native `method_argument_space` supplies normal argument spacing
and remains compatible with this rule.
