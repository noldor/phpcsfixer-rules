# PHP-CS-Fixer extension development

Before adding or changing a fixer, read PHP-CS-Fixer's official
[custom rules documentation](https://cs.symfony.com/doc/custom_rules.html) and
the installed interfaces used by that fixer. Implement consumer rules through
the supported custom-fixer registration API.

Keep fixers framework-independent. Register public fixers through `Config::create()`
in `src/Config.php`, with recommended rules in
`src/rules.php`. Keep token helpers under `src/Internal/`.
Preserve significant tokens and literal contents; regression tests must verify output, token
preservation and idempotence. Respect configured indentation and line endings.

Use PHP 8.5 with `iconv`, `mbstring`, `dom`, `xml`, `xmlwriter` and `zip` enabled
for development checks. Run `composer check` and complete independent review
before publication. Follow [.github/RELEASING.md](.github/RELEASING.md) when
changing release infrastructure, preparing a release or publishing a new rule.

Keep README.md focused on installation and rule-set connection. Document each
fixer's behavior and options under `docs/`.
