# Contributing to email-parse

Thank you for your interest in contributing!

## Development setup

```bash
git clone git@github.com:mmucklo/email-parse.git
cd email-parse
composer install
composer ci     # cs:check + PHPStan level 8 + PHPUnit
```

## Running tests

```bash
composer test             # PHPUnit (fast — unit + YAML-driven test spec)
composer test:coverage    # HTML coverage → coverage/
composer infect           # Infection mutation testing (takes ~2–5 min)
composer bench            # PhpBench performance benchmarks
composer stan             # PHPStan level 8
composer cs:check         # PHP CS Fixer (dry-run)
composer cs:fix           # PHP CS Fixer (auto-fix)
composer ci               # Full CI: cs:check → stan → test
```

## Adding test cases

Most parser tests live in `tests/testspec.yml`. Each entry specifies an input, options, and the expected output. Add new entries at the end of the file to cover new behavior or regressions. PHPUnit picks them up automatically.

For typed-API or property-based tests, add methods to `tests/ParseTest.php` or `tests/PropertyTest.php`.

## Code style

The project uses PHP CS Fixer with the committed `.php-cs-fixer.dist.php` config. Run `composer cs:fix` before pushing.

## Static analysis

PHPStan runs at level 8. If your change introduces a new type issue, either fix it or — if it's a tool limitation (e.g. a generic not expressible in PHP) — add it to `phpstan-baseline.neon` via `bin/phpstan analyse --generate-baseline`.

## Pull requests

- One logical change per PR.
- Include tests for new features and bug fixes.
- Keep `composer ci` green before requesting review.
- Commit messages: imperative mood, concise subject, body if the *why* isn't obvious from the diff.

## RFC compliance

When implementing validation rules, cite the specific RFC section in both code comments and the PR description. The project follows RFC 5321 (SMTP Mailbox), RFC 5322 (Internet Message Format), RFC 6531/6532 (EAI), and RFC 1035 (domain names). See [DESIGN.md](DESIGN.md) for the full reference.

## Reporting issues

Please include:
- PHP version
- `ParseOptions` configuration used (factory preset or custom)
- Input email string
- Expected vs actual output
- Whether the behavior matches the cited RFC or not
