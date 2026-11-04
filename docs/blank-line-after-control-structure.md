# Blank line after control structure

`Noldors/blank_line_after_control_structure` separates a completed control
structure from the next statement in the same statement list with exactly one
empty line. The shared preset enables this non-risky rule by default. It has no
options.

```php
if ($context->hasHidden('audit.source')) {
    return;
}

$source = \getenv('AUDIT_SOURCE') ?: 'cli';
```

The same separation applies after a guard that throws, regardless of what the
next statement does:

```php
if (! $valid) {
    throw new \RuntimeException();
}

$handler->next();
```

## Boundaries

- Supports `if` (including complete `elseif`/`else` and `else if` chains),
  `for`, `foreach`, `while`, `switch`, and complete `try`/`catch`/`finally`
  chains when the statement ends with `}`. A final unbraced body that itself
  ends in a braced control structure also qualifies.
- Supports `do { ... } while (...);`, inserting separation after the final
  semicolon. An unbraced `do` body is outside this rule's scope.
- Works in nested statement lists, including functions, closures, methods,
  branches and switch cases. It never separates branches or handlers belonging
  to one control structure, or `}` from the terminating `while` of a `do` loop.
- Requires an actual following statement in the same list. It adds nothing
  before an outer closing brace, a new switch case, the end of the file, or
  inline HTML. It ignores trailing comment-only parser nodes. Redundant empty
  statements are preserved by this rule; the preset's `no_empty_statement`
  removes them before this rule runs.
- Does not independently format alternative syntax (`endif`, `endwhile`, etc.),
  ordinary unbraced statements, `declare`, declarations, closures, anonymous
  classes or `match` expressions. The preset's native rules can first convert
  alternative syntax and add braces; the resulting structures then qualify.

The fixer parses statement boundaries using the package's existing PHP parser
and edits whitespace tokens only. Literal contents, significant tokens and
comments remain unchanged. Same-line trailing comments stay attached to the
completed block; standalone comments stay with the following statement:

```php
if ($done) {
    finish();
} // Finished this block.

// Prepare the next operation.
$handler->next();
```

Existing line indentation is preserved; newly introduced line breaks use the
PHP-CS-Fixer configuration's line ending. Multiple blank lines at the selected
boundary become one. Repeated formatting is idempotent.

## Preset interaction and overrides

The rule runs after `blank_line_before_statement`, `no_extra_blank_lines` and
the package's wrapping fixers. It supplies the missing separation after a
control structure; the native before-statement rule still handles its other
boundaries. No wrapping thresholds or native rule options change.

To disable it in a consumer:

```php
return Noldors\PhpCsFixer\Config::create(__DIR__)
    ->setRules([
        ...Noldors\PhpCsFixer\Config::rules(),
        'Noldors/blank_line_after_control_structure' => false,
    ]);
```
