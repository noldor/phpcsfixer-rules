# Fluent chain wrapping

`Noldors/fluent_chain_wrapping` splits a long fluent chain so each method call
after the first starts on its own line. The first call stays with its receiver.

```php
// Before, when the line exceeds the configured width:
$query->where('state', $state)->orderBy('created_at')->first();

// After:
$query->where('state', $state)
    ->orderBy('created_at')
    ->first();
```

## Configuration

```php
'Noldors/fluent_chain_wrapping' => ['max_line_length' => 100],
```

`max_line_length` must be a positive integer and defaults to 100. Width counts
Unicode codepoints; tabs advance to four-column tab stops. The trigger is the
physical source line, including its surrounding code. A line exactly at the
limit stays inline. Independent chains sharing an overlong physical line can
therefore both be expanded.

Line breaks inside array literals and closure bodies do not make a short chain
multiline. `User::factory()->create([...])` keeps its header together, and a
continuation after a closure can remain on the closing line:

```php
Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

Existing object and nullsafe continuation breaks keep a chain multiline, and
any remaining successive calls on the same line are separated. In the complete
preset, native `no_space_around_double_colon` can remove the only multiline
evidence from a short chain when its continuation breaks are all before `::`.
Such a chain becomes inline if its resulting line fits the limit. Long static
chains still wrap. A single call or property-only access does not become a
chain merely because its line is long.

## Scope and preservation

- Method, nullsafe and static call links are supported, including a static
  factory followed by instance calls. Property accesses between method calls do
  not count as calls, but retain the same receiver path. Dynamic method names
  such as `$object->{$method}()` and `Handler::{$method}()` are supported.
- Nested chains inside arguments are analysed independently. Their operators
  are not treated as successive calls of the surrounding chain.
- Free functions and constructors are not counted as fluent method links.
  First-class callable references are also outside this rule's call count.
- Explicitly regrouped receivers and offset accesses form boundaries for chain
  recognition. The rule does not infer that a path through these expressions
  remains fluent.
- Formatting changes only whitespace. Arguments, call order, `?->` behavior,
  comments, string contents and member names are preserved. An inline comment
  before a continued call stays before that call. Chains inside complex string
  interpolation retain their value and surrounding literal text.
- When a continuation moves, its multiline argument whitespace is reindented
  with it in the same pass.

The rule does not split an individually long string or argument expression.
Use the shared rule set's argument wrapping and native indentation rules for
the surrounding layout.
