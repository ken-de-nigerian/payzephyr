# Contributing

Thanks for considering contributing to PayZephyr — whether that's a bug fix, a new feature, a documentation improvement, or just a well-written issue report. This guide walks through the whole process, written for someone contributing to this project for the first time as much as for a seasoned maintainer.

## Code of Conduct

Be respectful, be patient with newcomers, and assume good faith. Report unacceptable behavior to ken.de.nigerian@payzephyr.dev.

## Ways to contribute, beyond code

- **Report a bug.** Search [existing issues](https://github.com/ken-de-nigerian/payzephyr/issues) first to avoid a duplicate. A good bug report includes: PHP and Laravel version, PayZephyr version, which provider (if relevant), the exact error message or unexpected behavior, and — ideally — a minimal reproduction.
- **Suggest a feature.** Explain the problem you're trying to solve, not just the solution you have in mind — that gives room to discuss whether there's a better approach.
- **Improve documentation.** Found something in these docs that's unclear, wrong, or missing? A PR fixing a single sentence is just as welcome as a PR adding a whole new page.

## Setting up your development environment

You don't need real payment provider credentials to work on PayZephyr itself — the test suite mocks every HTTP call (see [Testing](testing.md) for the same technique applied to your own app).

1. **Fork and clone**

   ```bash
   git clone https://github.com/YOUR-USERNAME/payzephyr.git
   cd payzephyr
   ```

2. **Install dependencies**

   ```bash
   composer install
   ```

3. **Create a branch**

   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/your-bug-fix
   ```

4. **Run the test suite** to confirm your setup works before you start changing anything

   ```bash
   composer test
   ```

## The development loop

```bash
# ...make your changes...

composer test       # run the test suite (Pest)
composer analyse     # static analysis (PHPStan)
composer format      # auto-fix code style (Laravel Pint)

# all three together
composer test && composer analyse && composer format
```

Run all three before opening a pull request — CI runs the same checks, and catching a formatting or static-analysis issue locally is faster than waiting for CI to tell you.

## Coding standards

PayZephyr follows PSR-12 with a few additions, enforced by Pint rather than left to memory — but the underlying principles worth internalizing:

- **`declare(strict_types=1);`** at the top of every file.
- **Type hints everywhere** — parameters and return types, not just where PHP requires them.
- **`final` by default.** Core classes are marked `final` unless there's a specific reason for them to be extended — this keeps the public API surface intentional rather than accidentally-extensible.
- **`readonly` DTOs.** Data transfer objects (everything in `src/DataObjects/`) should be immutable.
- **Comments only where they add real value.** Don't restate what the code already says — explain *why*, when the why isn't obvious from reading the code itself. See the project's own source for the tone to aim for.
- **One clear responsibility per class.**

```php
<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Drivers;

use KenDeNigerian\PayZephyr\Contracts\DriverInterface;

final class ExampleDriver extends AbstractDriver implements DriverInterface
{
    // ...
}
```

## Writing tests

Every behavior change needs a test — see [Testing](testing.md) for the mocked-HTTP-client pattern used throughout this codebase (the same technique you'd use testing your own app against PayZephyr applies here too, just from the other side). A few conventions specific to contributing to the package itself:

- Test files live in `tests/Unit/`, `tests/Feature/`, or `tests/Integration/`, mirroring the kind of thing under test.
- Name tests descriptively enough that a failure message alone tells you what broke, without needing to open the file — `'webhook validation fails with incorrect paystack signature'`, not `'test webhook 3'`.
- If you're fixing a bug, write the test that demonstrates the bug *first*, confirm it fails, then fix the code and confirm it passes. This is the single best guarantee the bug won't silently come back later.

## Adding support for a new payment provider

This is a substantial contribution — read [Custom Drivers](custom-drivers.md) first for the mechanics of what a driver needs to implement (that chapter is written for someone adding a provider to *their own app*, but the contract is identical for contributing one upstream into PayZephyr itself). Beyond what that chapter covers, contributing a driver into the package itself additionally means:

1. Adding the provider's config block to `config/payments.php`.
2. Writing a full test suite for the driver — charge, verify, webhook validation, and (if the provider supports it) subscriptions — following the pattern of an existing driver's tests as a template.
3. Adding the provider to the tables in [Multiple Providers](providers.md) and [Configuration](configuration.md#provider-credentials).
4. If the provider's webhook payload shape needs it, overriding the relevant `extractWebhook*` methods rather than relying on `AbstractDriver`'s defaults.

## Commit messages and PR titles

Use [conventional commits](https://www.conventionalcommits.org/) style:

```
feat: Add support for Square payment provider
fix: Correct webhook signature validation for Stripe
docs: Clarify subscription cancellation behavior per provider
test: Add tests for Flutterwave driver edge cases
refactor: Simplify PaymentManager fallback logic
chore: Update dependencies
```

## Before opening a pull request

- [ ] `composer test` passes
- [ ] `composer analyse` passes (no new PHPStan errors)
- [ ] `composer format` has been run (or your code already matches Pint's formatting)
- [ ] Documentation is updated if behavior changed — including [CHANGELOG.md](CHANGELOG.md), and specifically calling out anything **breaking**
- [ ] Your branch is rebased on (or merged with) the latest `main`

A PR description that explains *what changed and why* — not just *what* — makes review faster. If your PR fixes an open issue, reference it (`Fixes #123`).

## What happens after you submit

1. **CI runs automatically** — the same `test`/`analyse`/`format` checks, across PayZephyr's supported PHP and Laravel version matrix.
2. **A maintainer reviews the code** and may ask for changes — this is normal, not a rejection.
3. **Once approved, it gets merged**, and the change ships in the next release, noted in [CHANGELOG.md](CHANGELOG.md).

## Questions?

Open a [discussion or issue](https://github.com/ken-de-nigerian/payzephyr/issues) — there's no such thing as a question too basic to ask, and a confusing part of this guide is itself worth reporting as a documentation issue.
