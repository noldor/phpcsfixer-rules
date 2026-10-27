# Fluent chain wrapping

`Noldors/fluent_chain_wrapping` splits a long fluent chain so each method call
after the first starts on its own line. The first call stays with its receiver.

```php
// Before, when the chain exceeds the configured width:
$query->where('state', $state)->orderBy('created_at')->first();

// After:
$query->where('state', $state)
    ->orderBy('created_at')
    ->first();
```

## Configuration

```php
'Noldors/fluent_chain_wrapping' => ['max_line_length' => 120],
```

`max_line_length` must be a positive integer and defaults to 120. Width counts
Unicode code points, with tabs advancing to four-column tab stops. A chain
exactly at the limit stays inline. Each chain is measured from its own receiver,
including indentation, rather than from preceding enclosing expressions. A
short `$entry['request']->getUri()->getHost()` therefore remains together inside
a long callback or outer function call.

Line breaks inside argument lists, array literals and closure bodies do not
by themselves split a chain. `User::factory()->create([...])` keeps its header
together. Short continuations also join a line containing only closing
delimiters, even when the continuation was previously on its own line:

```php
Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$second = $this->withHeader(
    'X-Correlation-ID',
    '00000000-0000-0000-0000-000000000000',
)->getJson('/context-boundary')->assertOk();
```

Joining stops at a comment or when the resulting closing line would exceed the
configured width. Following short calls can share that line. Multiline
arguments are reindented with a moved call.

Other existing object and nullsafe continuation breaks keep a chain multiline;
remaining successive calls are then separated. In the complete preset, native
`no_space_around_double_colon` can remove the only multiline evidence from a
short chain when all continuation breaks precede `::`. It becomes inline if it
fits the limit. Long static chains still wrap.

## Scope and preservation

Method, nullsafe and static links are supported, including variable and braced
method names. Property accesses between calls retain the same receiver path
but do not count as calls. Nested chains are analysed independently. Free
functions, constructors and first-class callable references do not count as
method links. A single call or property-only access does not become a chain
merely because its surrounding line is long.

Explicitly regrouped receivers and offset accesses form chain-recognition
boundaries. The rule changes only whitespace, preserving arguments, call order,
`?->` behavior, comments, string contents and member names. Complex string
interpolation retains its value and surrounding text. It does not split an
individually long argument or impose a universal hard line limit.
