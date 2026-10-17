# Contributing

Thanks for helping make AI safer and cheaper to use from plain PHP.

## Ground rules

- **PHP 8.0 compatibility is a hard requirement.** No enums, `readonly`,
  `never`, `new` in initializers, first-class callable syntax, pure
  intersection types or other 8.1+ features. CI runs PHP 8.0 to 8.4.
- **No new runtime dependencies.** Only `ext-curl` and `ext-json` are
  required. PSR interfaces may be used only in optional adapters.
- **Never weaken a guard silently.** If something cannot be enforced (unknown
  price, missing storage) it must fail loudly, not degrade to "allow".
- **Provider changes must cite the official docs.** Link the documentation
  page in the pull request and add or update a recorded fixture in
  `tests/Fixtures/` that matches the documented response shape.
- **No secrets or prompts in logs, exceptions or fixtures.**

## Development

```bash
git clone https://github.com/your-vendor/php-ai-guard.git
cd php-ai-guard
composer install

composer test       # PHPUnit: no network or API keys needed
composer analyse    # PHPStan level 8
composer cs         # PHP-CS-Fixer (PSR-12), dry run
composer cs-fix     # apply fixes
```

MySQL/MariaDB storage tests run when these are set:

```bash
export AI_GUARD_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=ai_guard"
export AI_GUARD_TEST_MYSQL_USER=root AI_GUARD_TEST_MYSQL_PASSWORD=secret
```

To test against a specific PHP version without installing it:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.0-cli vendor/bin/phpunit
```

## Pull requests

1. Open an issue first for larger changes so we can agree on the approach.
2. Keep pull requests focused, with tests for every behaviour change.
3. Make sure `composer check` passes.
4. Add a line to the `Unreleased` section of `CHANGELOG.md`.

## Adding a provider

Implement `PhpAiGuard\Provider\ProviderInterface`, usually by extending
`AbstractHttpProvider`, which maps HTTP errors onto the retryable /
non-retryable exception hierarchy. If the service is OpenAI-compatible,
prefer adding a preset to `ProviderPresets` instead of a new class.
